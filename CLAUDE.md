# laravel-ai-moonshot — Agent Guide

Single-purpose Moonshot AI (Kimi K2) provider for the official Laravel AI SDK
(`laravel/ai`). Wraps Moonshot's OpenAI-compatible chat-completions endpoint
(`POST https://api.moonshot.ai/v1/chat/completions`).

## Architecture

One registration path. `MoonshotServiceProvider::boot()` calls
`AiManager::extend('moonshot', …)` (after-resolving), which returns a
`MoonshotProvider` (`extends Laravel\Ai\Providers\Provider implements TextProvider`).

`MoonshotProvider` uses three SDK traits (`GeneratesText`, `HasTextGateway`,
`StreamsText`) and lazily constructs a single `MoonshotGateway` instance. It
returns `MoonshotTextGenerationLoop` for the real gateway and Laravel AI's
standard `TextGenerationLoop` for injected fake gateways.

`MoonshotGateway` implements `Laravel\Ai\Contracts\Gateway\StepTextGateway`.
`generateTextStep()` and `generateStreamStep()` each perform one provider step
and return a `StepResponse`. The gateway never orchestrates tool recursion.
Behavior is split across local traits in `src/Concerns/`:

- `BuildsTextRequests` — request body assembly. Composes instructions + schema,
  maps messages, maps tools, merges `providerOptions(driver)` verbatim into the
  body (this is how Kimi `thinking` payloads reach Moonshot).
- `CreatesMoonshotClient` — `Http::baseUrl(...)->withToken(...)->throw()`.
- `HandlesTextStreaming` — SSE chunk loop. Yields `StreamStart`, `Reasoning*`,
  `Text*`, and `ToolCall`, then returns the step's `StepResponse`.
- `MapsAttachments`, `MapsMessages`, `MapsTools`, `ParsesTextResponses` —
  protocol shape conversion to/from OpenAI chat schema.

Plus two SDK-shipped traits: `HandlesFailoverErrors` and
`ParsesServerSentEvents`.

`MoonshotTextGenerationLoop` extends Laravel AI's `TextGenerationLoop`. It
resolves `$web_search` and Formula calls while delegating ordinary `Tool`
execution to the SDK behavior. The loop resets Formula definitions once per
generation, not once per provider step.

Streaming has one terminal `StreamEnd`, emitted by Laravel AI with cumulative
usage. `TextGenerationStepCompleted` is dispatched after each completed gateway
step for cancellation metering and is not part of the stream event sequence.

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
- **Default model IDs come from Moonshot's public catalog.**
  `default` and `smartest` map to `kimi-k2.6`; `cheapest` maps to `kimi-k2.5`.
  There is no separate "thinking" SKU — thinking is enabled via
  `providerOptions(['thinking' => ['type' => 'enabled']])`. If Moonshot
  retires one, defaults rot silently and users get HTTP 400. The weekly
  `catalog-drift` GitHub workflow polls `GET /v1/models` and opens an issue
  if any default disappears. Always allow override via
  `config/ai.php` → `providers.moonshot.models.text.{default,cheapest,smartest}`.
- **Provider options are merged into every step body.** Do not move them to the
  generation loop or only apply them on the first request.

## Structured outputs

Schemas passed through `TextGenerationLoop::generate()`'s `?array $schema` (typically
from an agent implementing `Laravel\Ai\Contracts\HasStructuredOutput` or via
`Laravel\Ai\StructuredAnonymousAgent`) become a Moonshot Chat-Completions
`response_format` envelope:

```php
response_format: {
    type: 'json_schema',
    json_schema: { name, schema, strict: true },
}
```

`strict` is hard-coded to `true`, and Moonshot's docs explicitly recommend
`json_schema` over the older `json_object` mode.
`BuildsTextRequests::buildTextRequestBody()` emits this envelope for every step,
including tool follow-ups and streams.

**Schemas must be MFJS-compatible** (Moonshot Flavored JSON Schema —
[spec](https://github.com/MoonshotAI/walle/blob/main/docs/mfjs-spec.zh.md)).
Unsupported keywords include `format`, `pattern`, `oneOf`, `allOf`,
`minLength`/`maxLength`, `minimum`/`maximum`, `title`, `$comment`, `prefixItems`.
Schemas are passed through verbatim — Moonshot returns HTTP 400 on
incompatible keywords, surfaced via `withErrorHandling`. We deliberately do
not sanitize.

Streaming structured outputs send the same envelope; SDK has no
`ObjectStart`/`ObjectDelta`/`ObjectEnd` events, so callers accumulate
`TextDelta`s and `json_decode` themselves. Non-streaming returns decoded data
through `StepResponse::$structured`; Laravel AI builds the final
`StructuredTextResponse`.

## Do not

- Add embeddings, image generation, audio, or transcription. Moonshot has no
  endpoints for them. Document the gap; do not fake it via OpenAI route shapes.
- Accept `ProviderTool` subclasses. `MapsTools` throws `RuntimeException` —
  keep it that way. Moonshot has no provider-side tools (web search, etc.).
- Publish a `config/moonshot.php`. Configuration lives under
  `config('ai.providers.moonshot')` — that is the SDK convention.
- Reintroduce Prism. The package targets `laravel/ai` only. The Prism
  implementation was removed in commit `548e57b` before the first public
  release.
- Bring in `spatie/laravel-package-tools`. Nothing to publish — vanilla
  `ServiceProvider` is enough.
