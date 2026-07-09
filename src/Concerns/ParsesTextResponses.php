<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Concerns;

use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;

trait ParsesTextResponses
{
    use DecodesStructuredOutput;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error'])) {
            /** @var array{type?: mixed, message?: mixed} $error */
            $error = is_array($data['error'] ?? null) ? $data['error'] : [];

            throw new AiException(sprintf(
                'Moonshot Error: [%s] %s',
                is_string($error['type'] ?? null) ? $error['type'] : 'unknown',
                is_string($error['message'] ?? null) ? $error['message'] : 'Unknown Moonshot error.',
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function parseTextResponse(array $data, Provider $provider, bool $structured): StepResponse
    {
        /** @var array<int, mixed> $choices */
        $choices = is_array($data['choices'] ?? null) ? $data['choices'] : [];
        /** @var array<string, mixed> $choice */
        $choice = is_array($choices[0] ?? null) ? $choices[0] : [];
        /** @var array<string, mixed> $message */
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        /** @var array<int, array<string, mixed>> $rawToolCalls */
        $rawToolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];

        $text = is_string($message['content'] ?? null) ? $message['content'] : '';
        $reasoning = is_string($message['reasoning_content'] ?? null) ? $message['reasoning_content'] : '';

        $toolCalls = array_values(array_map(function (array $toolCall): ToolCall {
            /** @var array<string, mixed> $function */
            $function = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
            $arguments = is_string($function['arguments'] ?? null) ? $function['arguments'] : '{}';
            $decoded = json_decode($arguments, true);
            $id = is_string($toolCall['id'] ?? null) ? $toolCall['id'] : '';

            return new ToolCall(
                $id,
                is_string($function['name'] ?? null) ? $function['name'] : '',
                is_array($decoded) ? $decoded : [],
                $id !== '' ? $id : null,
            );
        }, $rawToolCalls));

        /** @var array<string, mixed>|null $structuredData */
        $structuredData = $structured ? $this->decodeStructuredOutput($text) : null;

        return new StepResponse(
            text: $text,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason($choice),
            usage: $this->extractUsage($data),
            meta: new Meta(
                $provider->name(),
                is_string($data['model'] ?? null) ? $data['model'] : '',
            ),
            structured: $structuredData,
            // @phpstan-ignore argument.type (Laravel AI 0.9 types provider blocks as a list)
            providerContentBlocks: $reasoning !== '' ? [
                'reasoning_content' => $reasoning,
            ] : [],
        );
    }

    public function resolveProviderToolCall(
        ToolCall $toolCall,
        Provider $provider,
        ?int $timeout = null,
    ): ?ToolResult {
        if ($toolCall->name === self::MOONSHOT_WEB_SEARCH) {
            return $this->buildBuiltinFunctionResult($toolCall);
        }

        $formulaUri = $this->formulaToolUriFor($toolCall->name);

        if ($formulaUri === null) {
            return null;
        }

        return new ToolResult(
            $toolCall->id,
            $toolCall->name,
            $toolCall->arguments,
            $this->executeFormulaTool(
                $provider,
                $formulaUri,
                $toolCall->name,
                (string) json_encode($toolCall->arguments),
                $timeout,
            ),
            $toolCall->resultId,
        );
    }

    protected function buildBuiltinFunctionResult(ToolCall $toolCall): ToolResult
    {
        $encoded = json_encode($toolCall->arguments);

        return new ToolResult(
            $toolCall->id,
            $toolCall->name,
            $toolCall->arguments,
            $encoded === false ? '{}' : $encoded,
            $toolCall->resultId,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractUsage(array $data): Usage
    {
        /** @var array<string, mixed> $usage */
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        /** @var array<string, mixed> $details */
        $details = is_array($usage['completion_tokens_details'] ?? null) ? $usage['completion_tokens_details'] : [];

        return new Usage(
            promptTokens: is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : 0,
            completionTokens: is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : 0,
            cacheReadInputTokens: is_int($usage['prompt_cache_hit_tokens'] ?? null) ? $usage['prompt_cache_hit_tokens'] : 0,
            reasoningTokens: is_int($details['reasoning_tokens'] ?? null) ? $details['reasoning_tokens'] : 0,
        );
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    protected function extractFinishReason(array $choice): FinishReason
    {
        return match ($choice['finish_reason'] ?? '') {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }
}
