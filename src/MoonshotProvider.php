<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Concerns\StreamsText;
use Laravel\Ai\Providers\Provider;

/**
 * Laravel AI SDK provider for Moonshot AI (Kimi).
 *
 * Defaults map to the Kimi model family. Override via the consumer's
 * `config/ai.php` `providers.moonshot.models.text.{default,cheapest,smartest}` keys.
 */
final class MoonshotProvider extends Provider implements TextProvider
{
    use GeneratesText;
    use HasTextGateway;
    use StreamsText;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config, protected Dispatcher $events)
    {
        //
    }

    public function textGateway(): TextGateway
    {
        return $this->textGateway ??= new MoonshotGateway($this->events);
    }

    public function defaultTextModel(): string
    {
        return $this->configuredModel('default', 'kimi-k2.6');
    }

    public function cheapestTextModel(): string
    {
        return $this->configuredModel('cheapest', 'kimi-k2.5');
    }

    public function smartestTextModel(): string
    {
        return $this->configuredModel('smartest', 'kimi-k2.6');
    }

    private function configuredModel(string $tier, string $default): string
    {
        $models = $this->config['models'] ?? null;

        if (! is_array($models)) {
            return $default;
        }

        $text = $models['text'] ?? null;

        if (! is_array($text)) {
            return $default;
        }

        $value = $text[$tier] ?? null;

        return is_string($value) ? $value : $default;
    }
}
