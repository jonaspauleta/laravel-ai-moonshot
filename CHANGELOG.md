# Changelog

All notable changes to `laravel-ai-moonshot` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-04-25

### Added

- `Jonaspauleta\LaravelAiMoonshot\Exceptions\UnsupportedProviderToolException` — thrown when a `Laravel\Ai\Providers\Tools\ProviderTool` subclass is passed to the gateway. Replaces a raw `RuntimeException` with an actionable, type-narrow message.
- `Jonaspauleta\LaravelAiMoonshot\Exceptions\UnsupportedAttachmentException` (`::for()`, `::document()`) — thrown for unsupported attachment types and document attachments. Replaces raw `InvalidArgumentException`s.
- CI matrix: PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 on every push and pull request.
- Repository hygiene: `CODE_OF_CONDUCT.md`, GitHub issue forms (bug + feature + config), pull-request template, Dependabot config (composer + github-actions, weekly).

### Changed

- Lowered `php` constraint from `^8.5` to `^8.3` to match upstream `laravel/ai` and `illuminate/*`. PHP 8.5 was over-constrained for a library; CI exercises 8.3, 8.4, and 8.5.

### Fixed

- Removed a `deepseek-reasoner`-specific comment that leaked from the upstream `DeepSeekGateway` template into `ParsesTextResponses::processResponse()`. The replacement text describes Moonshot's actual non-streaming `reasoning_content` behavior.

## [1.0.0] - 2026-04-24

### Changed

- **Breaking**: renamed from `jonaspauleta/prism-moonshot` to `jonaspauleta/laravel-ai-moonshot`.
- **Breaking**: renamed root namespace from `Jonaspauleta\PrismMoonshot` to `Jonaspauleta\LaravelAiMoonshot`.
- **Breaking**: dropped Prism PHP dependency. Package now targets Laravel AI SDK (`laravel/ai ^0.6.3`) directly.
- `MoonshotGateway` is now a native Laravel AI SDK `TextGateway` implementation using the OpenAI-compatible Moonshot chat-completions endpoint (modeled on `laravel/ai`'s `DeepSeekGateway`).
- Provider config now lives under `config('ai.providers.moonshot')` with `driver`, `name`, `key`, and optional `models.text.{default,cheapest,smartest}` keys.

### Added

- `reasoning_content` deltas surface as Laravel AI SDK `ReasoningStart` / `ReasoningDelta` / `ReasoningEnd` stream events. `ReasoningEnd` fires before the first `TextStart` when the model transitions out of thinking.
- `kimi-k2-thinking` reachable via `Provider::smartestTextModel()`.

### Removed

- Prism provider class (`Jonaspauleta\PrismMoonshot\Moonshot`) and all Prism `Handlers` (`Text`, `Structured`, `Stream`). Use the Laravel AI SDK `agent()` / `AiManager` entry points instead.
- `PrismGateway` bridge that extended the removed `Laravel\Ai\Gateway\Prism\PrismGateway`.
- Structured output and image input helpers (not supported by this package release — reopen via a Laravel AI SDK structured-output implementation).
- `Maps\ThinkingMap`. `providerOptions` arrays are merged verbatim into the request body by the gateway; callers are responsible for passing the correct Moonshot shape.

### Migration

1. Update `composer.json`:

   ```diff
   -  "jonaspauleta/prism-moonshot": "^0.2"
   +  "jonaspauleta/laravel-ai-moonshot": "^1.0"
   ```

2. Replace namespace imports:

   ```diff
   -use Jonaspauleta\PrismMoonshot\...
   +use Jonaspauleta\LaravelAiMoonshot\...
   ```

3. Move provider config from `config/prism.php` to `config/ai.php`:

   ```php
   // config/ai.php
   'providers' => [
       'moonshot' => [
           'driver' => 'moonshot',
           'name' => 'moonshot',
           'key' => env('MOONSHOT_API_KEY'),
       ],
   ],
   ```

[1.1.0]: https://github.com/jonaspauleta/laravel-ai-moonshot/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/jonaspauleta/laravel-ai-moonshot/releases/tag/v1.0.0

## [0.2.1] - 2026-04-24

### Added

- Dedicated `prism.stream_timeout` config key for the streaming HTTP client. When set, `Moonshot::stream()` applies it as both `timeout` and `connect_timeout`, overriding the default `prism.request_timeout`. Lets callers extend the ceiling for long `thinking`-mode streams without inflating the per-request timeout used by text/structured calls. No behavior change when unset.

[0.2.1]: https://github.com/jonaspauleta/laravel-ai-moonshot/releases/tag/v0.2.1

## [0.2.0] - 2026-04-24

### Added

- **Thinking mode** support via `withProviderOptions(['thinking' => ...])`. Accepts `true` (shorthand for `['type' => 'enabled']`), or the full Moonshot shape `['type' => 'enabled'|'disabled', 'keep' => 'all']`. The `keep: all` flag is `kimi-k2.6`-only and preserves reasoning across multi-turn conversations.
- New `Maps\ThinkingMap` value mapper with input validation for `type` and `keep`.
- Tests covering all thinking input forms + invalid-input rejection paths.
- Documented Kimi thinking schema in README with both Prism standalone and Laravel AI agent examples.

[0.2.0]: https://github.com/jonaspauleta/laravel-ai-moonshot/releases/tag/v0.2.0

## [0.1.0] - 2026-04-23

### Added

- Initial Moonshot AI (Kimi K2) provider for Prism PHP.
- Bridge to the Laravel AI SDK via `MoonshotGateway` (extends `PrismGateway`).
- Text, streaming, structured/JSON-mode, and tool-calling support.
- `reasoning_content` deltas surfaced as Prism `ThinkingEvent`s.
- Image input via OpenAI-compatible `image_url` content parts.
- Pest 4 + PHPStan level max + Pint + Rector quality pipeline.
- GitHub Actions workflow.

[Unreleased]: https://github.com/jonaspauleta/laravel-ai-moonshot/compare/v1.1.0...HEAD
[0.1.0]: https://github.com/jonaspauleta/laravel-ai-moonshot/releases/tag/v0.1.0
