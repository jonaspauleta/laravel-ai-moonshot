<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\TextResponse;

final class MoonshotGateway implements TextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesMoonshotClient;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\MapsMessages;
    use Concerns\MapsTools;
    use Concerns\ParsesTextResponses;
    use Concerns\ResolvesFormulaTools;
    use HandlesFailoverErrors;
    use InvokesTools;
    use ParsesServerSentEvents;

    // why: $events mirrors Laravel\Ai\Gateway\DeepSeek\DeepSeekGateway's
    // protected Dispatcher — reserved for future event emission. PHPStan flags it
    // because nothing in this package reads it yet; keeping the parity with the
    // SDK's own gateways is more valuable than deleting it.
    /** @phpstan-ignore property.onlyWritten */
    public function __construct(private Dispatcher $events)
    {
        $this->initializeToolCallbacks();
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<int, mixed>  $messages
     * @param  array<int, mixed>  $tools
     * @param  array<string, mixed>|null  $schema
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        // why: the traits type parameters as the concrete Provider base class (matching
        // how Laravel's own DeepSeek/OpenAi gateways are written), but the public
        // contract is TextProvider. Every real TextProvider extends Provider.
        assert($provider instanceof Provider);

        $this->resetFormulaState();

        $body = $this->buildTextRequestBody(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
            $timeout,
        );

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('chat/completions', $body),
        );

        /** @var array<string, mixed> $data */
        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse(
            $data,
            $provider,
            filled($schema),
            $tools,
            $schema,
            $options,
            $instructions,
            $messages,
            $timeout,
        );
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<int, mixed>  $messages
     * @param  array<int, mixed>  $tools
     * @param  array<string, mixed>|null  $schema
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        // why: see generateText() — narrow the public contract to the concrete base
        // class so the traits (which mirror Laravel's own gateway traits) type-check.
        assert($provider instanceof Provider);

        $this->resetFormulaState();

        $body = $this->buildTextRequestBody(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
            $timeout,
        );

        $body['stream'] = true;
        $body['stream_options'] = ['include_usage' => true];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('chat/completions', $body),
        );

        yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $tools,
            $schema,
            $options,
            $response->toPsrResponse()->getBody(),
            $instructions,
            $messages,
            0,
            null,
            [],
            $timeout,
        );
    }
}
