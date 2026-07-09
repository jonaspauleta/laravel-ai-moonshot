<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Concerns;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Psr\Http\Message\StreamInterface;

trait HandlesTextStreaming
{
    /**
     * @return Generator<int, StreamEvent, mixed, StepResponse|null>
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        StreamInterface $streamBody,
    ): Generator {
        $messageId = $this->generateEventId();
        $reasoningId = '';
        $inReasoning = false;
        $currentReasoning = '';
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        /** @var array<int|string, array{id: string, name: string, arguments: string}> $pendingToolCalls */
        $pendingToolCalls = [];
        $usage = null;
        $finishReason = null;
        $responseModel = $model;

        foreach ($this->parseServerSentEvents($streamBody) as $event) {
            /** @var array<string, mixed> $data */
            $data = is_array($event) ? $event : [];

            if (isset($data['error'])) {
                /** @var array<string, mixed> $error */
                $error = is_array($data['error']) ? $data['error'] : [];

                yield new Error(
                    $this->generateEventId(),
                    is_string($error['code'] ?? null) ? $error['code'] : 'unknown_error',
                    is_string($error['message'] ?? null) ? $error['message'] : 'Unknown error',
                    false,
                    time(),
                )->withInvocationId($invocationId);

                return null;
            }

            /** @var array<int, mixed> $choices */
            $choices = is_array($data['choices'] ?? null) ? $data['choices'] : [];
            /** @var array<string, mixed>|null $choice */
            $choice = is_array($choices[0] ?? null) ? $choices[0] : null;

            if ($choice === null) {
                if (isset($data['usage'])) {
                    $usage = $this->extractUsage($data);
                }

                continue;
            }

            /** @var array<string, mixed> $delta */
            $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];

            if (! $streamStartEmitted) {
                $streamStartEmitted = true;
                $responseModel = is_string($data['model'] ?? null) ? $data['model'] : $model;

                yield new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $responseModel,
                    time(),
                )->withInvocationId($invocationId);
            }

            if ($inReasoning && ((is_string($delta['content'] ?? null) && $delta['content'] !== '') || isset($delta['tool_calls']))) {
                $inReasoning = false;

                yield new ReasoningEnd(
                    $this->generateEventId(),
                    $reasoningId,
                    time(),
                )->withInvocationId($invocationId);
            }

            if (is_string($delta['reasoning_content'] ?? null) && $delta['reasoning_content'] !== '') {
                if (! $inReasoning) {
                    $inReasoning = true;
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

            if (is_string($delta['content'] ?? null) && $delta['content'] !== '') {
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

            if (is_array($delta['tool_calls'] ?? null)) {
                foreach ($delta['tool_calls'] as $toolCallDelta) {
                    if (! is_array($toolCallDelta)) {
                        continue;
                    }

                    $index = $toolCallDelta['index'] ?? null;

                    if (! is_int($index) && ! is_string($index)) {
                        continue;
                    }

                    /** @var array<string, mixed> $function */
                    $function = is_array($toolCallDelta['function'] ?? null) ? $toolCallDelta['function'] : [];

                    $pendingToolCalls[$index] ??= [
                        'id' => is_string($toolCallDelta['id'] ?? null) ? $toolCallDelta['id'] : '',
                        'name' => is_string($function['name'] ?? null) ? $function['name'] : '',
                        'arguments' => '',
                    ];

                    if (is_string($function['arguments'] ?? null)) {
                        $pendingToolCalls[$index]['arguments'] .= $function['arguments'];
                    }
                }
            }

            if (is_string($choice['finish_reason'] ?? null)) {
                $finishReason = $choice['finish_reason'];
            }

            if (isset($data['usage'])) {
                $usage = $this->extractUsage($data);
            }
        }

        if ($inReasoning) {
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

        $toolCalls = [];

        if (filled($pendingToolCalls) && $finishReason === 'tool_calls') {
            foreach ($this->mapStreamToolCalls($pendingToolCalls) as $toolCall) {
                $toolCalls[] = $toolCall;

                yield new ToolCallEvent(
                    $this->generateEventId(),
                    $toolCall,
                    time(),
                )->withInvocationId($invocationId);
            }
        }

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason(['finish_reason' => $finishReason ?? '']),
            usage: $usage ?? new Usage,
            meta: new Meta($provider->name(), $responseModel),
            // @phpstan-ignore argument.type (Laravel AI 0.9 types provider blocks as a list)
            providerContentBlocks: $currentReasoning !== '' ? [
                'reasoning_content' => $currentReasoning,
            ] : [],
        );
    }

    /**
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

    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
