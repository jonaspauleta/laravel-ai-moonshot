<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function modelsFixture(): string
{
    $path = __DIR__.'/../Fixtures/models.json';
    $contents = file_get_contents($path);

    expect($contents)->not->toBeFalse();

    return (string) $contents;
}

it('renders the live model catalog as a table', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/models' => Http::response(modelsFixture(), 200, [
            'Content-Type' => 'application/json',
        ]),
    ]);

    $exit = Artisan::call('ai:moonshot:models');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('kimi-k2.6')
        ->and($output)->toContain('kimi-k2.5')
        ->and($output)->toContain('moonshot-v1-128k-vision-preview')
        ->and($output)->toContain('262144')
        ->and($output)->toContain('yes')
        ->and($output)->toContain('no');
});

it('dumps the raw JSON response when --json is passed', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/models' => Http::response(modelsFixture(), 200, [
            'Content-Type' => 'application/json',
        ]),
    ]);

    $exit = Artisan::call('ai:moonshot:models', ['--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('"id": "kimi-k2.6"')
        ->and($output)->toContain('"supports_reasoning": true');
});

it('fails with a clear message when the API key is missing', function (): void {
    config()->set('ai.providers.moonshot.key', '');

    $exit = Artisan::call('ai:moonshot:models');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Moonshot API key is missing.');
});

it('fails with the API status code when Moonshot returns an error', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/models' => Http::response('{"error":"unauthorized"}', 401, [
            'Content-Type' => 'application/json',
        ]),
    ]);

    $exit = Artisan::call('ai:moonshot:models');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('HTTP 401');
});
