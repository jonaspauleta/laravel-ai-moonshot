<?php

declare(strict_types=1);

namespace Jonaspauleta\PrismMoonshot\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Jonaspauleta\PrismMoonshot\Concerns\MapsFinishReason;
use Jonaspauleta\PrismMoonshot\Concerns\ValidatesResponses;
use Jonaspauleta\PrismMoonshot\Maps\FinishReasonMap;
use Jonaspauleta\PrismMoonshot\Maps\MessageMap;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

final class Structured
{
    use MapsFinishReason;
    use ValidatesResponses;

    private ResponseBuilder $responseBuilder;

    public function __construct(private PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        $request = $this->appendMessageForJsonMode($request);

        $data = $this->sendRequest($request);

        $this->validateResponse($data);

        return $this->createResponse($request, $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendRequest(Request $request): array
    {
        /** @var Response $response */
        $response = $this->client->post(
            'chat/completions',
            array_merge([
                'model' => $request->model(),
                'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'max_completion_tokens' => $request->maxTokens(),
                'response_format' => ['type' => 'json_object'],
            ], Arr::whereNotNull([
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
            ])),
        );

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createResponse(Request $request, array $data): StructuredResponse
    {
        $text = data_get($data, 'choices.0.message.content', '');

        $responseMessage = new AssistantMessage($text);
        $request->addMessage($responseMessage);

        $step = new Step(
            text: $text,
            finishReason: FinishReasonMap::map(data_get($data, 'choices.0.finish_reason', '')),
            usage: new Usage(
                data_get($data, 'usage.prompt_tokens'),
                data_get($data, 'usage.completion_tokens'),
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [],
            raw: $data,
        );

        $this->responseBuilder->addStep($step);

        return $this->responseBuilder->toResponse();
    }

    private function appendMessageForJsonMode(Request $request): Request
    {
        return $request->addMessage(new SystemMessage(sprintf(
            "You MUST respond EXCLUSIVELY with a JSON object that strictly adheres to the following schema. \n Do NOT explain or add other content. Validate your response against this schema \n %s",
            json_encode($request->schema()->toArray(), JSON_PRETTY_PRINT),
        )));
    }
}
