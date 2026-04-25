# laravel-ai-moonshot — Agent Guide

Single-purpose Moonshot AI (Kimi K2) provider for the official Laravel AI SDK
(`laravel/ai`). Wraps Moonshot's OpenAI-compatible chat-completions endpoint
(`POST https://api.moonshot.ai/v1/chat/completions`).

## Architecture

One registration path. `MoonshotServiceProvider::boot()` calls
`AiManager::extend('moonshot', …)` (after-resolving), which returns a
`MoonshotProvider` (`extends Laravel\Ai\Providers\Provider implements TextProvider`).

`MoonshotProvider` uses three SDK traits (`GeneratesText`, `HasTextGateway`,
`StreamsText`) and lazily constructs a single `MoonshotGateway` instance.

`MoonshotGateway` (`implements Laravel\Ai\Contracts\Gateway\TextGateway`) is the
HTTP layer. Behavior is split across local traits in `src/Concerns/`, mirroring
how `Laravel\Ai\Gateway\DeepSeek\DeepSeekGateway` is composed:

- `BuildsTextRequests` — request body assembly. Composes instructions + schema,
  maps messages, maps tools, merges `providerOptions(driver)` verbatim into the
  body (this is how Kimi `thinking` payloads reach Moonshot).
- `CreatesMoonshotClient` — `Http::baseUrl(...)->withToken(...)->throw()`.
- `HandlesTextStreaming` — SSE chunk loop. Yields `StreamStart`, `Reasoning*`,
  `Text*`, `ToolCall`, `ToolResult`, `StreamEnd` from the SDK. Recurses on
  `finish_reason: tool_calls` up to `maxSteps`.
- `MapsAttachments`, `MapsMessages`, `MapsTools`, `ParsesTextResponses` —
  protocol shape conversion to/from OpenAI chat schema.

Plus three SDK-shipped traits: `HandlesFailoverErrors`, `InvokesTools`,
`ParsesServerSentEvents`.

## Type-safety

PHPStan runs at `level: max`, no baseline. Moonshot HTTP responses arrive as
untyped JSON, so every read is narrowed inline (`is_string($x['foo'] ?? null) ? $x['foo'] : ''`)
before use. Keep `mixed` quarantined; do not add `data_get()` calls in handler
code.

## Common pitfalls

- **Driver string is canonical `'moonshot'`** — `MoonshotServiceProvider::KEY`.
  Do not accept aliases like `'kimi'`. Tests rely on the exact match.
- **`Http::fake()` keys must include the base URL prefix**:
  `'api.moonshot.ai/v1/chat/completions' => Http::response(...)`. The pending
  request has the base URL applied; bare `chat/completions` will not match.
- **`ReasoningEnd` must fire before the first `TextStart`.** The streaming
  trait tracks `$reasoningStartEmitted` / `$reasoningEndEmitted` for this. If
  you refactor, keep the invariant; there is a feature test asserting the order.
- **Default model IDs come from Moonshot's public catalog.** As of v1.1.2:
  `default` and `smartest` map to `kimi-k2.6`; `cheapest` maps to `kimi-k2.5`.
  There is no separate "thinking" SKU — thinking is enabled via
  `providerOptions(['thinking' => ['type' => 'enabled']])`. If Moonshot
  retires one, defaults rot silently and users get HTTP 400. The weekly
  `catalog-drift` GitHub workflow polls `GET /v1/models` and opens an issue
  if any default disappears. Always allow override via
  `config/ai.php` → `providers.moonshot.models.text.{default,cheapest,smartest}`.
- **`MoonshotGateway::$events` is unused on purpose** (`@phpstan-ignore property.onlyWritten`).
  Kept for parity with upstream `DeepSeekGateway`. If you start emitting events,
  drop the ignore comment.

## Do not

- Add embeddings, image generation, audio, or transcription. Moonshot has no
  endpoints for them. Document the gap; do not fake it via OpenAI route shapes.
- Accept `ProviderTool` subclasses. `MapsTools` throws `RuntimeException` —
  keep it that way. Moonshot has no provider-side tools (web search, etc.).
- Publish a `config/moonshot.php`. Configuration lives under
  `config('ai.providers.moonshot')` — that is the SDK convention.
- Reintroduce Prism. The package targets `laravel/ai` only as of v1.0.0. The
  Prism implementation was removed in commit `548e57b`; the migration is
  documented in `CHANGELOG.md`.
- Bring in `spatie/laravel-package-tools`. Nothing to publish — vanilla
  `ServiceProvider` is enough.
