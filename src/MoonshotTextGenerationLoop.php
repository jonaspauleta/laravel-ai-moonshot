<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot;

use Generator;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\TextResponse;
use Override;

final class MoonshotTextGenerationLoop extends TextGenerationLoop
{
    private ?int $timeout = null;

    public function __construct(
        private readonly MoonshotGateway $moonshotGateway,
        private readonly Provider $provider,
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

        try {
            return parent::generate($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout);
        } finally {
            $this->timeout = null;
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
}
