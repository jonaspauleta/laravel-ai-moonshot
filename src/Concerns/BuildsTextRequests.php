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
     * `tool_calls` must repeat non-empty `reasoning_content`. The API rejects a
     * missing field and (in practice) an empty string for this shape. This
     * invisible placeholder satisfies validation for replayed history and
     * in-turn tool steps when the stream or stored messages omitted reasoning.
     */
    private const FALLBACK_REASONING_CONTENT_FOR_KIMI_TOOL_STEP = "\u{200B}";

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
            'messages' => $this->applyThinkingReasoningEchoFallbacks(
                $this->mapMessagesToChat(
                    $messages,
                    $this->composeInstructions($instructions, $schema),
                ),
                $options,
                $provider,
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
     * `tool_calls` message has no usable `reasoning_content`.
     */
    protected function thinkingTypeIsEnabled(?TextGenerationOptions $options, Provider $provider): bool
    {
        $opts = $options?->providerOptions($provider->driver());

        return is_array($opts['thinking'] ?? null)
            && ($opts['thinking']['type'] ?? null) === 'enabled';
    }

    /**
     * Walk the OpenAI-shaped `messages` array and ensure every assistant message
     * with `tool_calls` carries non-empty `reasoning_content` when Kimi
     * thinking mode is on — including conversation history from
     * `mapMessagesToChat`, not only the in-turn synthetic assistant row.
     *
     * @param  array<int, array<string, mixed>>  $chatMessages
     * @return array<int, array<string, mixed>>
     */
    protected function applyThinkingReasoningEchoFallbacks(
        array $chatMessages,
        ?TextGenerationOptions $options,
        Provider $provider,
    ): array {
        if (! $this->thinkingTypeIsEnabled($options, $provider)) {
            return $chatMessages;
        }

        foreach ($chatMessages as $i => $msg) {
            if (($msg['role'] ?? null) !== 'assistant') {
                continue;
            }
            if (! isset($msg['tool_calls'])) {
                continue;
            }
            if (! is_array($msg['tool_calls'])) {
                continue;
            }
            if ($msg['tool_calls'] === []) {
                continue;
            }

            $rc = $msg['reasoning_content'] ?? null;

            if (is_string($rc) && $rc !== '') {
                continue;
            }

            $msg['reasoning_content'] = self::FALLBACK_REASONING_CONTENT_FOR_KIMI_TOOL_STEP;
            $chatMessages[$i] = $msg;
        }

        return $chatMessages;
    }
}
