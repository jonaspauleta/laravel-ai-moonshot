# prism-moonshot — Agent Guide

Moonshot AI (Kimi K2) provider for Prism PHP **and** Laravel AI SDK in one
package. Standalone Prism use works without `laravel/ai` installed; the AI SDK
bridge auto-disables itself in that case.

## Architecture

Two registration paths, one provider:

1. **Prism side** — `Jonaspauleta\PrismMoonshot\Moonshot` extends `Prism\Prism\Providers\Provider`. Wires `text()`, `stream()`, `structured()`, plus error mapping. Handlers and Maps mirror Prism's `DeepSeek` provider (Moonshot is OpenAI-compatible).
2. **Laravel AI SDK side** — `LaravelAi\MoonshotProvider extends Provider implements TextProvider`. Defers all HTTP work to `LaravelAi\MoonshotGateway`, which extends `PrismGateway` and overrides `configure()` to pass the `'moonshot'` driver string to `Prism::using()` (because `Lab` enum / `toPrismProvider()` doesn't know about us).

`MoonshotServiceProvider::needsGatewayOverride()` reflects on `PrismGateway::toPrismProvider()`'s return type — once the upstream PR widens it to `PrismProvider|string`, the override is skipped automatically.

## Common pitfalls

- **`provider()` in `ImageMapper` returns the string `'moonshot'`**, not a `Prism\Prism\Enums\Provider` case — that enum is closed and we are not in it. Don't change to enum form.
- **Constructor signature on `LaravelAi\MoonshotProvider` must match `Laravel\Ai\Providers\Provider`** — `(Gateway $gateway, array $config, Dispatcher $events)`. Don't swap to the DeepSeek-style 2-arg constructor; it breaks `AiManager`'s instantiation.
- **`MoonshotProvider::driver()` returns `$this->config['driver']`** — `MoonshotGateway::configure()` matches against `Moonshot::KEY` (`'moonshot'`). If someone configures `'driver' => 'kimi'`, the gateway won't match and the call goes through `parent::configure()`, which will then throw because `'kimi'` isn't in `toPrismProvider()`'s match. Document the canonical driver string.
- **Default model IDs (`kimi-k2.6`, etc.) come from Moonshot docs** — if Moonshot renames models, defaults rot silently and users get HTTP 400s. Always allow override via `models.text.{default,cheapest,smartest}` config.

## Do not

- Add embeddings — Moonshot has no embeddings endpoint as of release. Don't fake it via OpenAI's `/embeddings` route shape.
- Add a `Lab` enum case via reflection / runtime patch. The `#[Provider]` attribute already accepts `string`, so users do `#[Provider('moonshot')]`.
- Publish a `config/moonshot.php`. Configuration lives in the consumer's `config/prism.php` and `config/ai.php` — that's the convention both ecosystems expect.
- Bring in `spatie/laravel-package-tools` — there's nothing to publish (no migrations, no own config), so a vanilla `ServiceProvider` is enough.
- Mock `Http::fake()` against `chat/completions` without the `api.moonshot.ai/v1/` prefix — Prism's pending request has the base URL applied, so the fake key must be the full URL.
