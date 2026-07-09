<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Jonaspauleta\LaravelAiMoonshot\Messages\KimiAssistantMessage;
use Laravel\Ai\AiManager;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * TextGenerationOptions subclass that returns Kimi thinking with keep:all so
 * we exercise the replay code path that requires reasoning_content.
 */
final class ReplayThinkingOptions extends TextGenerationOptions
{
    /** @return array<string, mixed> */
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

it('echoes reasoning_content from a KimiAssistantMessage in the outgoing chat body', function (): void {
    $response = [
        'id' => 'resp-final',
        'model' => 'kimi-k2.6',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'Sunny.'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ];

    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')->push($response, 200);

    $provider = resolve(AiManager::class)->textProvider('moonshot');

    $historicalAssistant = new KimiAssistantMessage(
        content: '',
        reasoningContent: 'I should check the weather API for Spa.',
        toolCalls: new Collection([
            new ToolCall(
                id: 'call_abc',
                name: 'WeatherLookup',
                arguments: ['location' => 'Spa'],
                resultId: 'call_abc',
            ),
        ]),
    );

    $historicalToolResult = new ToolResultMessage(
        new Collection([
            new ToolResult(
                id: 'call_abc',
                name: 'WeatherLookup',
                arguments: ['location' => 'Spa'],
                result: '{"sky":"clear","temp_c":22}',
                resultId: 'call_abc',
            ),
        ]),
    );

    $provider->textGenerationLoop()->generate(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [
            new UserMessage('What is the weather at Spa?'),
            $historicalAssistant,
            $historicalToolResult,
            new Message('user', 'And tomorrow?'),
        ],
        options: new ReplayThinkingOptions(maxSteps: 1),
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

        $assistantToolCallTurn = array_find($messagesRaw, fn ($msg): bool => is_array($msg)
            && ($msg['role'] ?? null) === 'assistant'
            && isset($msg['tool_calls']));

        if ($assistantToolCallTurn === null) {
            return false;
        }

        return ($assistantToolCallTurn['reasoning_content'] ?? null) === 'I should check the weather API for Spa.';
    });
});

it('fills a placeholder reasoning_content when KimiAssistantMessage has empty reasoningContent and thinking is enabled', function (): void {
    $response = [
        'id' => 'resp-final',
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

    $provider->textGenerationLoop()->generate(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [
            new UserMessage('Anything?'),
            new KimiAssistantMessage(
                content: '',
                reasoningContent: '',
                toolCalls: new Collection([
                    new ToolCall(id: 'c1', name: 'NoOp', arguments: [], resultId: 'c1'),
                ]),
            ),
            new ToolResultMessage(new Collection([
                new ToolResult(id: 'c1', name: 'NoOp', arguments: [], result: '{}', resultId: 'c1'),
            ])),
            new Message('user', 'now?'),
        ],
        options: new ReplayThinkingOptions(maxSteps: 1),
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
                return ($msg['reasoning_content'] ?? null) === "\u{200B}";
            }
        }

        return false;
    });
});

it('plain AssistantMessage history still does not emit reasoning_content', function (): void {
    $response = [
        'id' => 'resp-final',
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

    $provider->textGenerationLoop()->generate(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [
            new UserMessage('hi'),
            new AssistantMessage(
                'I called a tool.',
                new Collection([
                    new ToolCall(id: 'c1', name: 'Foo', arguments: [], resultId: 'c1'),
                ]),
            ),
            new ToolResultMessage(new Collection([
                new ToolResult(id: 'c1', name: 'Foo', arguments: [], result: '{}', resultId: 'c1'),
            ])),
            new Message('user', 'again?'),
        ],
        options: new TextGenerationOptions(maxSteps: 1),
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

it('echoes reasoning_content from a plain AssistantMessage provider content block', function (): void {
    Http::fakeSequence('api.moonshot.ai/v1/chat/completions')->push([
        'id' => 'resp-final',
        'model' => 'kimi-k2.6',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'ok'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ], 200);

    $provider = resolve(AiManager::class)->textProvider('moonshot');
    $assistant = new AssistantMessage(
        '',
        new Collection([new ToolCall(id: 'c1', name: 'NoOp', arguments: [], resultId: 'c1')]),
        ['reasoning_content' => 'Reasoned by Kimi.'], // @phpstan-ignore argument.type
    );

    $provider->textGenerationLoop()->generate(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [
            new UserMessage('Anything?'),
            $assistant,
            new ToolResultMessage(new Collection([
                new ToolResult(id: 'c1', name: 'NoOp', arguments: [], result: '{}', resultId: 'c1'),
            ])),
            new UserMessage('Now?'),
        ],
    );

    Http::assertSent(function (Request $request): bool {
        $messages = $request->data()['messages'] ?? [];
        $assistantMessage = is_array($messages)
            ? array_find($messages, static fn (mixed $message): bool => is_array($message) && isset($message['tool_calls']))
            : null;

        return is_array($assistantMessage)
            && ($assistantMessage['reasoning_content'] ?? null) === 'Reasoned by Kimi.';
    });
});
