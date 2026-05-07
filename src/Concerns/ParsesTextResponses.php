<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;

trait ParsesTextResponses
{
    /**
     * Validate the Moonshot response data.
     *
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
     * Parse the Moonshot response data into a TextResponse.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, mixed>  $tools
     * @param  array<string, mixed>|null  $schema
     * @param  array<int, mixed>  $originalMessages
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?string $instructions = null,
        array $originalMessages = [],
        ?int $timeout = null,
    ): TextResponse {
        return $this->processResponse(
            $data,
            $provider,
            $structured,
            $tools,
            $schema,
            new Collection,
            new Collection,
            instructions: $instructions,
            originalMessages: $originalMessages,
            maxSteps: $options?->maxSteps,
            options: $options,
            timeout: $timeout,
        );
    }

    /**
     * Process a single response, handling tool loops recursively.
     *
     * `$assistantReasoning` holds the per-assistant-message `reasoning_content`
     * captured from non-streaming Moonshot responses (Kimi thinking mode). It
     * must be echoed back on the follow-up request whenever `thinking.type`
     * is enabled, otherwise Moonshot returns a 400 with
     * `reasoning_content is missing in assistant tool call message`.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, mixed>  $tools
     * @param  array<string, mixed>|null  $schema
     * @param  Collection<int, Step>  $steps
     * @param  Collection<int, Message>  $messages
     * @param  Collection<int, string>  $assistantReasoning
     * @param  array<int, mixed>  $originalMessages
     */
    protected function processResponse(
        array $data,
        Provider $provider,
        bool $structured,
        array $tools,
        ?array $schema,
        Collection $steps,
        Collection $messages,
        ?string $instructions = null,
        array $originalMessages = [],
        int $depth = 0,
        ?int $maxSteps = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
        ?Collection $assistantReasoning = null,
    ): TextResponse {
        $assistantReasoning ??= new Collection;

        /** @var array<int, mixed> $choices */
        $choices = is_array($data['choices'] ?? null) ? $data['choices'] : [];
        /** @var array<string, mixed> $choice */
        $choice = is_array($choices[0] ?? null) ? $choices[0] : [];
        /** @var array<string, mixed> $message */
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $model = is_string($data['model'] ?? null) ? $data['model'] : '';

        $text = is_string($message['content'] ?? null) ? $message['content'] : '';
        $reasoning = is_string($message['reasoning_content'] ?? null) ? $message['reasoning_content'] : '';
        /** @var array<int, array<string, mixed>> $rawToolCalls */
        $rawToolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];
        $usage = $this->extractUsage($data);
        $finishReason = $this->extractFinishReason($choice);

        $mappedToolCalls = array_values(array_map(function (array $toolCall): ToolCall {
            /** @var array<string, mixed> $function */
            $function = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
            $arguments = is_string($function['arguments'] ?? null) ? $function['arguments'] : '{}';
            $decoded = json_decode($arguments, true);

            return new ToolCall(
                is_string($toolCall['id'] ?? null) ? $toolCall['id'] : '',
                is_string($function['name'] ?? null) ? $function['name'] : '',
                is_array($decoded) ? $decoded : [],
                is_string($toolCall['id'] ?? null) ? $toolCall['id'] : null,
            );
        }, $rawToolCalls));

        $step = new Step(
            $text,
            $mappedToolCalls,
            [],
            $finishReason,
            $usage,
            new Meta($provider->name(), $model),
        );

        $steps->push($step);

        $assistantMessage = new AssistantMessage($text, collect($mappedToolCalls));

        $messages->push($assistantMessage);
        $assistantReasoning->push($reasoning);

        if ($finishReason === FinishReason::ToolCalls &&
            filled($mappedToolCalls) &&
            $steps->count() < ($maxSteps ?? round(count($tools) * 1.5))) {
            /** @var array<int, Tool> $filteredTools */
            $filteredTools = array_values(array_filter($tools, static fn ($t): bool => $t instanceof Tool));

            $toolResults = $this->executeToolCalls($mappedToolCalls, $filteredTools, $provider, $timeout);

            $steps->pop();

            $steps->push(new Step(
                $text,
                $mappedToolCalls,
                $toolResults,
                $finishReason,
                $usage,
                new Meta($provider->name(), $model),
            ));

            $toolResultMessage = new ToolResultMessage(collect($toolResults));

            $messages->push($toolResultMessage);

            return $this->continueWithToolResults(
                $model,
                $provider,
                $structured,
                $tools,
                $schema,
                $steps,
                $messages,
                $instructions,
                $originalMessages,
                $depth + 1,
                $maxSteps,
                $options,
                $timeout,
                $assistantReasoning,
            );
        }

        $allToolCalls = $steps->flatMap(fn (Step $s): array => $s->toolCalls);
        $allToolResults = $steps->flatMap(fn (Step $s): array => $s->toolResults);

        if ($structured) {
            $decoded = json_decode($text, true);
            /** @var array<string, mixed> $structuredData */
            $structuredData = is_array($decoded) ? $decoded : [];

            return new StructuredTextResponse(
                $structuredData,
                $text,
                $this->combineUsage($steps),
                new Meta($provider->name(), $model),
            )->withToolCallsAndResults(
                toolCalls: $allToolCalls,
                toolResults: $allToolResults,
            )->withSteps($steps);
        }

        return new TextResponse(
            $text,
            $this->combineUsage($steps),
            new Meta($provider->name(), $model),
        )->withMessages($messages)->withSteps($steps);
    }

    /**
     * Execute tool calls and return tool results.
     *
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<int, Tool>  $tools
     * @return array<int, ToolResult>
     */
    protected function executeToolCalls(array $toolCalls, array $tools, Provider $provider, ?int $timeout = null): array
    {
        /** @var array<int, ToolResult> $results */
        $results = [];

        foreach ($toolCalls as $toolCall) {
            if ($toolCall->name === self::MOONSHOT_WEB_SEARCH) {
                $results[] = $this->buildBuiltinFunctionResult($toolCall);

                continue;
            }

            $formulaUri = $this->formulaToolUriFor($toolCall->name);

            if ($formulaUri !== null) {
                $output = $this->executeFormulaTool(
                    $provider,
                    $formulaUri,
                    $toolCall->name,
                    (string) json_encode($toolCall->arguments),
                    $timeout,
                );

                $results[] = new ToolResult(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    $output,
                    $toolCall->resultId,
                );

                continue;
            }

            $tool = $this->findTool($toolCall->name, $tools);

            if ($tool === null) {
                continue;
            }

            $result = $this->executeTool($tool, $toolCall->arguments);

            $results[] = new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                $result,
                $toolCall->resultId,
            );
        }

        return $results;
    }

    /**
     * Build the echoed ToolResult required by Moonshot's builtin function protocol.
     *
     * Per Kimi spec the client must reply to a builtin tool_call (`$web_search`,
     * `$convert`, `$fetch`) with the same arguments JSON-encoded as content; the
     * tool itself runs server-side.
     *
     * @see https://platform.kimi.ai/docs/guide/use-official-tools
     */
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
     * Continue the conversation with tool results by making a follow-up request.
     *
     * @param  array<int, mixed>  $tools
     * @param  array<string, mixed>|null  $schema
     * @param  Collection<int, Step>  $steps
     * @param  Collection<int, Message>  $messages
     * @param  array<int, mixed>  $originalMessages
     * @param  Collection<int, string>  $assistantReasoning
     */
    protected function continueWithToolResults(
        string $model,
        Provider $provider,
        bool $structured,
        array $tools,
        ?array $schema,
        Collection $steps,
        Collection $messages,
        ?string $instructions,
        array $originalMessages,
        int $depth,
        ?int $maxSteps,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
        ?Collection $assistantReasoning = null,
    ): TextResponse {
        $assistantReasoning ??= new Collection;

        $chatMessages = $this->mapMessagesToChat(
            $originalMessages,
            $this->composeInstructions($instructions, $schema),
        );

        $assistantIndex = 0;

        foreach ($messages as $msg) {
            if ($msg instanceof AssistantMessage) {
                /** @var array<string, mixed> $mapped */
                $mapped = ['role' => 'assistant'];

                if (filled($msg->content)) {
                    $mapped['content'] = $msg->content;
                }

                $reasoning = $assistantReasoning->get($assistantIndex);

                if (is_string($reasoning) && filled($reasoning)) {
                    $mapped['reasoning_content'] = $reasoning;
                }

                $assistantIndex++;

                if ($msg->toolCalls->isNotEmpty()) {
                    /** @var Collection<int, ToolCall> $toolCalls */
                    $toolCalls = $msg->toolCalls;
                    $mapped['tool_calls'] = $toolCalls->map(
                        fn (ToolCall $toolCall): array => $this->serializeToolCallToChat($toolCall)
                    )->all();
                }

                $chatMessages[] = $mapped;
            } elseif ($msg instanceof ToolResultMessage) {
                /** @var Collection<int, ToolResult> $toolResults */
                $toolResults = $msg->toolResults;

                foreach ($toolResults as $toolResult) {
                    $chatMessages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolResult->resultId ?? $toolResult->id,
                        'content' => $this->serializeToolResultOutput($toolResult->result),
                    ];
                }
            }
        }

        $body = [
            'model' => $model,
            'messages' => $chatMessages,
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
            $body = array_merge($body, $providerOptions);
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('chat/completions', $body),
        );

        /** @var array<string, mixed> $data */
        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->processResponse(
            $data,
            $provider,
            $structured,
            $tools,
            $schema,
            $steps,
            $messages,
            $instructions,
            $originalMessages,
            $depth,
            $maxSteps,
            $options,
            $timeout,
            $assistantReasoning,
        );
    }

    /**
     * Extract usage data from the response.
     *
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
     * Extract and map the finish reason from the response.
     *
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

    /**
     * Combine usage across all steps.
     *
     * @param  Collection<int, Step>  $steps
     */
    protected function combineUsage(Collection $steps): Usage
    {
        /** @var Usage $result */
        $result = $steps->reduce(
            fn (Usage $carry, Step $step): Usage => $carry->add($step->usage),
            new Usage(0, 0)
        );

        return $result;
    }
}
