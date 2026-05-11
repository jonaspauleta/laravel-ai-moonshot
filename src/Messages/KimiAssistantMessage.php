<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Messages;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\Data\ToolCall;

/**
 * Assistant message variant that carries Kimi's `reasoning_content` so it can
 * be echoed back on follow-up requests when `thinking.keep: all` is enabled.
 *
 * Use this in place of `AssistantMessage` whenever you replay a stored
 * conversation history that contains tool calls — Moonshot rejects the request
 * with a 400 `reasoning_content is missing in assistant tool call message`
 * otherwise.
 *
 * @see https://platform.kimi.ai/docs/api/chat#body-one-of-0-thinking
 */
final class KimiAssistantMessage extends AssistantMessage
{
    /**
     * @param  Collection<int, ToolCall>|null  $toolCalls
     */
    public function __construct(
        string $content,
        public string $reasoningContent = '',
        ?Collection $toolCalls = null,
    ) {
        parent::__construct($content, $toolCalls);
    }
}
