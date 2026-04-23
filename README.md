# prism-moonshot

[Moonshot AI](https://platform.kimi.ai/) (Kimi K2) provider for [Prism PHP](https://github.com/prism-php/prism) and the [Laravel AI SDK](https://github.com/laravel/ai). Same package, two integration points: `Prism::text()->using('moonshot', ...)` standalone, and `agent()->prompt(..., provider: 'moonshot')` through Laravel AI.

Moonshot's API is OpenAI-compatible (`POST https://api.moonshot.ai/v1/chat/completions`), so this driver supports text generation, streaming, structured/JSON-mode output, tool calling, and reasoning content (`reasoning_content` deltas surfaced as `ThinkingEvent`s).

## Requirements

- PHP 8.5+
- `prism-php/prism` ^0.99 || ^0.100
- (optional) `laravel/ai` ^0.3 || ^0.4 || ^0.5 — for agent integration

## Install

```bash
composer require jonaspauleta/prism-moonshot
```

The service provider auto-registers via Laravel package discovery.

## Configure

Set the API key:

```env
MOONSHOT_API_KEY=sk-...
```

### Prism

In `config/prism.php`:

```php
'providers' => [
    // ...
    'moonshot' => [
        'api_key' => env('MOONSHOT_API_KEY'),
        'url' => env('MOONSHOT_URL', 'https://api.moonshot.ai/v1'),
    ],
],
```

### Laravel AI SDK

In `config/ai.php`:

```php
'providers' => [
    // ...
    'moonshot' => [
        'driver' => 'moonshot',
        'key' => env('MOONSHOT_API_KEY'),
        'url' => env('MOONSHOT_URL', 'https://api.moonshot.ai/v1'),
        'models' => [
            'text' => [
                'default' => 'kimi-k2.6',
                'cheapest' => 'kimi-k2-0905-preview',
                'smartest' => 'kimi-k2-thinking',
            ],
        ],
    ],
],
```

## Use it

### Prism (standalone)

```php
use Prism\Prism\Prism;

$response = Prism::text()
    ->using('moonshot', 'kimi-k2.6')
    ->withPrompt('Explain trail braking in two sentences.')
    ->asText();

echo $response->text;
```

Streaming:

```php
foreach (Prism::text()->using('moonshot', 'kimi-k2.6')->withPrompt('Tell me a story.')->asStream() as $event) {
    // TextStartEvent, TextDeltaEvent, TextCompleteEvent, ToolCallEvent, ThinkingEvent, ...
}
```

Structured / JSON mode:

```php
$response = Prism::structured()
    ->using('moonshot', 'kimi-k2.6')
    ->withSchema($schema)
    ->withPrompt('Return the front and rear tyre pressures for a Porsche GT3.')
    ->asStructured();
```

### Laravel AI SDK (agents)

```php
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Provider('moonshot')]
final class TrackGuide implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a Spa-Francorchamps coach.';
    }
}

$response = (new TrackGuide)->prompt('What gear for Eau Rouge?');
```

## Supported models

Moonshot exposes both the **Kimi K2** family and the older **Moonshot V1** family. Defaults in the AI SDK driver target the K2 line:

| Tier      | Default model              | Notes                              |
|-----------|----------------------------|------------------------------------|
| default   | `kimi-k2.6`                | Latest general-purpose K2 release  |
| cheapest  | `kimi-k2-0905-preview`     | Earlier K2 snapshot                |
| smartest  | `kimi-k2-thinking`         | Reasoning-tuned variant            |

Other supported model identifiers from Moonshot's docs:
`kimi-k2.5`, `kimi-k2-0711-preview`, `kimi-k2-turbo-preview`, `kimi-k2-thinking-turbo`, `moonshot-v1-8k`, `moonshot-v1-32k`, `moonshot-v1-128k`, `moonshot-v1-auto`, `moonshot-v1-{8k,32k,128k}-vision-preview`.

> **Note:** Moonshot updates model IDs frequently. Verify the exact identifier by hitting `GET https://api.moonshot.ai/v1/models` with your API key, or by checking the [official model list](https://platform.kimi.ai/docs/api/overview). The defaults baked into this package reflect public docs at release time and are easy to override via the `models` config block.

## What's supported

- ✅ Text generation (`text()`)
- ✅ Streaming (`stream()`) — including `reasoning_content` deltas as `ThinkingEvent`s
- ✅ Structured output (`structured()`) — JSON mode via `response_format: json_object`
- ✅ Tool / function calling
- ✅ Vision input (image URL or base64) via OpenAI-compatible content array
- ❌ Embeddings — Moonshot has no public embeddings endpoint
- ❌ File uploads / context caching — not exposed in v1

## How it bridges to the Laravel AI SDK

The Laravel AI SDK ships a `PrismGateway` that maps its drivers to Prism's built-in `PrismProvider` enum. Because `moonshot` is not in that enum, this package's `MoonshotGateway` extends `PrismGateway` and overrides `configure()` to pass the driver name as a string to `Prism::using()`.

A reflection probe in `MoonshotServiceProvider::needsGatewayOverride()` skips the override automatically once the upstream `toPrismProvider()` return type widens to `PrismProvider|string` (tracked in [laravel/ai#283](https://github.com/laravel/ai/issues/283)).

## Development

```bash
composer install
composer quality   # rector + pint + phpstan + pest
```

## License

MIT — see [LICENSE.md](LICENSE.md).

## Credits

- Inspired by [meirdick/prism-workers-ai](https://github.com/meirdick/prism-workers-ai) for the dual-registration pattern.
- Built on top of [prism-php/prism](https://github.com/prism-php/prism) and [laravel/ai](https://github.com/laravel/ai).
