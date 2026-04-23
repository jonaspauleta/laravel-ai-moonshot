<?php

declare(strict_types=1);

use Jonaspauleta\PrismMoonshot\LaravelAi\MoonshotProvider;
use Jonaspauleta\PrismMoonshot\Moonshot;
use Laravel\Ai\AiManager;
use Laravel\Ai\AiServiceProvider;

beforeEach(function (): void {
    app()->register(AiServiceProvider::class);

    config()->set('ai.providers.moonshot', [
        'driver' => Moonshot::KEY,
        'key' => 'test-key',
        'url' => Moonshot::DEFAULT_URL,
    ]);
});

it('registers the Moonshot driver with the Laravel AI manager', function (): void {
    /** @var AiManager $manager */
    $manager = resolve(AiManager::class);

    $provider = $manager->textProvider(Moonshot::KEY);

    expect($provider)->toBeInstanceOf(MoonshotProvider::class);

    /** @var MoonshotProvider $provider */
    expect($provider->driver())->toBe(Moonshot::KEY);
});

it('exposes Kimi defaults for tiered model resolution', function (): void {
    /** @var AiManager $manager */
    $manager = resolve(AiManager::class);

    $provider = $manager->textProvider(Moonshot::KEY);

    expect($provider->defaultTextModel())->toBe('kimi-k2.6');
    expect($provider->cheapestTextModel())->toBe('kimi-k2-0905-preview');
    expect($provider->smartestTextModel())->toBe('kimi-k2-thinking');
});

it('honours overridden model defaults from config', function (): void {
    config()->set('ai.providers.moonshot.models.text', [
        'default' => 'kimi-k2-turbo-preview',
        'cheapest' => 'kimi-k2-0711-preview',
        'smartest' => 'kimi-k2-thinking-turbo',
    ]);

    /** @var AiManager $manager */
    $manager = resolve(AiManager::class);

    $provider = $manager->textProvider(Moonshot::KEY);

    expect($provider->defaultTextModel())->toBe('kimi-k2-turbo-preview');
    expect($provider->cheapestTextModel())->toBe('kimi-k2-0711-preview');
    expect($provider->smartestTextModel())->toBe('kimi-k2-thinking-turbo');
});
