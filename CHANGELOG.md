# Changelog

All notable changes to `laravel-ai-moonshot` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.0] - Unreleased

### Added

- **Moonshot Files API support** for server-side document extraction (PDF, DOC, XLSX, PPTX, code, plain text, EPUB, …). Mirrors Moonshot's official OpenAI-compatible Files endpoints under `/v1/files`.
- `Jonaspauleta\LaravelAiMoonshot\Files\MoonshotFiles` service — typed wrapper around `POST /v1/files` (multipart upload), `GET /v1/files`, `GET /v1/files/{id}`, `GET /v1/files/{id}/content` (returns plain text), and `DELETE /v1/files/{id}`. Accepts `string` paths, `Illuminate\Http\UploadedFile`, or `SplFileInfo`. Resolves via `app(MoonshotFiles::class)` or constructor injection.
- `Jonaspauleta\LaravelAiMoonshot\Files\MoonshotFile` readonly DTO and `MoonshotFilePurpose` backed enum (`FileExtract`, `Image`, `Video`, `Batch`). Only `FileExtract` is exposed publicly in v1.2.0; vision purposes remain handled through the chat gateway's image attachment contracts.
- `Jonaspauleta\LaravelAiMoonshot\Concerns\InjectsMoonshotFiles` trait. Adds `withMoonshotFile(string|UploadedFile|SplFileInfo, ?label)` to any agent class that already uses `Promptable`. Exposes a `moonshotFilesMiddleware()` closure (and a default `middleware()` implementation) that prepends each labelled `Document: <label>\n<content>` block to the user prompt before it reaches the gateway.
- `php artisan ai:moonshot:files` artisan command. Default invocation renders a table of uploaded files (id, filename, bytes, purpose, status, created_at). `--delete=<id>` (repeatable) deletes one or more files; honours `--no-interaction`.
- `Jonaspauleta\LaravelAiMoonshot\Exceptions\MoonshotFilesException` with typed static constructors (`uploadFailed`, `extractionFailed`, `notFound`, `quotaExceeded`, `unsupportedSource`, `fileTooLarge`). Translates Moonshot error codes (`invalid_request_error`, `rate_limit_reached_error`, `exceeded_current_quota_error`) into actionable messages.
- README "Document Q&A" section between the existing "Image attachments" and "Thinking mode" sections, with copy-pasteable trait + service examples, the 1,000-files / 100 MB / 10 GB limits, and a security note on prompt-injection mitigation via document labelling.
- Three new Troubleshooting rows for extraction failures, quota exhaustion, and the 100 MB upload guard.

### Notes on the Moonshot system-message pattern

Moonshot's official documentation injects extracted text as a `system` message. The Laravel AI SDK's `Laravel\Ai\Messages\MessageRole` enum has only `Assistant` / `User` / `ToolResult` cases — the `system` slot is reserved for the agent's `instructions()`. To stay within the SDK contract without forking it, this package prepends extracted content to the **user prompt** as a labelled `Document: <name>\n<content>` block. The label is what makes the prompt-injection mitigation work; treat document content as untrusted input.

## [1.1.2] - 2026-04-25

### Fixed

- `cheapest` and `smartest` default model IDs no longer pointed at non-existent Moonshot SKUs (`kimi-k2-0905-preview` and `kimi-k2-thinking`). Both have been removed from Moonshot's `/v1/models` catalog, so out-of-the-box `#[UseCheapestModel]` / `#[UseSmartestModel]` calls returned HTTP 400.

### Changed

- `cheapest` tier now defaults to `kimi-k2.5`. `smartest` tier now defaults to `kimi-k2.6` — there is no separate thinking SKU; thinking is enabled per-call via `providerOptions(['thinking' => ['type' => 'enabled']])`.
- Tightened `laravel/ai` constraint from `^0.6.3` to `~0.6.3`. The upstream SDK is on `0.x` and minor bumps may carry breaking changes; we now pin per-minor and re-publish on each upstream minor after a compatibility audit. See the new "Versioning" section in the README for the full policy.

### Added

- `php artisan ai:moonshot:models` artisan command. Hits `GET /v1/models` against the configured Moonshot account and renders a table (`id`, `context_length`, `supports_image_in`, `supports_video_in`, `supports_reasoning`). Pass `--json` for the raw response.
- Weekly `catalog-drift` GitHub Actions workflow. Polls `GET /v1/models` and opens a labelled issue if any default tier ID disappears from the live catalog. Skips gracefully on forks where the `MOONSHOT_API_KEY` secret is unset.
- `composer smoke` script (`bin/smoke.php`). Runs three live-API scenarios — one-shot prompt, streaming prompt, tool call — gated by the `MOONSHOT_API_KEY` env var. Not wired into default CI; intended for `workflow_dispatch` and pre-tag verification.

## [1.1.1] - 2026-04-25

### Added

- Test coverage for the new exception classes: `UnsupportedProviderToolException` (provider-tool path) and both `UnsupportedAttachmentException` paths (`::for()` for unknown types, `::document()` for non-image `File` subclasses).
- `composer.json` `support` section pointing at the GitHub issue tracker, source, and README. Surfaces helpful links on the Packagist package page.

## [1.1.0] - 2026-04-25

### Added

- `Jonaspauleta\LaravelAiMoonshot\Exceptions\UnsupportedProviderToolException` — thrown when a `Laravel\Ai\Providers\Tools\ProviderTool` subclass is passed to the gateway. Replaces a raw `RuntimeException` with an actionable, type-narrow message.
- `Jonaspauleta\LaravelAiMoonshot\Exceptions\UnsupportedAttachmentException` (`::for()`, `::document()`) — thrown for unsupported attachment types and document attachments. Replaces raw `InvalidArgumentException`s.
- CI matrix: PHP 8.4 / 8.5 × Laravel 12 / 13 on every push and pull request.
- Repository hygiene: `CODE_OF_CONDUCT.md`, GitHub issue forms (bug + feature + config), pull-request template, Dependabot config (composer + github-actions, weekly).

### Changed

- Lowered `php` constraint from `^8.5` to `^8.4`. PHP 8.5 was over-constrained for a library; CI exercises 8.4 and 8.5.

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

[1.1.2]: https://github.com/jonaspauleta/laravel-ai-moonshot/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/jonaspauleta/laravel-ai-moonshot/compare/v1.1.0...v1.1.1
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

[Unreleased]: https://github.com/jonaspauleta/laravel-ai-moonshot/compare/v1.1.2...HEAD
[0.1.0]: https://github.com/jonaspauleta/laravel-ai-moonshot/releases/tag/v0.1.0
