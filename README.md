# laravel-ai-moonshot

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jonaspauleta/laravel-ai-moonshot.svg?style=flat-square)](https://packagist.org/packages/jonaspauleta/laravel-ai-moonshot)
[![Total Downloads](https://img.shields.io/packagist/dt/jonaspauleta/laravel-ai-moonshot.svg?style=flat-square)](https://packagist.org/packages/jonaspauleta/laravel-ai-moonshot)
[![Tests](https://img.shields.io/github/actions/workflow/status/jonaspauleta/laravel-ai-moonshot/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/jonaspauleta/laravel-ai-moonshot/actions/workflows/run-tests.yml)
[![License](https://img.shields.io/packagist/l/jonaspauleta/laravel-ai-moonshot.svg?style=flat-square)](LICENSE.md)

[Moonshot AI](https://platform.moonshot.ai/) (Kimi K2) provider for the official [Laravel AI SDK](https://github.com/laravel/ai).

Moonshot's API is OpenAI-compatible (`POST https://api.moonshot.ai/v1/chat/completions`), so this driver plugs directly into the SDK's `TextProvider` / `TextGateway` contracts and behaves like any first-party provider — `agent()`, `Ai::textProvider()`, agent classes with `#[Provider]` attributes, streaming, tool calling, broadcasting, and queued prompts all work out of the box.

## Features

- ✅ Text generation (`prompt()`)
- ✅ Streaming responses (`stream()`, `broadcast()`, `broadcastOnQueue()`)
- ✅ Tool calling (function calling)
- ✅ Image attachments (base64, remote URL, local file, stored disk, `UploadedFile`)
- ✅ Document Q&A via Moonshot Files API (PDF, DOC, XLSX, PPTX, …) — server-side OCR + extraction
- ✅ Kimi **thinking mode** with `reasoning_content` deltas surfaced as `ReasoningStart` / `ReasoningDelta` / `ReasoningEnd` stream events
- ✅ Multi-turn reasoning persistence (`thinking.keep = all`)
- ✅ Per-tier model overrides (`default`, `cheapest`, `smartest`)
- ✅ Custom base URL (proxy / self-hosted compatible)
- ✅ PHPStan level max, Pest 3 / 4, Pint, Rector — full quality pipeline

## Requirements

| Requirement      | Version            |
|------------------|--------------------|
| PHP              | `^8.4` (8.4, 8.5)  |
| Laravel          | `12.x \| 13.x`     |
| `laravel/ai`     | `^0.6.3`           |

`laravel/ai 0.6.x` requires `illuminate/* ^12.0|^13.0`, so Laravel 11 is not supported. CI exercises every PHP × Laravel combination above on each push and PR.

## Installation

```bash
composer require jonaspauleta/laravel-ai-moonshot
```

The service provider is auto-discovered. There are no migrations or config files to publish — configuration lives in your application's existing `config/ai.php`.

## Configuration

Add your API key to `.env`:

```env
MOONSHOT_API_KEY=sk-...
```

Register the provider in `config/ai.php`:

```php
'providers' => [
    // ...

    'moonshot' => [
        'driver' => 'moonshot',
        'name' => 'moonshot',
        'key' => env('MOONSHOT_API_KEY'),

        // Optional. Defaults shown.
        'url' => env('MOONSHOT_URL', 'https://api.moonshot.ai/v1'),

        // Optional per-tier model overrides.
        'models' => [
            'text' => [
                'default'  => 'kimi-k2.6',
                'cheapest' => 'kimi-k2.5',
                'smartest' => 'kimi-k2.6',
            ],
        ],
    ],
],
```

To make Moonshot the default provider for the whole application:

```php
'default' => env('AI_PROVIDER', 'moonshot'),
```

### Configuration reference

| Key                    | Type     | Required | Default                       | Description                                                                       |
|------------------------|----------|----------|-------------------------------|-----------------------------------------------------------------------------------|
| `driver`               | `string` | yes      | —                             | Must be `'moonshot'`. Resolves the provider in `AiManager`.                       |
| `name`                 | `string` | yes      | —                             | Display name returned by `Provider::name()`.                                      |
| `key`                  | `string` | yes      | —                             | Your Moonshot API key.                                                            |
| `url`                  | `string` | no       | `https://api.moonshot.ai/v1`  | API base URL. Override for proxies or regional endpoints.                         |
| `models.text.default`  | `string` | no       | `kimi-k2.6`                   | Used by `Provider::defaultTextModel()`.                                           |
| `models.text.cheapest` | `string` | no       | `kimi-k2.5`                   | Used by `Provider::cheapestTextModel()` and the `#[UseCheapestModel]` attribute.  |
| `models.text.smartest` | `string` | no       | `kimi-k2.6`                   | Used by `Provider::smartestTextModel()` and the `#[UseSmartestModel]` attribute. There is no separate thinking SKU — enable thinking per-call via `providerOptions`. |

## How it works

This package ships a single Laravel AI SDK provider — `MoonshotProvider` — backed by `MoonshotGateway`, a `TextGateway` that calls Moonshot's OpenAI-compatible `POST /v1/chat/completions` endpoint. Registration happens in `MoonshotServiceProvider::boot()` via `AiManager::extend('moonshot', …)`, so the driver string `'moonshot'` resolves to a real provider anywhere the SDK looks one up (`agent(provider: 'moonshot')`, `Ai::textProvider('moonshot')`, the `#[Provider('moonshot')]` attribute, etc.).

Streaming reads the SSE body chunk-by-chunk and maps Moonshot's payload to the SDK's stream events:

- `delta.content` → `TextStart` / `TextDelta` / `TextEnd`
- `delta.reasoning_content` → `ReasoningStart` / `ReasoningDelta` / `ReasoningEnd` (Kimi thinking mode)
- `delta.tool_calls` → buffered, then `ToolCall` + `ToolResult` after `finish_reason: tool_calls`
- `usage` chunk → `StreamEnd` payload

`ReasoningEnd` is guaranteed to fire before the first `TextStart` when the model transitions out of thinking. Multi-step tool loops are continued internally up to `TextGenerationOptions::$maxSteps` (default `ceil(count(tools) * 1.5)`).

## Usage

### Quick start (ad-hoc agent)

```php
use function Laravel\Ai\agent;

$response = agent('You are a helpful assistant.')
    ->prompt('Explain Moonshot Kimi K2 in one sentence.', provider: 'moonshot');

echo $response->text;
```

### Agent class

Generate one with `php artisan make:agent RaceEngineer` (the generator ships with `laravel/ai`), or write it by hand:

```php
namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider('moonshot')]
class RaceEngineer implements Agent, Conversational, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are a Formula 1 race engineer. Be concise and technical.';
    }

    public function messages(): iterable
    {
        return [];
    }

    public function tools(): iterable
    {
        return [];
    }
}
```

```php
$response = RaceEngineer::make()
    ->prompt('Best dry setup for the Nordschleife in a GT3?');

echo $response->text;
```

### Streaming

```php
$stream = RaceEngineer::make()
    ->stream('Walk me through a flying lap at Spa.', provider: 'moonshot');

foreach ($stream as $event) {
    // TextStart, TextDelta, TextEnd, ReasoningStart, ReasoningDelta, ReasoningEnd, ...
}
```

### Broadcasting (Echo / Reverb)

```php
use Illuminate\Broadcasting\PrivateChannel;

RaceEngineer::make()
    ->broadcastOnQueue(
        'What is the best setup for the Nordschleife?',
        new PrivateChannel("agent.{$user->id}.{$requestId}"),
        provider: 'moonshot',
        model: 'kimi-k2.6',
    );
```

### Tool calling

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetWeather implements Tool
{
    public function description(): string
    {
        return 'Get the current weather for a city.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()->description('City name')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $city = $request->string('city');

        return "Sunny, 24°C in {$city}.";
    }
}
```

```php
use function Laravel\Ai\agent;

$response = agent(
    instructions: 'Use tools when needed.',
    tools: [new GetWeather],
)->prompt('What is the weather in Lisbon?', provider: 'moonshot');
```

### Image attachments

```php
use Laravel\Ai\Files\RemoteImage;
use function Laravel\Ai\agent;

$response = agent('Describe the image.')
    ->prompt(
        prompt: 'What do you see?',
        attachments: [new RemoteImage('https://example.com/photo.jpg')],
        provider: 'moonshot',
    );
```

Supported attachment types: `Base64Image`, `RemoteImage`, `LocalImage`, `StoredImage`, and `Illuminate\Http\UploadedFile` (when the MIME type is `image/jpeg|png|gif|webp`). Document attachments are **not** supported through the SDK's generic `Document` contract — use the Files API below instead.

### Document Q&A (PDF, DOC, XLSX, …)

Moonshot's `chat/completions` endpoint does **not** accept document attachments. Document Q&A goes through Moonshot's separate Files API at `POST /v1/files`, which performs server-side text extraction (including OCR for scanned PDFs) and returns the extracted text. That text is then injected as a leading prompt block on subsequent chat completions.

Supported formats include `.pdf`, `.txt`, `.csv`, `.doc`, `.docx`, `.xls`, `.xlsx`, `.ppt`, `.pptx`, `.md`, `.json`, `.html`, `.epub`, plus most code/config formats. See Moonshot's [official format list](https://platform.kimi.ai/docs/api/files) for the full catalog.

#### Ergonomic API: `withMoonshotFile()`

Add the `InjectsMoonshotFiles` trait to any agent class that already uses `Promptable`. Implement `HasMiddleware` so the trait's middleware can prepend the extracted document text to the user prompt:

```php
namespace App\Ai\Agents;

use Jonaspauleta\LaravelAiMoonshot\Concerns\InjectsMoonshotFiles;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Promptable;

#[Provider('moonshot')]
final class ContractAnalyst implements Agent, HasMiddleware
{
    use Promptable;
    use InjectsMoonshotFiles;

    public function instructions(): string
    {
        return 'You analyze contracts and surface risky clauses.';
    }
}

$response = ContractAnalyst::make()
    ->withMoonshotFile('storage/app/contracts/2026-renewal.pdf')
    ->withMoonshotFile($uploadedFile, label: 'Refund Policy')
    ->prompt('What are the riskiest clauses?');
```

Each `withMoonshotFile()` call uploads the file, fetches its extracted text, and appends a labelled block (`Document: <label>\n<content>`) that the trait's middleware prepends to your next prompt. Call it as many times as you need; ordering is preserved.

If your agent already implements `HasMiddleware` and overrides `middleware()`, alias the trait method and merge:

```php
use InjectsMoonshotFiles {
    middleware as moonshotFilesMiddlewareDefault;
}

public function middleware(): array
{
    return [...$this->moonshotFilesMiddlewareDefault(), new MyOtherMiddleware()];
}
```

#### Lower-level service: `MoonshotFiles`

When you need control over the file lifecycle (listing, deleting, retrieving extracted content directly), inject the `MoonshotFiles` service:

```php
use Jonaspauleta\LaravelAiMoonshot\Files\MoonshotFiles;

$files = app(MoonshotFiles::class);

$file = $files->upload('contract.pdf');
$text = $files->content($file->id);

// Later: list / delete
foreach ($files->list() as $entry) {
    $files->delete($entry->id);
}
```

Run `php artisan ai:moonshot:files` to inspect uploaded files; `php artisan ai:moonshot:files --delete=<id>` to remove them.

#### Limits

- **1,000 files** per account
- **100 MB** maximum per file (the service rejects oversize uploads before the network call)
- **10 GB** total storage across all uploads
- Extraction is currently free, but rate-limited at peak usage

#### Security: treat document content as untrusted input

Extracted document text is **untrusted user input** — a malicious PDF can attempt prompt injection by mimicking system instructions. The `withMoonshotFile()` ergonomic API mitigates this by prefixing each block with `Document: <label>` so the model can distinguish your trusted instructions from document text.

The official Moonshot documentation injects extracted content as a `system` message. The Laravel AI SDK's `MessageRole` enum has no `system` case (the `instructions()` slot is reserved), so this package prepends the document block to the user prompt instead. The `Document: <label>` framing is what makes the prompt-injection mitigation work — keep the labels meaningful and avoid putting model-controlled strings in them.

### Thinking mode

There is no separate thinking model. `kimi-k2.6` and `kimi-k2.5` both expose reasoning via the `thinking` request parameter. The `smartest` tier defaults to `kimi-k2.6` with thinking enabled at call-site via `HasProviderOptions`.

When thinking is enabled, Kimi streams chain-of-thought through `reasoning_content` deltas. This package surfaces them as standard Laravel AI SDK stream events — `ReasoningStart` → `ReasoningDelta` → `ReasoningEnd` — and guarantees `ReasoningEnd` fires before the first `TextStart`.

Pass Moonshot's native `thinking` payload by implementing `HasProviderOptions` on your agent. The gateway merges the array into the request body verbatim:

```php
namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider('moonshot')]
class ThinkingAgent implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Think step by step before answering.';
    }

    public function messages(): iterable
    {
        return [];
    }

    public function tools(): iterable
    {
        return [];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $provider === 'moonshot'
            // type: 'enabled' | 'disabled'
            // keep: 'all' — kimi-k2.6 only, preserves reasoning across multi-turn conversations
            ? ['thinking' => ['type' => 'enabled', 'keep' => 'all']]
            : [];
    }
}
```

> **Note:** the `keep: 'all'` flag is `kimi-k2.6`-specific. For one-off thinking on `kimi-k2.5`, send `['thinking' => ['type' => 'enabled']]` without `keep`.

## Models

The defaults track Moonshot's [public model catalog](https://platform.moonshot.ai/docs/api/chat). Override per-tier in `config/ai.php` if Moonshot renames models or you want to pin a specific snapshot.

| Tier      | Default ID    | Used by                                       |
|-----------|---------------|-----------------------------------------------|
| Default   | `kimi-k2.6`   | `Provider::defaultTextModel()`                |
| Cheapest  | `kimi-k2.5`   | `#[UseCheapestModel]`, `cheapestTextModel()`  |
| Smartest  | `kimi-k2.6`   | `#[UseSmartestModel]`, `smartestTextModel()`. Same SKU as `default` — Moonshot has no separate thinking model. Enable thinking per-call via `providerOptions(['thinking' => ['type' => 'enabled']])`. |

Run `php artisan ai:moonshot:models` against a configured environment to print the live catalog (model IDs, context length, image/video/reasoning support). Pass `--json` for the raw response.

You can also pass an explicit model per call: `->prompt('...', model: 'kimi-k2.6')`.

## Caveats

**Structured output is best-effort.** When the SDK passes a JSON Schema (via the structured agent flow), the gateway sets `response_format: json_object` and prepends the schema to the system instructions — but Moonshot does **not** enforce JSON Schema server-side. Validate the response in your application (e.g. with `spatie/laravel-data` or any JSON Schema validator) and retry on parse errors.

## Not supported

The Moonshot API does not expose endpoints for the following capabilities at the time of release; this package will throw or fail-fast rather than fake them:

- **Embeddings** — Moonshot has no embeddings endpoint.
- **Image generation, audio, transcription, reranking** — text only.
- **Provider tools** (`ProviderTool` subclasses such as web search) — throws `RuntimeException` if passed.
- **Document attachments via the SDK's generic `Document` contract** — use `withMoonshotFile()` or the `MoonshotFiles` service instead, which goes through Moonshot's `/v1/files` extraction endpoint (see [Document Q&A](#document-qa-pdf-doc-xlsx-)).

## Troubleshooting

| Symptom                                                                                       | Cause / fix                                                                                                                                               |
|-----------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------|
| `Driver [moonshot] is not supported.`                                                          | `MoonshotServiceProvider` did not boot. Ensure auto-discovery is on, or register manually in `config/app.php` `providers`.                                |
| HTTP 401 `Invalid API key`                                                                     | `MOONSHOT_API_KEY` missing or wrong. Verify with `dd(config('ai.providers.moonshot.key'))` after a `php artisan config:clear`.                            |
| HTTP 400 `model not found` for `kimi-k2.6` (or any default tier)                               | Moonshot renamed or retired the default. Pin a working model under `config/ai.php` `providers.moonshot.models.text.{default,cheapest,smartest}`.          |
| `RuntimeException: Provider tools are not supported by Moonshot.`                              | You passed a `ProviderTool` subclass (e.g. web search). Use plain function tools or remove the provider tool.                                             |
| Document attachment is silently ignored or rejected                                            | Moonshot accepts image attachments only. Extract document text client-side and send as a regular `Message`.                                                |
| Structured output returns malformed JSON                                                      | Moonshot does **not** enforce JSON Schema server-side. Validate the response in your app and retry on parse error. See [Caveats](#caveats).               |
| Streaming hangs on long thinking-mode responses                                               | Default per-request timeout is 60s. Pass `timeout:` to `streamText()` or raise it in the gateway's HTTP client invocation.                                |
| `Http::fake()` in tests does not intercept the request                                        | Fake key must include the full base URL: `'api.moonshot.ai/v1/chat/completions' => Http::response(...)`. The Laravel HTTP client applies the base URL.    |
| `MoonshotFilesException: ... extraction failed for [file-...]`                                | Moonshot could not extract text from the uploaded file. Check the file is one of the [supported formats](https://platform.kimi.ai/docs/api/files) and not corrupted. Inspect status via `php artisan ai:moonshot:files`. |
| `MoonshotFilesException: Moonshot Files API quota exceeded`                                  | You hit the 1,000-files / 10 GB account limit, or per-minute rate limit at peak. Delete unused files via `php artisan ai:moonshot:files --delete=<id>`. |
| `MoonshotFilesException: File of N bytes exceeds Moonshot Files API limit of 104857600`     | The file is larger than 100 MB. Split it or extract relevant pages client-side before uploading.                                                          |

## Testing

```bash
composer test          # Pest
composer analyse       # PHPStan level max
composer format        # Pint
composer quality       # rector + pint + phpstan + pest
```

CI runs Pint, Rector (dry-run), PHPStan, and Pest on every push and PR — see [`.github/workflows/run-tests.yml`](.github/workflows/run-tests.yml).

## Versioning

This package follows [Semantic Versioning](https://semver.org/), but the upstream `laravel/ai` SDK is still on `0.x` — its minor bumps may include breaking changes. To keep our SemVer promise honest:

- We pin to a specific `laravel/ai` minor: `composer.json` requires `~0.6.3` (allows `0.6.x` patches but **not** `0.7.0`).
- Each new `laravel/ai` minor (`0.7`, `0.8`, …) lands in this package as **one of our minor releases** (`1.2.0`, `1.3.0`, …) after a compatibility audit.
- Truly breaking changes here (renaming public classes, removing a public method, dropping a PHP version) bump our **major**.

If `laravel/ai` reaches `1.0.0` we will widen the constraint to `^1.0` and follow standard SemVer ranges.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release notes. The project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/).

## Contributing

Contributions are welcome. Please:

1. Open an issue first for larger changes so we can discuss the approach.
2. Run `composer quality` before pushing — CI will fail otherwise.
3. Use [Conventional Commits](https://www.conventionalcommits.org/) for commit messages.
4. Add Pest tests for new behavior. `tests/Feature/MoonshotStreamTest.php` is a good template for HTTP-faked gateway tests.

## Security

If you discover a security vulnerability, please email **jpaulo4799santos@gmail.com** instead of opening a public issue. You'll get a response within 48 hours. See [SECURITY.md](SECURITY.md) for the full policy.

## Credits

- [João Paulo Santos](https://github.com/jonaspauleta)
- The [Laravel AI SDK](https://github.com/laravel/ai) team — `MoonshotGateway` is modeled directly on the SDK's first-party `DeepSeekGateway`.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
