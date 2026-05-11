<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Concerns;

use Laravel\Ai\Gateway\Concerns\ComposesSchemaInstructions;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;

trait BuildsTextRequests
{
    use ComposesSchemaInstructions;

    /**
     * When Kimi `thinking.type` is `enabled`, assistant messages that include
     * `tool_calls` must repeat `reasoning_content` from the prior model output.
     * If the stream (or non-streaming body) omitted reasoning entirely, this
     * sentinel keeps the field present so the API accepts the request — it is
     * not a substitute for real chain-of-thought and must not be shown as user
     * visible "thinking"; it only satisfies echo rules for the next HTTP call.
     */
    private const FALLBACK_REASONING_CONTENT_FOR_KIMI_TOOL_STEP = '';

    /**
     * Build the request body for the Chat Completions API.
     *
     * @param  array<int, mixed>  $messages
     * @param  array<int, mixed>  $tools
     * @param  array<string, mixed>|null  $schema
     * @return array<string, mixed>
     */
    protected function buildTextRequestBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout = null,
    ): array {
        $body = [
            'model' => $model,
            'messages' => $this->mapMessagesToChat(
                $messages,
                $this->composeInstructions($instructions, $schema),
            ),
        ];

        if (filled($tools)) {
            $mappedTools = $this->mapTools($tools, $provider, $timeout);

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
            return array_merge($body, $providerOptions);
        }

        return $body;
    }

    /**
     * Build the response format options for structured output.
     *
     * @return array{type: string}
     */
    protected function buildResponseFormat(): array
    {
        return ['type' => 'json_object'];
    }

    /**
     * Kimi returns HTTP 400 when thinking is enabled but a prior assistant
     * `tool_calls` message has no `reasoning_content` key at all.
     */
    protected function thinkingTypeIsEnabled(?TextGenerationOptions $options, Provider $provider): bool
    {
        $opts = $options?->providerOptions($provider->driver());

        return is_array($opts['thinking'] ?? null)
            && ($opts['thinking']['type'] ?? null) === 'enabled';
    }

    /**
     * @param  array<string, mixed>  $assistantMessage
     */
    protected function ensureEchoedReasoningForThinkingToolStep(
        array &$assistantMessage,
        ?TextGenerationOptions $options,
        Provider $provider,
    ): void {
        if (! isset($assistantMessage['tool_calls']) || $assistantMessage['tool_calls'] === []) {
            return;
        }

        if (! $this->thinkingTypeIsEnabled($options, $provider)) {
            return;
        }

        if (array_key_exists('reasoning_content', $assistantMessage)) {
            return;
        }

        $assistantMessage['reasoning_content'] = self::FALLBACK_REASONING_CONTENT_FOR_KIMI_TOOL_STEP;
    }
}
