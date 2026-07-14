<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Request;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Tools\Request as ToolRequest;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * Test tool whose `class_basename` matches the tool_calls.name in the fake response.
 */
final class WhoAmI implements Tool
{
    public function description(): string
    {
        return 'Reveal who the user is.';
    }

    public function handle(ToolRequest $request): string
    {
        return 'Ada';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

/**
 * @param  array<string, mixed>  $structured
 * @return array<string, mixed>
 */
function moonshotFinalChatResponse(array $structured): array
{
    return [
        'id' => 'resp-final',
        'model' => 'kimi-k2.6',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => json_encode($structured)],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
    ];
}

/**
 * @return array<string, mixed>
 */
function responseFormatFromBody(Request $request): array
{
    /** @var array<string, mixed> $body */
    $body = $request->data();
    $rf = $body['response_format'] ?? null;

    if (! is_array($rf)) {
        return [];
    }

    /** @var array<string, mixed> $rf */
    return $rf;
}

function recordedRequestAt(int $index): Request
{
    $pair = Http::recorded()[$index] ?? null;

    assert(is_array($pair));

    return $pair[0];
}

it('sends response_format with type json_schema and strict:true when a schema is provided', function (): void {
    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')
        ->push(moonshotFinalChatResponse(['name' => 'Ada']), 200);

    $provider = resolve(AiManager::class)->textProvider('moonshot');
    $factory = new JsonSchemaTypeFactory;

    $provider->textGenerationLoop()->generate(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Pick a name.')],
        schema: ['name' => $factory->string()],
    );

    Http::assertSent(function (Request $request): bool {
        $rf = responseFormatFromBody($request);

        expect($rf)->toHaveKey('type', 'json_schema');
        expect($rf)->toHaveKey('json_schema');

        /** @var array<string, mixed> $js */
        $js = is_array($rf['json_schema'] ?? null) ? $rf['json_schema'] : [];

        expect($js)->toHaveKey('name', 'schema_definition');
        expect($js)->toHaveKey('strict', true);
        expect($js)->toHaveKey('schema');

        return true;
    });
});

it('recursively disables additionalProperties on nested object schemas', function (): void {
    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')
        ->push(moonshotFinalChatResponse(['name' => 'Ada']), 200);

    $provider = resolve(AiManager::class)->textProvider('moonshot');
    $factory = new JsonSchemaTypeFactory;

    $nestedSchema = [
        'user' => $factory->object([
            'name' => $factory->string(),
            'age' => $factory->integer(),
        ]),
    ];

    $provider->textGenerationLoop()->generate(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Pick a user.')],
        schema: $nestedSchema,
    );

    Http::assertSent(function (Request $request): bool {
        $rf = responseFormatFromBody($request);
        /** @var array<string, mixed> $js */
        $js = is_array($rf['json_schema'] ?? null) ? $rf['json_schema'] : [];
        /** @var array<string, mixed> $schema */
        $schema = is_array($js['schema'] ?? null) ? $js['schema'] : [];

        expect($schema)->toHaveKey('type', 'object');
        expect($schema)->toHaveKey('additionalProperties', false);

        /** @var array<string, mixed> $props */
        $props = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        /** @var array<string, mixed> $userProp */
        $userProp = is_array($props['user'] ?? null) ? $props['user'] : [];

        expect($userProp)->toHaveKey('type', 'object');
        expect($userProp)->toHaveKey('additionalProperties', false);

        return true;
    });
});

it('decodes the response content into a StructuredTextResponse', function (): void {
    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')
        ->push(moonshotFinalChatResponse(['name' => 'Ada', 'age' => 36]), 200);

    $provider = resolve(AiManager::class)->textProvider('moonshot');
    $factory = new JsonSchemaTypeFactory;

    $response = $provider->textGenerationLoop()->generate(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Pick a person.')],
        schema: [
            'name' => $factory->string(),
            'age' => $factory->integer(),
        ],
    );

    expect($response)->toBeInstanceOf(StructuredTextResponse::class);
    assert($response instanceof StructuredTextResponse);
    expect($response->structured)->toBe(['name' => 'Ada', 'age' => 36]);
});

it('falls back to empty structured data when the model returns invalid JSON', function (): void {
    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')->push([
        'id' => 'resp-final',
        'model' => 'kimi-k2.6',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'not actually json'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ], 200);

    $provider = resolve(AiManager::class)->textProvider('moonshot');
    $factory = new JsonSchemaTypeFactory;

    $response = $provider->textGenerationLoop()->generate(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Hi')],
        schema: ['name' => $factory->string()],
    );

    expect($response)->toBeInstanceOf(StructuredTextResponse::class);
    assert($response instanceof StructuredTextResponse);
    expect($response->structured)->toBe([]);
    expect($response->text)->toBe('not actually json');
});

/**
 * @return array<string, mixed>
 */
function moonshotToolCallStep(): array
{
    return [
        'id' => 'resp-tools',
        'model' => 'kimi-k2.6',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'WhoAmI',
                        'arguments' => '{}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2],
    ];
}

it('defers response_format while tools may run and enforces it on a final tool-free request', function (): void {
    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')
        ->push(moonshotToolCallStep(), 200)
        ->push([
            'id' => 'resp-answer',
            'model' => 'kimi-k2.6',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'The user is Ada.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 6, 'completion_tokens' => 4],
        ], 200)
        ->push(moonshotFinalChatResponse(['name' => 'Ada']), 200);

    $provider = resolve(AiManager::class)->textProvider('moonshot');
    $factory = new JsonSchemaTypeFactory;

    $response = $provider->textGenerationLoop()->generate(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Who am I?')],
        tools: [new WhoAmI],
        schema: ['name' => $factory->string()],
        options: new TextGenerationOptions(maxSteps: 3),
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(3);

    foreach ([0, 1] as $toolLoopStep) {
        $request = recordedRequestAt($toolLoopStep);
        /** @var array<string, mixed> $body */
        $body = $request->data();

        expect($body)->toHaveKey('tools');
        expect($body)->toHaveKey('tool_choice', 'auto');
        expect($body)->not->toHaveKey('response_format');
    }

    $finalize = recordedRequestAt(2);
    /** @var array<string, mixed> $finalizeBody */
    $finalizeBody = $finalize->data();

    expect($finalizeBody)->not->toHaveKey('tools');
    expect($finalizeBody)->not->toHaveKey('tool_choice');

    $rf = responseFormatFromBody($finalize);
    expect($rf)->toHaveKey('type', 'json_schema');
    /** @var array<string, mixed> $js */
    $js = is_array($rf['json_schema'] ?? null) ? $rf['json_schema'] : [];
    expect($js)->toHaveKey('strict', true);

    /** @var array<int, array<string, mixed>> $finalizeMessages */
    $finalizeMessages = is_array($finalizeBody['messages'] ?? null) ? $finalizeBody['messages'] : [];
    $lastMessage = $finalizeMessages[count($finalizeMessages) - 1];

    expect($lastMessage['role'] ?? null)->toBe('assistant');
    expect($lastMessage['content'] ?? null)->toBe('The user is Ada.');

    expect($response)->toBeInstanceOf(StructuredTextResponse::class);
    assert($response instanceof StructuredTextResponse);
    expect($response->structured)->toBe(['name' => 'Ada']);
    expect($response->usage->promptTokens)->toBe(15);
    expect($response->toolCalls)->toHaveCount(1);
});

it('sends response_format on every step when defer_structured_output is disabled', function (): void {
    config()->set('ai.providers.moonshot.defer_structured_output', false);

    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')
        ->push(moonshotToolCallStep(), 200)
        ->push(moonshotFinalChatResponse(['name' => 'Ada']), 200);

    $provider = resolve(AiManager::class)->textProvider('moonshot');
    $factory = new JsonSchemaTypeFactory;

    $provider->textGenerationLoop()->generate(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Who am I?')],
        tools: [new WhoAmI],
        schema: ['name' => $factory->string()],
        options: new TextGenerationOptions(maxSteps: 3),
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    foreach ($recorded as $pair) {
        /** @var Request $request */
        $request = $pair[0];
        $rf = responseFormatFromBody($request);
        expect($rf)->toHaveKey('type', 'json_schema');
        /** @var array<string, mixed> $js */
        $js = is_array($rf['json_schema'] ?? null) ? $rf['json_schema'] : [];
        expect($js)->toHaveKey('strict', true);
    }
});

it('strips unanswered tool calls from the structured finalize request when the step budget runs out', function (): void {
    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')
        ->push(moonshotToolCallStep(), 200)
        ->push(moonshotFinalChatResponse(['name' => 'Ada']), 200);

    $provider = resolve(AiManager::class)->textProvider('moonshot');
    $factory = new JsonSchemaTypeFactory;

    $response = $provider->textGenerationLoop()->generate(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Who am I?')],
        tools: [new WhoAmI],
        schema: ['name' => $factory->string()],
        options: new TextGenerationOptions(maxSteps: 1),
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $finalize = recordedRequestAt(1);
    /** @var array<string, mixed> $finalizeBody */
    $finalizeBody = $finalize->data();

    expect($finalizeBody)->not->toHaveKey('tools');
    expect(responseFormatFromBody($finalize))->toHaveKey('type', 'json_schema');

    /** @var array<int, array<string, mixed>> $finalizeMessages */
    $finalizeMessages = is_array($finalizeBody['messages'] ?? null) ? $finalizeBody['messages'] : [];

    expect($finalizeMessages)->not->toBeEmpty();

    foreach ($finalizeMessages as $message) {
        expect($message)->not->toHaveKey('tool_calls');
        expect($message['role'] ?? null)->not->toBe('tool');
    }

    expect($response)->toBeInstanceOf(StructuredTextResponse::class);
    assert($response instanceof StructuredTextResponse);
    expect($response->structured)->toBe(['name' => 'Ada']);
});

it('keeps response_format alongside thinking provider options', function (): void {
    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')
        ->push(moonshotFinalChatResponse(['name' => 'Ada']), 200);

    $provider = resolve(AiManager::class)->textProvider('moonshot');
    $factory = new JsonSchemaTypeFactory;

    $options = new class extends TextGenerationOptions
    {
        /** @return array<string, mixed> */
        #[Override]
        public function providerOptions(Lab|string $provider): array
        {
            return ['thinking' => ['type' => 'enabled']];
        }
    };

    $provider->textGenerationLoop()->generate(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Hi')],
        schema: ['name' => $factory->string()],
        options: $options,
    );

    Http::assertSent(function (Request $request): bool {
        /** @var array<string, mixed> $body */
        $body = $request->data();

        /** @var array<string, mixed>|null $rf */
        $rf = is_array($body['response_format'] ?? null) ? $body['response_format'] : null;
        /** @var array<string, mixed>|null $thinking */
        $thinking = is_array($body['thinking'] ?? null) ? $body['thinking'] : null;

        expect($rf)->not->toBeNull();
        expect($rf)->toHaveKey('type', 'json_schema');
        expect($thinking)->toBe(['type' => 'enabled']);

        return true;
    });
});

it('decodes fenced structured output using the Laravel AI decoder', function (): void {
    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')
        ->push([
            'id' => 'resp-final',
            'model' => 'kimi-k2.6',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => "```json\n{\"name\":\"Ada\"}\n```"],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ], 200);

    $provider = resolve(AiManager::class)->textProvider('moonshot');
    $factory = new JsonSchemaTypeFactory;

    $response = $provider->textGenerationLoop()->generate(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Pick a name.')],
        schema: ['name' => $factory->string()],
    );

    expect($response)->toBeInstanceOf(StructuredTextResponse::class);
    assert($response instanceof StructuredTextResponse);
    expect($response->structured)->toBe(['name' => 'Ada']);
});
