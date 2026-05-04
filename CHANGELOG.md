# Changelog

All notable changes to `laravel-ai-moonshot` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - 2026-05-04

### Fixed

- Echo the assistant's `reasoning_content` back on tool-call follow-up
  requests when Kimi `thinking` mode is enabled. Without this, multi-turn
  flows that involve any tool call (including the built-in `$web_search`)
  failed with `400 invalid_request_error: thinking is enabled but
  reasoning_content is missing in assistant tool call message at index N`.
  The fix accumulates `reasoning_content` deltas during streaming (and
  reads `message.reasoning_content` from non-streaming responses), then
  attaches it to the assistant message that holds the `tool_calls` on the
  follow-up POST. No-op when `thinking` is disabled or no reasoning was
  produced.

## [1.0.1] - 2026-04-27

### Added

- Support Moonshot's server-side `$web_search` builtin. The SDK's generic
  `Laravel\Ai\Providers\Tools\WebSearch` ProviderTool is mapped to
  `{"type":"builtin_function","function":{"name":"$web_search"}}` and the
  resulting `$web_search` tool_calls are auto-replied with the model's own
  arguments (per the Kimi protocol — Moonshot runs the actual search
  server-side). Works for both non-streaming and streaming paths.
  See https://platform.kimi.ai/docs/guide/use-web-search.

### Notes

- `WebSearch` client-side knobs (`maxSearches`, `allowedDomains`, location
  fields) are silently dropped — Kimi exposes no client-side configuration
  for the builtin.
- All other `ProviderTool` subclasses (e.g. `WebFetch`, `FileSearch`)
  continue to throw `UnsupportedProviderToolException`.

[1.0.1]: https://github.com/jonaspauleta/laravel-ai-moonshot/releases/tag/v1.0.1

## [1.0.0] - 2026-04-25

Initial public release.

### Added

- Moonshot AI (Kimi K2) provider for the official Laravel AI SDK (`laravel/ai`).
  Wraps Moonshot's OpenAI-compatible chat-completions endpoint
  (`POST https://api.moonshot.ai/v1/chat/completions`) and registers a native
  `TextProvider` / `TextGateway` via `AiManager::extend('moonshot', …)`.
- Text generation through `agent()`, `Ai::textProvider('moonshot')`, and
  `#[Provider('moonshot')]` agent classes.
- Streaming responses (`stream()`, `broadcast()`, `broadcastOnQueue()`) with
  `TextStart` / `TextDelta` / `TextEnd` SDK events.
- Tool calling (function tools). Provider-side tools throw
  `UnsupportedProviderToolException` — Moonshot has no provider-hosted tools.
- Image attachments: `Base64Image`, `RemoteImage`, `LocalImage`, `StoredImage`,
  and `Illuminate\Http\UploadedFile` (when MIME is `image/jpeg|png|gif|webp`).
- Document Q&A via Moonshot Files API (`/v1/files`). Server-side text
  extraction (PDF, DOC, XLSX, PPTX, code, EPUB, …) via the `MoonshotFiles`
  service and the ergonomic `InjectsMoonshotFiles` trait
  (`withMoonshotFile()`).
- Kimi **thinking mode** with `reasoning_content` deltas surfaced as
  `ReasoningStart` / `ReasoningDelta` / `ReasoningEnd` stream events.
  `ReasoningEnd` is guaranteed to fire before the first `TextStart`. Enabled
  per-call via `providerOptions(['thinking' => ['type' => 'enabled']])`;
  `keep: 'all'` preserves reasoning across multi-turn conversations on
  `kimi-k2.6`.
- Per-tier model overrides through
  `config('ai.providers.moonshot.models.text.{default,cheapest,smartest}')`.
  Defaults track Moonshot's public catalog (`kimi-k2.6` for default/smartest,
  `kimi-k2.5` for cheapest).
- Custom base URL via `config('ai.providers.moonshot.url')` for proxies and
  regional endpoints.
- Artisan commands: `ai:moonshot:models` (live `/v1/models` catalog) and
  `ai:moonshot:files` (list / delete uploads).
- Typed exceptions: `UnsupportedProviderToolException`,
  `UnsupportedAttachmentException`, `MoonshotFilesException`.
- Quality pipeline: Pest 3 / 4, PHPStan level max (no baseline), Pint,
  Rector. CI matrix on PHP 8.4 / 8.5 × Laravel 12 / 13. Weekly
  `catalog-drift` workflow polls `/v1/models` and opens an issue if any
  default tier ID disappears from the live catalog.

[1.0.0]: https://github.com/jonaspauleta/laravel-ai-moonshot/releases/tag/v1.0.0
