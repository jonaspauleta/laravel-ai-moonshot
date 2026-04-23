<?php

declare(strict_types=1);

namespace Jonaspauleta\PrismMoonshot;

use Closure;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Jonaspauleta\PrismMoonshot\Handlers\Stream;
use Jonaspauleta\PrismMoonshot\Handlers\Structured;
use Jonaspauleta\PrismMoonshot\Handlers\Text;
use Override;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use SensitiveParameter;

final class Moonshot extends Provider
{
    use InitializesClient;

    public const string KEY = 'moonshot';

    public const string DEFAULT_URL = 'https://api.moonshot.ai/v1';

    public function __construct(
        #[SensitiveParameter] public readonly string $apiKey,
        public readonly string $url = self::DEFAULT_URL,
    ) {}

    #[Override]
    public function text(TextRequest $request): TextResponse
    {
        return new Text($this->client(
            $request->clientOptions(),
            $request->clientRetry(),
        ))->handle($request);
    }

    #[Override]
    public function stream(TextRequest $request): Generator
    {
        return new Stream($this->client(
            $request->clientOptions(),
            $request->clientRetry(),
        ))->handle($request);
    }

    #[Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        return new Structured($this->client(
            $request->clientOptions(),
            $request->clientRetry(),
        ))->handle($request);
    }

    #[Override]
    public function handleRequestException(string $model, RequestException $e): never
    {
        match ($e->response->status()) {
            429 => throw PrismRateLimitedException::make([]),
            default => $this->handleResponseErrors($e),
        };
    }

    private function handleResponseErrors(RequestException $e): never
    {
        $data = $e->response->json();
        $payload = is_array($data) ? $data : [];

        throw PrismException::providerRequestErrorWithDetails(
            provider: 'Moonshot',
            statusCode: $e->response->status(),
            errorType: $this->nullableString(data_get($payload, 'error.type')),
            errorMessage: $this->nullableString(data_get($payload, 'error.message')),
            previous: $e,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool}|array{}  $retry
     */
    private function client(array $options = [], array $retry = []): PendingRequest
    {
        $client = $this->baseClient()
            ->when($this->apiKey !== '', fn (PendingRequest $client): PendingRequest => $client->withToken($this->apiKey))
            ->withOptions($options);

        if ($retry !== []) {
            $client = $client->retry(...$retry);
        }

        return $client->baseUrl($this->url);
    }
}
