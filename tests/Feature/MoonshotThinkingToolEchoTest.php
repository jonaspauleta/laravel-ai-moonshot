<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Jonaspauleta\LaravelAiMoonshot\Messages\KimiAssistantMessage;
use Laravel\Ai\AiManager;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * TextGenerationOptions subclass that returns Kimi `thinking` provider options
 * without needing a full Agent stub.
 */
final class ThinkingOptions extends TextGenerationOptions
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function providerOptions(Lab|string $provider): array
    {
        return [
            'thinking' => [
                'type' => 'enabled',
                'keep' => 'all',
            ],
        ];
    }
}

/**
 * @param  array<int, array<string, mixed>>  $chunks
 */
function sseFromThinkingChunks(array $chunks): string
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

it('echoes reasoning_content on the assistant tool_call message in the non-streaming follow-up', function (): void {
    $firstResponse = [
        'id' => 'resp-1',
        'model' => 'kimi-k2.6',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'reasoning_content' => 'I should search for the answer.',
                'tool_calls' => [[
                    'id' => 'call_abc',
                    'type' => 'function',
                    'function' => [
                        'name' => '$web_search',
                        'arguments' => '{"query":"Spa weather"}',
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
            'message' => ['role' => 'assistant', 'content' => 'Sunny.'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 1],
    ];

    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')
        ->push($firstResponse, 200)
        ->push($secondResponse, 200);

    $provider = resolve(AiManager::class)->textProvider('moonshot');

    $provider->textGateway()->generateText(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Spa weather?')],
        options: new ThinkingOptions(maxSteps: 3),
    );

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/v1/chat/completions')) {
            return false;
        }

        /** @var array<string, mixed> $body */
        $body = $request->data();
        $messagesRaw = $body['messages'] ?? null;
        if (! is_array($messagesRaw)) {
            return false;
        }

        $assistantToolCallTurn = array_find($messagesRaw, fn ($msg): bool => is_array($msg) && ($msg['role'] ?? null) === 'assistant' && isset($msg['tool_calls']));

        if ($assistantToolCallTurn === null) {
            return false;
        }

        return ($assistantToolCallTurn['reasoning_content'] ?? null) === 'I should search for the answer.';
    });
});

it('echoes a fallback reasoning_content on the streaming tool follow-up when no reasoning deltas were streamed, without turning thinking off', function (): void {
    $firstSse = sseFromThinkingChunks([
        ['id' => 'x', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant']]]],
        ['id' => 'x', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => [
            'tool_calls' => [[
                'index' => 0,
                'id' => 'call_stream',
                'function' => ['name' => '$web_search', 'arguments' => '{"query":"x"}'],
            ]],
        ]]]],
        ['id' => 'x', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]],
    ]);

    $secondSse = sseFromThinkingChunks([
        ['id' => 'y', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['content' => 'Done']]]],
        ['id' => 'y', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1]],
    ]);

    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')
        ->push($firstSse, 200, ['Content-Type' => 'text/event-stream'])
        ->push($secondSse, 200, ['Content-Type' => 'text/event-stream']);

    $provider = resolve(AiManager::class)->textProvider('moonshot');

    $generator = $provider->textGateway()->streamText(
        'inv-no-reasoning-tool-follow-up',
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'x?')],
        options: new ThinkingOptions(maxSteps: 3),
    );

    iterator_to_array($generator, false);

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);
    if ($recorded[0] === null || $recorded[1] === null) {
        throw new RuntimeException('Expected two Http recordings');
    }

    $firstBody = $recorded[0][0]->data();
    $secondBody = $recorded[1][0]->data();
    expect(data_get($firstBody, 'thinking.type'))->toBe('enabled');
    expect(data_get($secondBody, 'thinking.type'))->toBe('enabled');

    $messagesRaw = $secondBody['messages'] ?? null;
    if (! is_array($messagesRaw)) {
        throw new RuntimeException('Expected chat messages array on second request');
    }

    $assistantToolCallTurn = array_find($messagesRaw, fn ($msg): bool => is_array($msg) && ($msg['role'] ?? null) === 'assistant' && isset($msg['tool_calls']));

    expect($assistantToolCallTurn)->toBeArray()
        ->and($assistantToolCallTurn['reasoning_content'] ?? null)->toBe("\u{200B}");
});

it('fills a non-empty fallback for historical KimiAssistantMessage tool_calls when reasoning is empty', function (): void {
    $response = [
        'id' => 'resp',
        'model' => 'kimi-k2.6',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'ok'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ];

    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')->push($response, 200);

    $provider = resolve(AiManager::class)->textProvider('moonshot');

    $historicalAssistant = new KimiAssistantMessage(
        content: '',
        reasoningContent: '',
        toolCalls: new Collection([
            new ToolCall(
                id: 'call_x',
                name: '$web_search',
                arguments: ['query' => 'test'],
                resultId: 'call_x',
            ),
        ]),
    );

    $provider->textGateway()->generateText(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [
            new UserMessage('First turn'),
            $historicalAssistant,
            new ToolResultMessage(new Collection([
                new ToolResult(
                    id: 'call_x',
                    name: '$web_search',
                    arguments: ['query' => 'test'],
                    result: '{"r":1}',
                    resultId: 'call_x',
                ),
            ])),
            new Message('user', 'Follow up'),
        ],
        options: new ThinkingOptions(maxSteps: 3),
    );

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/v1/chat/completions')) {
            return false;
        }

        /** @var array<string, mixed> $body */
        $body = $request->data();
        $messagesRaw = $body['messages'] ?? null;
        if (! is_array($messagesRaw)) {
            return false;
        }

        $row = array_find($messagesRaw, fn ($m): bool => is_array($m) && ($m['role'] ?? null) === 'assistant' && isset($m['tool_calls']));

        return is_array($row) && ($row['reasoning_content'] ?? null) === "\u{200B}";
    });
});

it('echoes reasoning_content on the assistant tool_call message in the streaming follow-up', function (): void {
    $firstSse = sseFromThinkingChunks([
        ['id' => 'x', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant']]]],
        ['id' => 'x', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['reasoning_content' => 'Searching the web…']]]],
        ['id' => 'x', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => [
            'tool_calls' => [[
                'index' => 0,
                'id' => 'call_stream',
                'function' => ['name' => '$web_search', 'arguments' => '{"query":"latest F1 news"}'],
            ]],
        ]]]],
        ['id' => 'x', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]],
    ]);

    $secondSse = sseFromThinkingChunks([
        ['id' => 'y', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => ['content' => 'Done']]]],
        ['id' => 'y', 'model' => 'kimi-k2.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1]],
    ]);

    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')
        ->push($firstSse, 200, ['Content-Type' => 'text/event-stream'])
        ->push($secondSse, 200, ['Content-Type' => 'text/event-stream']);

    $provider = resolve(AiManager::class)->textProvider('moonshot');

    $generator = $provider->textGateway()->streamText(
        'inv-thinking',
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'Catch me up.')],
        options: new ThinkingOptions(maxSteps: 3),
    );

    iterator_to_array($generator, false);

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/v1/chat/completions')) {
            return false;
        }

        /** @var array<string, mixed> $body */
        $body = $request->data();
        $messagesRaw = $body['messages'] ?? null;
        if (! is_array($messagesRaw)) {
            return false;
        }

        $assistantToolCallTurn = array_find($messagesRaw, fn ($msg): bool => is_array($msg) && ($msg['role'] ?? null) === 'assistant' && isset($msg['tool_calls']));

        if ($assistantToolCallTurn === null) {
            return false;
        }

        return ($assistantToolCallTurn['reasoning_content'] ?? null) === 'Searching the web…';
    });
});

it('omits reasoning_content from the assistant tool_call message when no reasoning was produced', function (): void {
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
                    'function' => ['name' => '$web_search', 'arguments' => '{"query":"x"}'],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ];

    $secondResponse = [
        'id' => 'resp-2',
        'model' => 'kimi-k2.6',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'ok'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ];

    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')
        ->push($firstResponse, 200)
        ->push($secondResponse, 200);

    $provider = resolve(AiManager::class)->textProvider('moonshot');

    $provider->textGateway()->generateText(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new Message('user', 'x?')],
        options: new TextGenerationOptions(maxSteps: 3),
    );

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/v1/chat/completions')) {
            return false;
        }

        /** @var array<string, mixed> $body */
        $body = $request->data();
        $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];

        foreach ($messages as $msg) {
            if (is_array($msg) && ($msg['role'] ?? null) === 'assistant' && isset($msg['tool_calls'])) {
                return ! array_key_exists('reasoning_content', $msg);
            }
        }

        return false;
    });
});
