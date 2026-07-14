<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Concerns;

use Jonaspauleta\LaravelAiMoonshot\MoonshotTextGenerationLoop;
use Laravel\Ai\Gateway\Concerns\ComposesSchemaInstructions;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
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
     * Whether strict `response_format` must be withheld from requests that
     * also carry `tools`.
     *
     * Kimi frequently returns an empty schema-valid JSON object without ever
     * invoking a tool when a request combines `tools` (tool_choice: auto) with
     * a strict `json_schema` response_format. When deferral is on (the
     * default), tool-loop steps omit `response_format` (the schema prompt
     * from {@see ComposesSchemaInstructions} keeps steering the model) and
     * {@see MoonshotTextGenerationLoop} appends one final tool-free request
     * that enforces the schema. Disable via
     * `ai.providers.moonshot.defer_structured_output => false`.
     */
    public function shouldDeferStructuredOutput(Provider $provider): bool
    {
        return (bool) ($provider->additionalConfiguration()['defer_structured_output'] ?? true);
    }

    /**
     * Build the request body for the Chat Completions API.
     *
     * @param  array<mixed>  $messages
     * @param  array<mixed>  $tools
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

        if (filled($schema) && (! isset($body['tools']) || ! $this->shouldDeferStructuredOutput($provider))) {
            $body['response_format'] = $this->buildResponseFormat($schema);
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
     * Build the `response_format` envelope Moonshot expects for Structured Outputs.
     *
     * Moonshot's Chat Completions API mirrors OpenAI's `response_format` shape:
     * `{ type: 'json_schema', json_schema: { name, schema, strict } }`. Strict
     * mode requires MFJS (Moonshot Flavored JSON Schema), a subset that excludes
     * `format`, `pattern`, `oneOf`, `allOf`, `minLength`/`maxLength`, etc. Schemas
     * are passed through verbatim — Moonshot returns HTTP 400 on incompatible
     * keywords, which surfaces cleanly via the existing failover-error handling.
     *
     * @param  array<string, mixed>  $schema
     * @return array{
     *     type: 'json_schema',
     *     json_schema: array{name: string, schema: array<string, mixed>, strict: true}
     * }
     */
    protected function buildResponseFormat(array $schema): array
    {
        $schemaArray = new ObjectSchema($schema)->toSchema();

        $name = $schemaArray['name'] ?? null;
        unset($schemaArray['name']);

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => is_string($name) ? $name : 'schema_definition',
                'schema' => $schemaArray,
                'strict' => true,
            ],
        ];
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
