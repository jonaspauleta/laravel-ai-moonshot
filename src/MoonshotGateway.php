<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Jonaspauleta\LaravelAiMoonshot\Events\TextGenerationStepCompleted;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;

final class MoonshotGateway implements StepTextGateway
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
    use ParsesServerSentEvents;

    public function __construct(private Dispatcher $events)
    {
        //
    }

    public function beginTextGeneration(): void
    {
        $this->resetFormulaState();
    }

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        assert($provider instanceof Provider);

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
        );
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        assert($provider instanceof Provider);

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

        $stepResponse = yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $response->toPsrResponse()->getBody(),
        );

        if ($stepResponse instanceof StepResponse) {
            $this->events->dispatch(new TextGenerationStepCompleted(
                $invocationId,
                $stepContext,
                $stepResponse,
            ));
        }

        return $stepResponse;
    }
}
