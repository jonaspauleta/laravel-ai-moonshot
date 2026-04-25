<?php

declare(strict_types=1);

use Jonaspauleta\LaravelAiMoonshot\MoonshotProvider;
use Laravel\Ai\AiManager;

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
