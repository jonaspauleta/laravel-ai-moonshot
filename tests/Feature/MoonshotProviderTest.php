<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Jonaspauleta\PrismMoonshot\Moonshot;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('registers the Moonshot driver with Prism', function (): void {
    $manager = $this->app->make(PrismManager::class);

    $instance = $manager->resolve(Moonshot::KEY, [
        'api_key' => 'test-key',
        'url' => Moonshot::DEFAULT_URL,
    ]);

    expect($instance)->toBeInstanceOf(Moonshot::class);
    expect($instance->apiKey)->toBe('test-key');
    expect($instance->url)->toBe(Moonshot::DEFAULT_URL);
});

it('generates text from a successful chat completion', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/chat/completions' => Http::response([
            'id' => 'cmpl-test-1',
            'object' => 'chat.completion',
            'created' => 1700000000,
            'model' => 'kimi-k2.6',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello from Kimi.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5, 'total_tokens' => 17],
        ]),
    ]);

    $response = Prism::text()
        ->using(Moonshot::KEY, 'kimi-k2.6')
        ->withPrompt('Say hello.')
        ->asText();

    expect($response->text)->toBe('Hello from Kimi.');
    expect($response->finishReason)->toBe(FinishReason::Stop);
    expect($response->usage->promptTokens)->toBe(12);
    expect($response->usage->completionTokens)->toBe(5);
    expect($response->meta->model)->toBe('kimi-k2.6');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $request->url() === 'https://api.moonshot.ai/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $body['model'] === 'kimi-k2.6'
            && $body['messages'][0]['role'] === 'user';
    });
});

it('throws PrismRateLimitedException on HTTP 429', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/chat/completions' => Http::response([
            'error' => ['type' => 'rate_limit_exceeded', 'message' => 'Too many requests'],
        ], 429),
    ]);

    Prism::text()
        ->using(Moonshot::KEY, 'kimi-k2.6')
        ->withPrompt('Hi.')
        ->asText();
})->throws(PrismRateLimitedException::class);

it('throws PrismException with provider details on non-429 errors', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/chat/completions' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'message' => 'Invalid model'],
        ], 400),
    ]);

    Prism::text()
        ->using(Moonshot::KEY, 'kimi-k2.6')
        ->withPrompt('Hi.')
        ->asText();
})->throws(PrismException::class);
