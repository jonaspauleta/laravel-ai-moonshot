<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Jonaspauleta\LaravelAiMoonshot\Console\Commands\ListFilesCommand;
use Jonaspauleta\LaravelAiMoonshot\Console\Commands\ListModelsCommand;
use Jonaspauleta\LaravelAiMoonshot\Files\MoonshotFiles;
use Laravel\Ai\AiManager;
use Override;

final class MoonshotServiceProvider extends ServiceProvider
{
    public const string KEY = 'moonshot';

    #[Override]
    public function register(): void
    {
        $this->app->singleton(MoonshotFiles::class, function (Application $app): MoonshotFiles {
            $raw = config('ai.providers.moonshot');
            $config = is_array($raw) ? $raw : [];

            $key = is_string($config['key'] ?? null) ? $config['key'] : '';
            $url = is_string($config['url'] ?? null) ? $config['url'] : 'https://api.moonshot.ai/v1';

            return new MoonshotFiles(
                apiKey: $key,
                baseUrl: $url,
                http: $app->make(HttpFactory::class),
            );
        });
    }

    public function boot(): void
    {
        $this->app->afterResolving(AiManager::class, function (AiManager $manager): void {
            $manager->extend(self::KEY, function (Application $app, array $config): MoonshotProvider {
                /** @var array<string, mixed> $config */
                return new MoonshotProvider(
                    $config,
                    $app->make(Dispatcher::class),
                );
            });
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                ListModelsCommand::class,
                ListFilesCommand::class,
            ]);
        }
    }
}
