<?php

declare(strict_types=1);

use Jonaspauleta\LaravelAiMoonshot\MoonshotProvider;
use Jonaspauleta\LaravelAiMoonshot\MoonshotTextGenerationLoop;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Messages\UserMessage;

it('registers the moonshot driver with the Laravel AI SDK AiManager', function (): void {
    $provider = resolve(AiManager::class)->textProvider('moonshot');

    expect($provider)->toBeInstanceOf(MoonshotProvider::class);
});

it('returns the kimi k2.6 default model', function (): void {
    $provider = resolve(AiManager::class)->textProvider('moonshot');

    expect($provider->defaultTextModel())->toBe('kimi-k2.6')
        ->and($provider->smartestTextModel())->toBe('kimi-k2.6')
        ->and($provider->cheapestTextModel())->toBe('kimi-k2.5');
});

it('uses the step gateway and Moonshot text generation loop', function (): void {
    $provider = resolve(AiManager::class)->textProvider('moonshot');
    assert($provider instanceof MoonshotProvider);

    expect($provider->textGateway())->toBeInstanceOf(StepTextGateway::class)
        ->and($provider->textGenerationLoop())->toBeInstanceOf(MoonshotTextGenerationLoop::class);
});

it('uses the standard generation loop for an injected fake gateway', function (): void {
    $provider = resolve(AiManager::class)->textProvider('moonshot');
    assert($provider instanceof MoonshotProvider);
    $provider->useTextGateway(new FakeTextGateway(['Fake response.']));

    expect($provider->textGenerationLoop())->toBeInstanceOf(TextGenerationLoop::class);
    expect($provider->textGenerationLoop())->not->toBeInstanceOf(MoonshotTextGenerationLoop::class);

    $response = $provider->textGenerationLoop()->generate(
        $provider,
        'kimi-k2.6',
        instructions: null,
        messages: [new UserMessage('Hello')],
    );

    expect($response->text)->toBe('Fake response.');
});
