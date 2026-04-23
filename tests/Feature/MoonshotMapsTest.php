<?php

declare(strict_types=1);

use Jonaspauleta\PrismMoonshot\Maps\FinishReasonMap;
use Jonaspauleta\PrismMoonshot\Maps\MessageMap;
use Jonaspauleta\PrismMoonshot\Maps\ToolChoiceMap;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

it('maps finish reasons to FinishReason enum', function (string $raw, FinishReason $expected): void {
    expect(FinishReasonMap::map($raw))->toBe($expected);
})->with([
    ['stop', FinishReason::Stop],
    ['tool_calls', FinishReason::ToolCalls],
    ['length', FinishReason::Length],
    ['content_filter', FinishReason::ContentFilter],
    ['', FinishReason::Unknown],
    ['unrecognized', FinishReason::Unknown],
]);

it('maps tool choice enum and named function', function (): void {
    expect(ToolChoiceMap::map(ToolChoice::Auto))->toBe('auto');
    expect(ToolChoiceMap::map(ToolChoice::Any))->toBe('required');
    expect(ToolChoiceMap::map(ToolChoice::None))->toBe('none');
    expect(ToolChoiceMap::map(null))->toBeNull();
    expect(ToolChoiceMap::map('lookup'))->toBe([
        'type' => 'function',
        'function' => ['name' => 'lookup'],
    ]);
});

it('maps system, user, assistant, and tool messages to OpenAI-compatible payloads', function (): void {
    $systemPrompts = [new SystemMessage('You are concise.')];

    $messages = [
        new UserMessage('Hi'),
        new AssistantMessage('Hello!', [new ToolCall('call_1', 'lookup', ['q' => 'rain'])]),
        new ToolResultMessage([new ToolResult('call_1', 'lookup', ['q' => 'rain'], 'sunny')]),
    ];

    $mapped = (new MessageMap($messages, $systemPrompts))();

    expect($mapped)->toHaveCount(4);

    expect($mapped[0])->toBe([
        'role' => 'system',
        'content' => 'You are concise.',
    ]);

    expect($mapped[1]['role'])->toBe('user');
    expect($mapped[1]['content'][0])->toBe(['type' => 'text', 'text' => 'Hi']);

    expect($mapped[2]['role'])->toBe('assistant');
    expect($mapped[2]['content'])->toBe('Hello!');
    expect($mapped[2]['tool_calls'][0]['function']['name'])->toBe('lookup');
    expect($mapped[2]['tool_calls'][0]['function']['arguments'])->toBe('{"q":"rain"}');

    expect($mapped[3])->toBe([
        'role' => 'tool',
        'tool_call_id' => 'call_1',
        'content' => 'sunny',
    ]);
});
