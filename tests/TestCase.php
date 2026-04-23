<?php

declare(strict_types=1);

namespace Jonaspauleta\PrismMoonshot\Tests;

use Jonaspauleta\PrismMoonshot\MoonshotServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Prism\Prism\PrismServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PrismServiceProvider::class,
            MoonshotServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('prism.providers.moonshot', [
            'api_key' => env('MOONSHOT_API_KEY', 'test-key'),
            'url' => 'https://api.moonshot.ai/v1',
        ]);
    }
}
