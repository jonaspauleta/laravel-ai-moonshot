<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jonaspauleta\PrismMoonshot\Moonshot;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('returns structured JSON output with response_format=json_object', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/chat/completions' => Http::response([
            'id' => 'cmpl-struct',
            'model' => 'kimi-k2.6',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => '{"name":"Spa","country":"Belgium"}',
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 25, 'completion_tokens' => 10, 'total_tokens' => 35],
        ]),
    ]);

    $schema = new ObjectSchema(
        name: 'track',
        description: 'A racing circuit',
        properties: [
            new StringSchema('name', 'circuit name'),
            new StringSchema('country', 'country'),
        ],
        requiredFields: ['name', 'country'],
    );

    $response = Prism::structured()
        ->using(Moonshot::KEY, 'kimi-k2.6')
        ->withSchema($schema)
        ->withPrompt('Give me a famous Formula 1 circuit.')
        ->asStructured();

    expect($response->structured)->toBe(['name' => 'Spa', 'country' => 'Belgium']);
    expect($response->finishReason)->toBe(FinishReason::Stop);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return data_get($body, 'response_format.type') === 'json_object'
            && data_get($body, 'model') === 'kimi-k2.6';
    });
});
