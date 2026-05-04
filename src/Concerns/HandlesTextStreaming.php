<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Concerns;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Psr\Http\Message\StreamInterface;

trait HandlesTextStreaming
{
    /**
     * Process a Chat Completions streaming response and yield Laravel stream events.
     *
     * @param  array<int, mixed>  $tools
     * @param  array<string, mixed>|null  $schema
     * @param  StreamInterface  $streamBody
     * @param  array<int, mixed>  $originalMessages
     * @param  array<int, array<string, mixed>>  $priorChatMessages
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        $streamBody,
        ?string $instructions = null,
        array $originalMessages = [],
        int $depth = 0,
        ?int $maxSteps = null,
        array $priorChatMessages = [],
        ?int $timeout = null,
    ): Generator {
        $maxSteps ??= $options?->maxSteps;

        $messageId = $this->generateEventId();
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $reasoningStartEmitted = false;
        $reasoningEndEmitted = false;
        $reasoningId = '';
        $currentText = '';
        $currentReasoning = '';
        /** @var array<int|string, array{id: string, name: string, arguments: string}> $pendingToolCalls */
        $pendingToolCalls = [];
        $usage = null;
        $finishReason = null;

        foreach ($this->parseServerSentEvents($streamBody) as $event) {
            /** @var array<string, mixed> $data */
            $data = is_array($event) ? $event : [];

            if (isset($data['error'])) {
                /** @var array<string, mixed> $err */
                $err = is_array($data['error']) ? $data['error'] : [];

                yield new Error(
                    $this->generateEventId(),
                    is_string($err['code'] ?? null) ? $err['code'] : 'unknown_error',
                    is_string($err['message'] ?? null) ? $err['message'] : 'Unknown error',
                    false,
                    time(),
                )->withInvocationId($invocationId);

                return;
            }

            /** @var array<string, mixed> $choices0 */
            $choices0 = [];
            if (isset($data['choices']) && is_array($data['choices']) && isset($data['choices'][0]) && is_array($data['choices'][0])) {
                $choices0 = $data['choices'][0];
            }
            $choice = $choices0 !== [] ? $choices0 : null;

            if (! $choice) {
                if (isset($data['usage'])) {
                    $usage = $this->extractUsage($data);
                }

                continue;
            }

            /** @var array<string, mixed> $delta */
            $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];

            if (! $streamStartEmitted) {
                $streamStartEmitted = true;

                yield new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    is_string($data['model'] ?? null) ? $data['model'] : $model,
                    time(),
                )->withInvocationId($invocationId);
            }

            if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== '' && is_string($delta['reasoning_content'])) {
                if (! $reasoningStartEmitted) {
                    $reasoningStartEmitted = true;
                    $reasoningId = $this->generateEventId();

                    yield new ReasoningStart(
                        $this->generateEventId(),
                        $reasoningId,
                        time(),
                    )->withInvocationId($invocationId);
                }

                $currentReasoning .= $delta['reasoning_content'];

                yield new ReasoningDelta(
                    $this->generateEventId(),
                    $reasoningId,
                    $delta['reasoning_content'],
                    time(),
                )->withInvocationId($invocationId);
            }

            if (isset($delta['content']) && $delta['content'] !== '' && is_string($delta['content'])) {
                if ($reasoningStartEmitted && ! $reasoningEndEmitted) {
                    $reasoningEndEmitted = true;

                    yield new ReasoningEnd(
                        $this->generateEventId(),
                        $reasoningId,
                        time(),
                    )->withInvocationId($invocationId);
                }

                if (! $textStartEmitted) {
                    $textStartEmitted = true;

                    yield new TextStart(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    )->withInvocationId($invocationId);
                }

                $currentText .= $delta['content'];

                yield new TextDelta(
                    $this->generateEventId(),
                    $messageId,
                    $delta['content'],
                    time(),
                )->withInvocationId($invocationId);
            }

            if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tcDelta) {
                    if (! is_array($tcDelta)) {
                        continue;
                    }

                    $idx = $tcDelta['index'] ?? null;
                    if (! is_int($idx) && ! is_string($idx)) {
                        continue;
                    }

                    /** @var array<string, mixed> $function */
                    $function = is_array($tcDelta['function'] ?? null) ? $tcDelta['function'] : [];

                    if (! isset($pendingToolCalls[$idx])) {
                        $pendingToolCalls[$idx] = [
                            'id' => is_string($tcDelta['id'] ?? null) ? $tcDelta['id'] : '',
                            'name' => is_string($function['name'] ?? null) ? $function['name'] : '',
                            'arguments' => '',
                        ];
                    }

                    if (isset($function['arguments']) && is_string($function['arguments'])) {
                        $pendingToolCalls[$idx]['arguments'] .= $function['arguments'];
                    }
                }
            }

            if (isset($choice['finish_reason']) && is_string($choice['finish_reason'])) {
                $finishReason = $choice['finish_reason'];
            }

            if (isset($data['usage'])) {
                $usage = $this->extractUsage($data);
            }
        }

        if ($reasoningStartEmitted && ! $reasoningEndEmitted) {
            $reasoningEndEmitted = true;

            yield new ReasoningEnd(
                $this->generateEventId(),
                $reasoningId,
                time(),
            )->withInvocationId($invocationId);
        }

        if ($textStartEmitted) {
            yield new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            )->withInvocationId($invocationId);
        }

        if (filled($pendingToolCalls) && $finishReason === 'tool_calls') {
            $mappedToolCalls = $this->mapStreamToolCalls($pendingToolCalls);

            foreach ($mappedToolCalls as $toolCall) {
                yield new ToolCallEvent(
                    $this->generateEventId(),
                    $toolCall,
                    time(),
                )->withInvocationId($invocationId);
            }

            yield from $this->handleStreamingToolCalls(
                $invocationId,
                $provider,
                $model,
                $tools,
                $schema,
                $options,
                $mappedToolCalls,
                $currentText,
                $currentReasoning,
                $instructions,
                $originalMessages,
                $depth,
                $maxSteps,
                $priorChatMessages,
                $timeout,
            );

            return;
        }

        yield new StreamEnd(
            $this->generateEventId(),
            $this->extractFinishReason(['finish_reason' => $finishReason ?? ''])->value,
            $usage ?? new Usage(0, 0),
            time(),
        )->withInvocationId($invocationId);
    }

    /**
     * Handle tool calls detected during streaming.
     *
     * @param  array<int, mixed>  $tools
     * @param  array<string, mixed>|null  $schema
     * @param  array<int, ToolCall>  $mappedToolCalls
     * @param  array<int, mixed>  $originalMessages
     * @param  array<int, array<string, mixed>>  $priorChatMessages
     */
    protected function handleStreamingToolCalls(
        string $invocationId,
        Provider $provider,
        string $model,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        array $mappedToolCalls,
        string $currentText,
        string $currentReasoning,
        ?string $instructions,
        array $originalMessages,
        int $depth,
        ?int $maxSteps,
        array $priorChatMessages,
        ?int $timeout = null,
    ): Generator {
        /** @var array<int, ToolResult> $toolResults */
        $toolResults = [];

        foreach ($mappedToolCalls as $toolCall) {
            if ($toolCall->name === self::MOONSHOT_WEB_SEARCH) {
                $toolResult = $this->buildBuiltinWebSearchResult($toolCall);
            } else {
                $tool = $this->findTool($toolCall->name, $tools);

                if ($tool === null) {
                    continue;
                }

                $result = $this->executeTool($tool, $toolCall->arguments);

                $toolResult = new ToolResult(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    $result,
                    $toolCall->resultId,
                );
            }

            $toolResults[] = $toolResult;

            yield new ToolResultEvent(
                $this->generateEventId(),
                $toolResult,
                true,
                null,
                time(),
            )->withInvocationId($invocationId);
        }

        if ($depth + 1 < ($maxSteps ?? round(count($tools) * 1.5))) {
            /** @var array<string, mixed> $assistantMsg */
            $assistantMsg = ['role' => 'assistant'];

            if (filled($currentText)) {
                $assistantMsg['content'] = $currentText;
            }

            if (filled($currentReasoning)) {
                $assistantMsg['reasoning_content'] = $currentReasoning;
            }

            $assistantMsg['tool_calls'] = array_map(
                fn (ToolCall $toolCall): array => $this->serializeToolCallToChat($toolCall), $mappedToolCalls
            );

            /** @var array<int, array<string, mixed>> $toolResultMessages */
            $toolResultMessages = [];

            foreach ($toolResults as $toolResult) {
                $toolResultMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolResult->resultId ?? $toolResult->id,
                    'content' => $this->serializeToolResultOutput($toolResult->result),
                ];
            }

            $updatedPriorMessages = [...$priorChatMessages, $assistantMsg, ...$toolResultMessages];

            $chatMessages = [
                ...$this->mapMessagesToChat(
                    $originalMessages,
                    $this->composeInstructions($instructions, $schema),
                ),
                ...$updatedPriorMessages,
            ];

            $body = [
                'model' => $model,
                'messages' => $chatMessages,
                'stream' => true,
                'stream_options' => ['include_usage' => true],
            ];

            if (filled($tools)) {
                $mappedTools = $this->mapTools($tools);

                if (filled($mappedTools)) {
                    $body['tool_choice'] = 'auto';
                    $body['tools'] = $mappedTools;
                }
            }

            if (filled($schema)) {
                $body['response_format'] = $this->buildResponseFormat();
            }

            if (! is_null($options?->maxTokens)) {
                $body['max_completion_tokens'] = $options->maxTokens;
            }

            if (! is_null($options?->temperature)) {
                $body['temperature'] = $options->temperature;
            }

            $providerOptions = $options?->providerOptions($provider->driver());

            if (filled($providerOptions)) {
                $body = array_merge($body, $providerOptions);
            }

            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $this->client($provider, $timeout)
                    ->withOptions(['stream' => true])
                    ->post('chat/completions', $body),
            );

            yield from $this->processTextStream(
                $invocationId,
                $provider,
                $model,
                $tools,
                $schema,
                $options,
                $response->toPsrResponse()->getBody(),
                $instructions,
                $originalMessages,
                $depth + 1,
                $maxSteps,
                $updatedPriorMessages,
                $timeout,
            );
        } else {
            yield new StreamEnd(
                $this->generateEventId(),
                'stop',
                new Usage(0, 0),
                time(),
            )->withInvocationId($invocationId);
        }
    }

    /**
     * Map raw streaming tool call data to ToolCall DTOs.
     *
     * @param  array<int|string, array{id: string, name: string, arguments: string}>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapStreamToolCalls(array $toolCalls): array
    {
        return array_values(array_map(function (array $toolCall): ToolCall {
            $decoded = json_decode($toolCall['arguments'], true);

            return new ToolCall(
                $toolCall['id'],
                $toolCall['name'],
                is_array($decoded) ? $decoded : [],
                $toolCall['id'] !== '' ? $toolCall['id'] : null,
            );
        }, $toolCalls));
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
