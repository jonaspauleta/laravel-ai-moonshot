<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jonaspauleta\LaravelAiMoonshot\MoonshotGateway;
use Laravel\Ai\AiManager;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function webSearchGateway(): MoonshotGateway
{
    return new MoonshotGateway(resolve(Dispatcher::class));
}

/**
 * @param  array<int, array<string, mixed>>  $chunks
 */
function sseFromWebSearchChunks(array $chunks): string
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
 * Invoke a protected method on the gateway via Closure rebinding.
 */
function callWebSearchProtected(MoonshotGateway $gateway, string $method, mixed ...$args): mixed
{
    /** @var Closure(mixed ...): mixed $closure */
    $closure = Closure::bind(
        fn (mixed ...$callArgs): mixed => $this->{$method}(...$callArgs),
        $gateway,
        MoonshotGateway::class,
    );

    return $closure(...$args);
}

it('maps WebSearch ProviderTool to Moonshot $web_search builtin_function', function (): void {
    $gateway = webSearchGateway();

    /** @var Provider $provider */
    $provider = resolve(AiManager::class)->textProvider('moonshot');

    /** @var array<int, array<string, mixed>> $mapped */
    $mapped = callWebSearchProtected($gateway, 'mapTools', [new WebSearch], $provider);

    expect($mapped)->toBe([
        [
            'type' => 'builtin_function',
            'function' => [
                'name' => '$web_search',
            ],
        ],
    ]);
});

it('echoes $web_search arguments as ToolResult content in non-streaming path', function (): void {
    $gateway = webSearchGateway();

    /** @var Provider $provider */
    $provider = resolve(AiManager::class)->textProvider('moonshot');

    $toolCall = new ToolCall(
        id: 'call_1',
        name: '$web_search',
        arguments: ['query' => 'Spa Francorchamps weather'],
        resultId: 'call_1',
    );

    /** @var array<int, ToolResult> $results */
    $results = callWebSearchProtected($gateway, 'executeToolCalls', [$toolCall], [], $provider);

    expect($results)->toHaveCount(1);
    expect($results[0]->name)->toBe('$web_search');
    expect($results[0]->result)->toBe('{"query":"Spa Francorchamps weather"}');
    expect($results[0]->id)->toBe('call_1');
});

it('drives a $web_search tool_call round-trip end-to-end (non-streaming)', function (): void {
    $firstResponse = [
        'id' => 'resp-1',
        'model' => 'kimi-k2.6',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_abc',
                    'type' => 'function',
                    'function' => [
                        'name' => '$web_search',
                        'arguments' => '{"query":"Nurburgring lap record"}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ];

    $secondResponse = [
        'id' => 'resp-2',
        'model' => 'kimi-k2.6',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => 'Per recent results, the lap record is...',
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 12],
    ];

    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')
        ->push($firstResponse, 200)
        ->push($secondResponse, 200);

    $provider = resolve(AiManager::class)->textProvider('moonshot');

    // why: drive the $web_search round-trip through public contract without
    // tripping the SDK's narrow Tool[] PHPDoc on tools — the response handler
    // recognizes $web_search by name regardless of whether tools were declared.
    $response = $provider->textGateway()->generateText(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Find the lap record.')],
        options: new TextGenerationOptions(maxSteps: 3),
    );

    expect($response->text)->toBe('Per recent results, the lap record is...');

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/v1/chat/completions')) {
            return false;
        }

        /** @var array<string, mixed> $body */
        $body = $request->data();

        $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
        $toolMessage = end($messages);

        if (! is_array($toolMessage)) {
            return false;
        }

        return ($toolMessage['role'] ?? null) === 'tool'
            && ($toolMessage['tool_call_id'] ?? null) === 'call_abc'
            && ($toolMessage['content'] ?? null) === '{"query":"Nurburgring lap record"}';
    });
});

it('echoes $web_search arguments as ToolResultEvent during streaming', function (): void {
    $firstSse = sseFromWebSearchChunks([
        ['id' => 'x', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant']]]],
        ['id' => 'x', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => [
            'tool_calls' => [[
                'index' => 0,
                'id' => 'call_stream',
                'function' => ['name' => '$web_search', 'arguments' => '{"query":"latest F1 news"}'],
            ]],
        ]]]],
        ['id' => 'x', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]],
    ]);

    $secondSse = sseFromWebSearchChunks([
        ['id' => 'y', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['content' => 'Done']]]],
        ['id' => 'y', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1]],
    ]);

    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')
        ->push($firstSse, 200, ['Content-Type' => 'text/event-stream'])
        ->push($secondSse, 200, ['Content-Type' => 'text/event-stream']);

    $provider = resolve(AiManager::class)->textProvider('moonshot');

    $generator = $provider->textGateway()->streamText(
        'inv-ws',
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Catch me up on F1.')],
    );

    /** @var array<int, StreamEvent> $events */
    $events = iterator_to_array($generator, false);

    /** @var array<int, ToolResultEvent> $resultEvents */
    $resultEvents = array_values(array_filter(
        $events,
        static fn (StreamEvent $e): bool => $e instanceof ToolResultEvent,
    ));

    expect($resultEvents)->toHaveCount(1);
    expect($resultEvents[0]->toolResult->name)->toBe('$web_search');
    expect($resultEvents[0]->toolResult->result)->toBe('{"query":"latest F1 news"}');
});
