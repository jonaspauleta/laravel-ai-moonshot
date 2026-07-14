<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot;

use Generator;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\TextResponse;
use Override;

final class MoonshotTextGenerationLoop extends TextGenerationLoop
{
    private ?int $timeout = null;

    private ?string $model = null;

    private ?string $instructions = null;

    /** @var array<string, Type>|null */
    private ?array $schema = null;

    private ?TextGenerationOptions $options = null;

    private bool $structuredOutputDeferred = false;

    public function __construct(
        private readonly MoonshotGateway $moonshotGateway,
        private readonly Provider&TextProvider $provider,
    ) {
        parent::__construct($moonshotGateway);
    }

    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     */
    #[Override]
    public function generate(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $this->moonshotGateway->beginTextGeneration();
        $this->timeout = $timeout;
        $this->model = $model;
        $this->instructions = $instructions;
        $this->schema = $schema;
        $this->options = $options;
        $this->structuredOutputDeferred = filled($schema)
            && filled($tools)
            && $this->moonshotGateway->shouldDeferStructuredOutput($this->provider);

        try {
            return parent::generate($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout);
        } finally {
            $this->timeout = null;
            $this->model = null;
            $this->instructions = null;
            $this->schema = null;
            $this->options = null;
            $this->structuredOutputDeferred = false;
        }
    }

    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     */
    #[Override]
    public function stream(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        $this->moonshotGateway->beginTextGeneration();
        $this->timeout = $timeout;

        try {
            yield from parent::stream($invocationId, $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout);
        } finally {
            $this->timeout = null;
        }
    }

    /**
     * When structured output was deferred (tools + schema on Kimi), the tool
     * loop ran without `response_format`. Append one final tool-free request
     * that enforces the strict `json_schema` over the full conversation, so
     * the terminal generation is schema-validated and cannot invite more
     * tool calls.
     *
     * @param  Collection<int, Step>  $steps
     * @param  array<int, Message>  $allMessages
     */
    #[Override]
    protected function buildFinalResponse(
        Collection $steps,
        array $allMessages,
        int $originalMessageCount,
        ?StepResponse $lastResult,
    ): TextResponse {
        if (! $this->structuredOutputDeferred || $this->model === null) {
            return parent::buildFinalResponse($steps, $allMessages, $originalMessageCount, $lastResult);
        }

        $finalizeResult = $this->moonshotGateway->generateTextStep(
            $this->provider,
            $this->model,
            $this->instructions,
            $this->withoutTrailingUnansweredToolCalls($allMessages),
            [],
            $this->schema,
            $this->options,
            $this->timeout,
            new StepContext(stepNumber: $steps->count(), isFinalStep: true),
        );

        $steps->push($this->buildStep($finalizeResult));

        $allMessages[] = new AssistantMessage(
            $finalizeResult->text,
            collect($finalizeResult->toolCalls),
            $finalizeResult->providerContentBlocks,
        );

        return parent::buildFinalResponse($steps, $allMessages, $originalMessageCount, $finalizeResult);
    }

    #[Override]
    protected function executeToolCalls(array $toolCalls, array $tools): array
    {
        return array_map(function (ToolCall $toolCall) use ($tools): ToolResult {
            $providerResult = $this->moonshotGateway->resolveProviderToolCall(
                $toolCall,
                $this->provider,
                $this->timeout,
            );

            if ($providerResult instanceof ToolResult) {
                return $providerResult;
            }

            $tool = $this->findTool($toolCall->name, $tools);

            if (! $tool instanceof Tool) {
                throw new NoSuchToolException($toolCall->name);
            }

            return new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                $this->executeTool($tool, $toolCall->arguments),
                $toolCall->resultId,
            );
        }, $toolCalls);
    }

    /**
     * Drop unexecuted `tool_calls` from a trailing assistant message (step
     * budget exhausted); Moonshot rejects assistant `tool_calls` rows that
     * have no matching `tool` responses.
     *
     * @param  array<int, Message>  $messages
     * @return array<int, Message>
     */
    private function withoutTrailingUnansweredToolCalls(array $messages): array
    {
        $last = end($messages);

        if (! $last instanceof AssistantMessage || $last->toolCalls->isEmpty()) {
            return $messages;
        }

        array_pop($messages);

        if (filled($last->content)) {
            $messages[] = new AssistantMessage($last->content, null, $last->providerContentBlocks);
        }

        return $messages;
    }
}
