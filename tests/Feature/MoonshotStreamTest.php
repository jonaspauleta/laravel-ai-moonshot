<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Jonaspauleta\PrismMoonshot\Moonshot;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('streams text deltas from an SSE response', function (): void {
    $sse = implode("\n", [
        'data: {"id":"x","model":"kimi-k2.6","choices":[{"index":0,"delta":{"role":"assistant"}}]}',
        '',
        'data: {"id":"x","model":"kimi-k2.6","choices":[{"index":0,"delta":{"content":"Hello"}}]}',
        '',
        'data: {"id":"x","model":"kimi-k2.6","choices":[{"index":0,"delta":{"content":" world"}}]}',
        '',
        'data: {"id":"x","model":"kimi-k2.6","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":3,"completion_tokens":2}}',
        '',
        'data: [DONE]',
        '',
    ]);

    Http::fake([
        'api.moonshot.ai/v1/chat/completions' => Http::response($sse, 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $events = [];
    foreach (Prism::text()->using(Moonshot::KEY, 'kimi-k2.6')->withPrompt('Hi')->asStream() as $event) {
        $events[] = $event;
    }

    $eventTypes = array_map(fn (StreamEvent $e): string => $e::class, $events);

    expect($eventTypes)->toContain(StreamStartEvent::class);
    expect($eventTypes)->toContain(TextStartEvent::class);
    expect($eventTypes)->toContain(TextDeltaEvent::class);
    expect($eventTypes)->toContain(TextCompleteEvent::class);
    expect($eventTypes)->toContain(StreamEndEvent::class);

    $deltas = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof TextDeltaEvent));
    $text = implode('', array_map(fn (TextDeltaEvent $e): string => $e->delta, $deltas));

    expect($text)->toBe('Hello world');
});
