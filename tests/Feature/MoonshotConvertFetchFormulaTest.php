<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jonaspauleta\LaravelAiMoonshot\MoonshotGateway;
use Jonaspauleta\LaravelAiMoonshot\Providers\Tools\Convert;
use Jonaspauleta\LaravelAiMoonshot\Providers\Tools\Fetch;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function formulaGateway(): MoonshotGateway
{
    return new MoonshotGateway(resolve(Dispatcher::class));
}

/**
 * Returns the Moonshot provider instance as a concrete Provider (for trait method calls).
 */
function formulaProvider(): Provider
{
    /** @var Provider */
    return resolve(AiManager::class)->textProvider('moonshot');
}

/**
 * Returns the Moonshot provider as TextProvider (for public gateway API calls).
 */
function formulaTextProvider(): TextProvider
{
    return resolve(AiManager::class)->textProvider('moonshot');
}

/**
 * Invoke a protected method on the gateway via Closure rebinding.
 */
function callFormulaProtected(MoonshotGateway $gateway, string $method, mixed ...$args): mixed
{
    /** @var Closure(mixed ...): mixed $closure */
    $closure = Closure::bind(
        fn (mixed ...$callArgs): mixed => $this->{$method}(...$callArgs),
        $gateway,
        MoonshotGateway::class,
    );

    return $closure(...$args);
}

/**
 * @param  array<int, array<string, mixed>>  $chunks
 */
function sseFromFormulaChunks(array $chunks): string
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

// --- Unit: mapTools ---

it('maps Convert to the function tool definitions fetched from the Formulas API', function (): void {
    $formulaToolsResponse = [
        'object' => 'list',
        'tools' => [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'convert',
                    'description' => 'Convert units or currencies.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'from_value' => ['type' => 'number'],
                            'from_unit' => ['type' => 'string'],
                            'to_unit' => ['type' => 'string'],
                        ],
                        'required' => ['from_value', 'from_unit', 'to_unit'],
                    ],
                ],
            ],
        ],
    ];

    Http::fake([
        'api.moonshot.ai/v1/formulas/moonshot/convert:latest/tools' => Http::response($formulaToolsResponse, 200),
    ]);

    $gateway = formulaGateway();
    $provider = formulaProvider();

    /** @var array<int, array{type: string, function: array{name: string}}> $mapped */
    $mapped = callFormulaProtected($gateway, 'mapTools', [new Convert], $provider);

    expect($mapped)->toHaveCount(1);
    expect($mapped[0]['type'])->toBe('function');
    expect($mapped[0]['function']['name'])->toBe('convert');
});

it('maps Fetch to the function tool definitions fetched from the Formulas API', function (): void {
    $formulaToolsResponse = [
        'object' => 'list',
        'tools' => [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'fetch',
                    'description' => 'Fetch a URL and return its content as Markdown.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'url' => ['type' => 'string'],
                        ],
                        'required' => ['url'],
                    ],
                ],
            ],
        ],
    ];

    Http::fake([
        'api.moonshot.ai/v1/formulas/moonshot/fetch:latest/tools' => Http::response($formulaToolsResponse, 200),
    ]);

    $gateway = formulaGateway();
    $provider = formulaProvider();

    /** @var array<int, array{type: string, function: array{name: string}}> $mapped */
    $mapped = callFormulaProtected($gateway, 'mapTools', [new Fetch], $provider);

    expect($mapped)->toHaveCount(1);
    expect($mapped[0]['type'])->toBe('function');
    expect($mapped[0]['function']['name'])->toBe('fetch');
});

it('caches formula tool definitions within a single request lifecycle', function (): void {
    $formulaToolsResponse = [
        'object' => 'list',
        'tools' => [
            [
                'type' => 'function',
                'function' => ['name' => 'convert', 'description' => 'Convert.', 'parameters' => ['type' => 'object', 'properties' => [], 'required' => []]],
            ],
        ],
    ];

    Http::fake([
        'api.moonshot.ai/v1/formulas/moonshot/convert:latest/tools' => Http::response($formulaToolsResponse, 200),
    ]);

    $gateway = formulaGateway();
    $provider = formulaProvider();

    callFormulaProtected($gateway, 'mapTools', [new Convert], $provider);
    callFormulaProtected($gateway, 'mapTools', [new Convert], $provider);

    Http::assertSentCount(1);
});

// --- Unit: executeToolCalls ---

it('executes a formula tool call via the fibers endpoint and returns the output', function (): void {
    $formulaToolsResponse = [
        'object' => 'list',
        'tools' => [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'convert',
                    'description' => 'Convert units.',
                    'parameters' => ['type' => 'object', 'properties' => [], 'required' => []],
                ],
            ],
        ],
    ];

    $fibersResponse = [
        'status' => 'succeeded',
        'context' => [
            'output' => '15.0 psi = 1.034 bar',
        ],
    ];

    Http::fake([
        'api.moonshot.ai/v1/formulas/moonshot/convert:latest/tools' => Http::response($formulaToolsResponse, 200),
        'api.moonshot.ai/v1/formulas/moonshot/convert:latest/fibers' => Http::response($fibersResponse, 200),
    ]);

    $gateway = formulaGateway();
    $provider = formulaProvider();

    // populate the registry first
    callFormulaProtected($gateway, 'mapTools', [new Convert], $provider);

    $toolCall = new ToolCall(
        id: 'call_abc',
        name: 'convert',
        arguments: ['from_value' => 15, 'from_unit' => 'psi', 'to_unit' => 'bar'],
        resultId: 'call_abc',
    );

    /** @var array<int, ToolResult> $results */
    $results = callFormulaProtected($gateway, 'executeToolCalls', [$toolCall], [], $provider);

    expect($results)->toHaveCount(1);
    expect($results[0]->name)->toBe('convert');
    expect($results[0]->result)->toBe('15.0 psi = 1.034 bar');
    expect($results[0]->id)->toBe('call_abc');

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/formulas/moonshot/convert:latest/fibers')) {
            return false;
        }

        /** @var array<string, mixed> $body */
        $body = $request->data();
        $arguments = is_string($body['arguments'] ?? null) ? $body['arguments'] : '';

        return ($body['name'] ?? null) === 'convert'
            && str_contains($arguments, 'from_unit');
    });
});

// --- End-to-end: non-streaming ---

it('drives a Convert formula tool_call round-trip end-to-end (non-streaming)', function (): void {
    $formulaToolsResponse = [
        'object' => 'list',
        'tools' => [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'convert',
                    'description' => 'Convert units.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'from_value' => ['type' => 'number'],
                            'from_unit' => ['type' => 'string'],
                            'to_unit' => ['type' => 'string'],
                        ],
                        'required' => ['from_value', 'from_unit', 'to_unit'],
                    ],
                ],
            ],
        ],
    ];

    $firstResponse = [
        'id' => 'resp-1',
        'model' => 'kimi-k2.6',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_conv',
                    'type' => 'function',
                    'function' => [
                        'name' => 'convert',
                        'arguments' => '{"from_value":15,"from_unit":"psi","to_unit":"bar"}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 8],
    ];

    $fibersResponse = [
        'status' => 'succeeded',
        'context' => [
            'output' => '15.0 psi = 1.034 bar',
        ],
    ];

    $secondResponse = [
        'id' => 'resp-2',
        'model' => 'kimi-k2.6',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => '15 psi is approximately 1.034 bar.',
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 14],
    ];

    Http::fake([
        'api.moonshot.ai/v1/formulas/moonshot/convert:latest/tools' => Http::response($formulaToolsResponse, 200),
        'api.moonshot.ai/v1/formulas/moonshot/convert:latest/fibers' => Http::response($fibersResponse, 200),
        'api.moonshot.ai/v1/chat/completions' => Http::sequence()
            ->push($firstResponse, 200)
            ->push($secondResponse, 200),
    ]);

    $gateway = formulaGateway();
    $textProvider = formulaTextProvider();

    $response = $gateway->generateText(
        $textProvider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Convert 15 psi to bar.')],
        tools: [new Convert],
        options: new TextGenerationOptions(maxSteps: 3),
    );

    expect($response->text)->toBe('15 psi is approximately 1.034 bar.');

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
            && ($toolMessage['tool_call_id'] ?? null) === 'call_conv'
            && ($toolMessage['content'] ?? null) === '15.0 psi = 1.034 bar';
    });
});

// --- End-to-end: streaming ---

it('drives a Convert formula tool_call round-trip during streaming', function (): void {
    $formulaToolsResponse = [
        'object' => 'list',
        'tools' => [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'convert',
                    'description' => 'Convert units.',
                    'parameters' => ['type' => 'object', 'properties' => [], 'required' => []],
                ],
            ],
        ],
    ];

    $fibersResponse = [
        'status' => 'succeeded',
        'context' => [
            'output' => '15.0 psi = 1.034 bar',
        ],
    ];

    $firstSse = sseFromFormulaChunks([
        ['id' => 'x', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant']]]],
        ['id' => 'x', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => [
            'tool_calls' => [[
                'index' => 0,
                'id' => 'call_stream_conv',
                'function' => ['name' => 'convert', 'arguments' => '{"from_value":15,"from_unit":"psi","to_unit":"bar"}'],
            ]],
        ]]]],
        ['id' => 'x', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]],
    ]);

    $secondSse = sseFromFormulaChunks([
        ['id' => 'y', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['content' => '15 psi is approximately 1.034 bar.']]]],
        ['id' => 'y', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 8]],
    ]);

    Http::fake([
        'api.moonshot.ai/v1/formulas/moonshot/convert:latest/tools' => Http::response($formulaToolsResponse, 200),
        'api.moonshot.ai/v1/formulas/moonshot/convert:latest/fibers' => Http::response($fibersResponse, 200),
        'api.moonshot.ai/v1/chat/completions' => Http::sequence()
            ->push($firstSse, 200, ['Content-Type' => 'text/event-stream'])
            ->push($secondSse, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $gateway = formulaGateway();
    $textProvider = formulaTextProvider();

    $generator = $gateway->streamText(
        'inv-conv',
        $textProvider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Convert 15 psi to bar.')],
        tools: [new Convert],
    );

    /** @var array<int, StreamEvent> $events */
    $events = iterator_to_array($generator, false);

    /** @var array<int, ToolResultEvent> $resultEvents */
    $resultEvents = array_values(array_filter(
        $events,
        static fn (StreamEvent $e): bool => $e instanceof ToolResultEvent,
    ));

    expect($resultEvents)->toHaveCount(1);
    expect($resultEvents[0]->toolResult->name)->toBe('convert');
    expect($resultEvents[0]->toolResult->result)->toBe('15.0 psi = 1.034 bar');
});
