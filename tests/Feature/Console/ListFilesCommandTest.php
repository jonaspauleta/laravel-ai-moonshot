<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('renders the file table', function (): void {
    $listJson = (string) file_get_contents(__DIR__.'/../../Fixtures/files/list-response.json');

    Http::fake([
        'api.moonshot.ai/v1/files' => Http::response(
            $listJson,
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    $exit = Artisan::call('ai:moonshot:files');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('file-abc123')
        ->and($output)->toContain('contract.pdf')
        ->and($output)->toContain('policy.docx')
        ->and($output)->toContain('file-extract')
        ->and($output)->toContain('ready');
});

it('warns when no files exist', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/files' => Http::response(['object' => 'list', 'data' => []], 200),
    ]);

    $exit = Artisan::call('ai:moonshot:files');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('No files uploaded');
});

it('deletes files passed via --delete', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/files/file-abc123' => Http::response([
            'id' => 'file-abc123',
            'object' => 'file',
            'deleted' => true,
        ], 200),
    ]);

    $exit = Artisan::call('ai:moonshot:files', [
        '--delete' => ['file-abc123'],
        '--no-interaction' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Deleted file-abc123');
});

it('reports a failure when deletion errors', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/files/file-missing' => Http::response([], 404),
    ]);

    $exit = Artisan::call('ai:moonshot:files', [
        '--delete' => ['file-missing'],
        '--no-interaction' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Failed to delete file-missing');
});
