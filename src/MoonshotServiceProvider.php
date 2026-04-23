<?php

declare(strict_types=1);

namespace Jonaspauleta\PrismMoonshot;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Jonaspauleta\PrismMoonshot\LaravelAi\MoonshotGateway;
use Jonaspauleta\PrismMoonshot\LaravelAi\MoonshotProvider;
use Laravel\Ai\AiManager;
use Laravel\Ai\Gateway\Prism\PrismGateway;
use Prism\Prism\PrismManager;
use ReflectionMethod;
use ReflectionUnionType;

final class MoonshotServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerWithPrism();
        $this->registerWithLaravelAi();
    }

    /**
     * Register the Moonshot provider with Prism's PrismManager.
     *
     * Enables: Prism::text()->using('moonshot', $model)->asText()
     */
    private function registerWithPrism(): void
    {
        $this->app->extend(PrismManager::class, function (PrismManager $manager): PrismManager {
            $manager->extend(Moonshot::KEY, fn (Application $app, array $config): Moonshot => new Moonshot(
                apiKey: $config['api_key'] ?? $config['key'] ?? '',
                url: $config['url'] ?? Moonshot::DEFAULT_URL,
            ));

            return $manager;
        });
    }

    /**
     * Register the Moonshot driver with Laravel AI SDK's AiManager.
     *
     * Enables: agent()->prompt('Hello', provider: 'moonshot')
     *
     * Falls back gracefully when laravel/ai is not installed or when its
     * Prism gateway has been removed/replaced upstream.
     *
     * @see https://github.com/laravel/ai/issues/283
     */
    private function registerWithLaravelAi(): void
    {
        if (! class_exists(AiManager::class)) {
            return;
        }

        if (! class_exists(PrismGateway::class)) {
            Log::warning(
                'jonaspauleta/prism-moonshot: Laravel AI SDK\'s PrismGateway has been removed. '
                .'Moonshot integration via agent() is disabled until a direct gateway is available. '
                .'Prism standalone (Prism::text()->using("moonshot", ...)) still works.',
            );

            return;
        }

        $useOverride = $this->needsGatewayOverride();

        $this->app->afterResolving(AiManager::class, function (AiManager $manager) use ($useOverride): void {
            $manager->extend(Moonshot::KEY, function (Application $app, array $config) use ($useOverride): MoonshotProvider {
                $gateway = $useOverride
                    ? new MoonshotGateway($app->make(Dispatcher::class))
                    : new PrismGateway($app->make(Dispatcher::class));

                return new MoonshotProvider(
                    $gateway,
                    $config,
                    $app->make(Dispatcher::class),
                );
            });
        });
    }

    /**
     * Detect whether `PrismGateway::toPrismProvider()` still requires a gateway
     * override. Once the upstream return type widens to `PrismProvider|string`,
     * the standard PrismGateway can resolve a string driver name on its own.
     */
    private function needsGatewayOverride(): bool
    {
        if (! class_exists(PrismGateway::class)) {
            return false;
        }

        $method = new ReflectionMethod(PrismGateway::class, 'toPrismProvider');

        return ! ($method->getReturnType() instanceof ReflectionUnionType);
    }
}
