<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Jonaspauleta\LaravelAiMoonshot\Events\TextGenerationStepCompleted;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * @param  array<int, array<string, mixed>>  $chunks
 */
function sseLoopChunks(array $chunks): string
{
    $lines = [];

    foreach ($chunks as $chunk) {
        $lines[] = 'data: '.json_encode($chunk);
        $lines[] = '';
    }

    $lines[] = 'data: [DONE]';
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * A trivial tool the streaming tool-loop can resolve by name and execute.
 */
final class GetWeatherTool implements Tool
{
    public function name(): string
    {
        return 'get_weather';
    }

    public function description(): string
    {
        return 'Get the weather for a city.';
    }

    public function handle(Request $request): string
    {
        return 'Sunny, 24C.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['city' => $schema->string()->description('City name')];
    }
}

it('meters the summed token usage across every step of a streaming tool-loop, not just the final segment', function (): void {
    // Step 0: model asks to call get_weather and bills 100+20 tokens.
    $step0 = sseLoopChunks([
        ['id' => 's0', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant']]]],
        ['id' => 's0', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
            'index' => 0,
            'id' => 'call_1',
            'type' => 'function',
            'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Lisbon"}'],
        ]]]]]],
        ['id' => 's0', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]],
    ]);

    // Step 1: model answers and bills another 50+10 tokens.
    $step1 = sseLoopChunks([
        ['id' => 's1', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant']]]],
        ['id' => 's1', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['content' => 'It is sunny.'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10]],
    ]);

    Http::fake([
        'api.moonshot.ai/v1/chat/completions' => Http::sequence()
            ->push($step0, 200, ['Content-Type' => 'text/event-stream'])
            ->push($step1, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    Event::fake([TextGenerationStepCompleted::class]);

    $provider = resolve(AiManager::class)->textProvider('moonshot');

    $generator = $provider->textGenerationLoop()->stream(
        'inv-loop',
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Weather in Lisbon?')],
        tools: [new GetWeatherTool],
    );

    /** @var array<int, StreamEvent> $events */
    $events = iterator_to_array($generator, false);

    /** @var array<int, StreamEnd> $streamEnds */
    $streamEnds = array_values(array_filter(
        $events,
        static fn (StreamEvent $event): bool => $event instanceof StreamEnd,
    ));

    expect($streamEnds)->toHaveCount(1);

    $combined = $streamEnds[0]->usage;

    expect($combined->promptTokens)->toBe(150);
    expect($combined->completionTokens)->toBe(30);

    $stepUsages = [];

    Event::assertDispatched(TextGenerationStepCompleted::class, function (TextGenerationStepCompleted $event) use (&$stepUsages): bool {
        $stepUsages[] = [$event->response->usage->promptTokens, $event->response->usage->completionTokens];

        return true;
    });

    expect($stepUsages)->toBe([[100, 20], [50, 10]]);
});

it('keeps completed-step usage observable when consumption stops during the next step', function (): void {
    $step0 = sseLoopChunks([
        ['id' => 's0', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant']]]],
        ['id' => 's0', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
            'index' => 0,
            'id' => 'call_1',
            'type' => 'function',
            'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Lisbon"}'],
        ]]]]]],
        ['id' => 's0', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]],
    ]);

    $step1 = sseLoopChunks([
        ['id' => 's1', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant']]]],
        ['id' => 's1', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['content' => 'It is sunny.'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10]],
    ]);

    Http::fake([
        'api.moonshot.ai/v1/chat/completions' => Http::sequence()
            ->push($step0, 200, ['Content-Type' => 'text/event-stream'])
            ->push($step1, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    Event::fake([TextGenerationStepCompleted::class]);

    $provider = resolve(AiManager::class)->textProvider('moonshot');
    $generator = $provider->textGenerationLoop()->stream(
        'inv-cancelled-loop',
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Weather in Lisbon?')],
        tools: [new GetWeatherTool],
    );

    $events = [];
    $streamStarts = 0;

    foreach ($generator as $event) {
        $events[] = $event;

        if ($event instanceof StreamStart && ++$streamStarts === 2) {
            break;
        }
    }

    expect(array_filter($events, static fn (mixed $event): bool => $event instanceof StreamEnd))->toBeEmpty();

    Event::assertDispatchedTimes(TextGenerationStepCompleted::class, 1);
    Event::assertDispatched(
        TextGenerationStepCompleted::class,
        static fn (TextGenerationStepCompleted $event): bool => $event->context->stepNumber === 0
            && $event->response->usage->promptTokens === 100
            && $event->response->usage->completionTokens === 20,
    );
});
