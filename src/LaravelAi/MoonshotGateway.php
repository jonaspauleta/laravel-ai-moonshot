<?php

declare(strict_types=1);

namespace Jonaspauleta\PrismMoonshot\LaravelAi;

use Jonaspauleta\PrismMoonshot\Moonshot;
use Laravel\Ai\Gateway\Prism\PrismGateway;
use Laravel\Ai\Providers\Provider;
use Override;

/**
 * Bridges Laravel AI SDK's PrismGateway to the custom Moonshot Prism provider.
 *
 * Overrides `configure()` to bypass `toPrismProvider()`, which only knows about
 * the Prism-builtin providers. When the upstream method's return type widens to
 * `PrismProvider|string`, this override becomes unnecessary — see the reflection
 * check in `MoonshotServiceProvider::needsGatewayOverride()`.
 *
 * @see https://github.com/laravel/ai/issues/283
 */
final class MoonshotGateway extends PrismGateway
{
    /**
     * @param  mixed  $prism  Pending Prism request (text, structured, or embeddings builder)
     */
    #[Override]
    protected function configure($prism, Provider $provider, string $model): mixed
    {
        if ($provider->driver() === Moonshot::KEY) {
            return $prism->using(
                Moonshot::KEY,
                $model,
                array_filter([
                    ...$provider->additionalConfiguration(),
                    'api_key' => $provider->providerCredentials()['key'],
                ]),
            );
        }

        return parent::configure($prism, $provider, $model);
    }
}
