# Changelog

All notable changes to `laravel-ai-moonshot` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-05-07

### Added

- Formula tool support for `Convert` and `Fetch`. Both classes now extend the new
  `MoonshotFormulaTool` abstract base (instead of bare `ProviderTool`). When either
  is passed to an Agent's tool list the gateway fetches live function tool definitions
  from `GET /formulas/<uri>/tools` and registers them with the chat completions `tools`
  array as ordinary `{type: "function", ...}` entries. When the model emits a tool_call
  for one of those names the gateway executes it via `POST /formulas/<uri>/fibers` and
  forwards the `context.output` string back as the ToolResult content. Works for both
  non-streaming and streaming paths.
- New `src/Providers/Tools/MoonshotFormulaTool.php` abstract base class. Subclasses
  declare `formulaUri(): string` to identify the Moonshot Formulas API resource (e.g.
  `"moonshot/convert:latest"`). Ready to be extended for future Moonshot formula tools
  (memory, code_runner, etc.) without any gateway changes.
- New `src/Concerns/ResolvesFormulaTools.php` trait added to `MoonshotGateway`. Provides
  per-request in-memory caching of formula tool definitions (prevents duplicate GETs),
  a name → URI registry for tool call dispatch, and the `fetchFormulaToolDefinitions` /
  `executeFormulaTool` helpers.

### Changed

- `Convert` and `Fetch` now extend `MoonshotFormulaTool` instead of `ProviderTool`
  directly. Agent authors using `new Convert` / `new Fetch` will see no API change,
  but the wire format changes: tool calls are dispatched via the Formulas fibers
  endpoint instead of being echoed back as builtin_function results.
  **BC break for direct subclassers of `Convert` / `Fetch`** — if you subclassed
  either class, extend `MoonshotFormulaTool` and implement `formulaUri()` instead.
- `MapsTools::mapTools()` now requires a `Provider $provider` and optional
  `?int $timeout` argument (previously just `array $tools`). Internal callers
  (including test helpers using Closure rebinding) must be updated.
- `ParsesTextResponses::executeToolCalls()` now requires `Provider $provider` and
  optional `?int $timeout` for formula tool dispatch.
- Removed `MOONSHOT_CONVERT` and `MOONSHOT_FETCH` constants from `MapsTools`.
  Only `MOONSHOT_WEB_SEARCH` remains.
- `moonshotBuiltinNames()` now returns only `['$web_search']`; `$convert` and
  `$fetch` are no longer in the list.
- Formula tool state is reset at the start of every `generateText()` and
  `streamText()` call, making the gateway safe under Octane's shared-instance model.

## [1.1.0] - 2026-05-07

### Added

- Support Moonshot's server-side `$convert` and `$fetch` builtins. New
  `Jonaspauleta\LaravelAiMoonshot\Providers\Tools\Convert` and `Fetch`
  ProviderTool subclasses are mapped to
  `{"type":"builtin_function","function":{"name":"$convert"}}` and `"$fetch"`
  respectively, mirroring the existing `$web_search` plumbing. Tool calls
  for these names are auto-replied with the model's own arguments per the
  Kimi protocol — Moonshot runs the actual conversion / fetch server-side.
  Works for both non-streaming and streaming paths.
  See https://platform.kimi.ai/docs/guide/use-official-tools.

### Changed

- Renamed protected helpers `mapBuiltinWebSearch()` → `mapBuiltinFunction(string $name)`
  and `buildBuiltinWebSearchResult()` → `buildBuiltinFunctionResult()` to
  reflect the generalised builtin handling. Subclassers calling these
  directly must update call sites.
- Introduced `moonshotBuiltinName(mixed $tool)` and `moonshotBuiltinNames()`
  protected helpers on the `MapsTools` trait — resolves the builtin function
  name for a ProviderTool, or returns the full list of recognised builtin
  names. Streaming and non-streaming paths share the same recognition logic.

## [1.0.3] - 2026-05-04

### Changed

- Apply rector (`ForeachToArrayFindRector`) and pint formatting fixes to
  the thinking-mode regression tests added in 1.0.2 so CI passes. Library
  behavior is identical to 1.0.2.

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
