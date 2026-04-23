<?php

declare(strict_types=1);

namespace Jonaspauleta\PrismMoonshot;

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
        $data = $e->response->json() ?? [];

        throw PrismException::providerRequestErrorWithDetails(
            provider: 'Moonshot',
            statusCode: $e->response->status(),
            errorType: data_get($data, 'error.type'),
            errorMessage: data_get($data, 'error.message'),
            previous: $e,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    private function client(array $options = [], array $retry = []): PendingRequest
    {
        return $this->baseClient()
            ->when($this->apiKey, fn ($client) => $client->withToken($this->apiKey))
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($this->url);
    }
}
