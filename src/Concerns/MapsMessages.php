<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Concerns;

use Illuminate\Support\Collection;
use Jonaspauleta\LaravelAiMoonshot\Messages\KimiAssistantMessage;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

trait MapsMessages
{
    /**
     * Map the given Laravel messages to Chat Completions messages format.
     *
     * @param  array<mixed>  $messages
     * @return array<int, array<string, mixed>>
     */
    protected function mapMessagesToChat(array $messages, ?string $instructions = null): array
    {
        /** @var array<int, array<string, mixed>> $chatMessages */
        $chatMessages = [];

        if (filled($instructions)) {
            $chatMessages[] = [
                'role' => 'system',
                'content' => $instructions,
            ];
        }

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            match ($message->role) {
                MessageRole::User => $this->mapUserMessage($message, $chatMessages),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $chatMessages),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $chatMessages),
            };
        }

        return $chatMessages;
    }

    /**
     * Map a user message to Chat Completions format.
     *
     * @param  array<int, array<string, mixed>>  $chatMessages
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$chatMessages): void
    {
        if (! $message instanceof UserMessage || $message->attachments->isEmpty()) {
            $chatMessages[] = [
                'role' => 'user',
                'content' => $message->content,
            ];

            return;
        }

        $chatMessages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $message->content],
                ...$this->mapAttachments($message->attachments),
            ],
        ];
    }

    /**
     * Map an assistant message to Chat Completions format.
     *
     * @param  array<int, array<string, mixed>>  $chatMessages
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$chatMessages): void
    {
        /** @var array<string, mixed> $msg */
        $msg = ['role' => 'assistant'];

        if (filled($message->content)) {
            $msg['content'] = $message->content;
        }

        // KimiAssistantMessage carries reasoning_content captured from a prior
        // turn. Echo it back so Moonshot's `thinking.keep: all` is happy —
        // otherwise the API 400s with `reasoning_content is missing in
        // assistant tool call message`.
        if ($message instanceof KimiAssistantMessage && filled($message->reasoningContent)) {
            $msg['reasoning_content'] = $message->reasoningContent;
        } elseif ($message instanceof AssistantMessage) {
            $reasoning = $this->reasoningContentFromProviderBlocks($message->providerContentBlocks);

            if (is_string($reasoning) && filled($reasoning)) {
                $msg['reasoning_content'] = $reasoning;
            }
        }

        if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
            /** @var Collection<int, ToolCall> $toolCalls */
            $toolCalls = $message->toolCalls;
            $msg['tool_calls'] = $toolCalls->map(
                fn (ToolCall $toolCall): array => $this->serializeToolCallToChat($toolCall)
            )->all();
        }

        $chatMessages[] = $msg;
    }

    protected function reasoningContentFromProviderBlocks(mixed $blocks): ?string
    {
        if (! is_array($blocks)) {
            return null;
        }

        $reasoning = $blocks['reasoning_content'] ?? null;

        if (is_string($reasoning)) {
            return $reasoning;
        }

        foreach ($blocks as $block) {
            if (is_array($block)
                && ($block['type'] ?? null) === 'reasoning_content'
                && is_string($block['reasoning_content'] ?? null)) {
                return $block['reasoning_content'];
            }
        }

        return null;
    }

    /**
     * Map a tool result message to Chat Completions format.
     *
     * @param  array<int, array<string, mixed>>  $chatMessages
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$chatMessages): void
    {
        if (! $message instanceof ToolResultMessage) {
            return;
        }

        /** @var Collection<int, ToolResult> $toolResults */
        $toolResults = $message->toolResults;

        foreach ($toolResults as $toolResult) {
            $chatMessages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolResult->resultId ?? $toolResult->id,
                'content' => $this->serializeToolResultOutput($toolResult->result),
            ];
        }
    }

    /**
     * Serialize a tool call DTO to Chat Completions array format.
     *
     * @return array<string, mixed>
     */
    protected function serializeToolCallToChat(ToolCall $toolCall): array
    {
        return [
            'id' => $toolCall->resultId ?? $toolCall->id,
            'type' => 'function',
            'function' => [
                'name' => $toolCall->name,
                'arguments' => json_encode($toolCall->arguments ?: (object) []),
            ],
        ];
    }

    /**
     * Serialize a tool result output value to a string.
     */
    protected function serializeToolResultOutput(mixed $output): string
    {
        if (is_string($output)) {
            return $output;
        }

        if (is_array($output)) {
            return (string) json_encode($output);
        }

        if (is_scalar($output) || $output === null) {
            return strval($output);
        }

        return (string) json_encode($output);
    }
}
