<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Jonaspauleta\LaravelAiMoonshot\Exceptions\MoonshotFilesException;
use Jonaspauleta\LaravelAiMoonshot\Files\MoonshotFile;
use Jonaspauleta\LaravelAiMoonshot\Files\MoonshotFilePurpose;
use Jonaspauleta\LaravelAiMoonshot\Files\MoonshotFiles;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function moonshotFilesFixture(string $name): string
{
    $contents = file_get_contents(__DIR__.'/../../Fixtures/files/'.$name);
    expect($contents)->not->toBeFalse();

    return (string) $contents;
}

function moonshotTempFile(string $contents, string $name = 'doc.txt'): string
{
    $dir = sys_get_temp_dir().'/moonshot-files-test-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $path = $dir.'/'.$name;
    file_put_contents($path, $contents);

    return $path;
}

it('uploads a file from a path', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/files' => Http::response(moonshotFilesFixture('upload-response.json'), 200, [
            'Content-Type' => 'application/json',
        ]),
    ]);

    $files = resolve(MoonshotFiles::class);
    $path = moonshotTempFile('hello world', 'contract.pdf');

    $file = $files->upload($path);

    expect($file)->toBeInstanceOf(MoonshotFile::class)
        ->and($file->id)->toBe('file-abc123')
        ->and($file->filename)->toBe('contract.pdf')
        ->and($file->purpose)->toBe(MoonshotFilePurpose::FileExtract)
        ->and($file->status)->toBe('ready');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/files')
        && $request->isMultipart());
});

it('uploads from an UploadedFile', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/files' => Http::response(moonshotFilesFixture('upload-response.json'), 200, [
            'Content-Type' => 'application/json',
        ]),
    ]);

    $files = resolve(MoonshotFiles::class);
    $path = moonshotTempFile('hello', 'contract.pdf');
    $upload = new UploadedFile($path, 'contract.pdf', 'application/pdf', null, true);

    $file = $files->upload($upload);

    expect($file->id)->toBe('file-abc123');
});

it('rejects unsupported sources at the type system level', function (): void {
    $files = resolve(MoonshotFiles::class);

    $thrown = false;
    try {
        /** @phpstan-ignore-next-line argument.type */
        $files->upload(['invalid']);
    } catch (TypeError $e) {
        $thrown = true;
        expect($e->getMessage())->toContain('upload');
    }
    expect($thrown)->toBeTrue();
});

it('rejects files larger than 100 MB before the network call', function (): void {
    Http::fake();

    $files = resolve(MoonshotFiles::class);

    // Simulate by mocking SplFileInfo with a stub size
    $path = moonshotTempFile('seed', 'big.bin');
    $bigPath = $path.'-big';
    $resource = fopen($bigPath, 'wb');
    expect($resource)->not->toBeFalse();
    /** @var resource $resource */
    ftruncate($resource, MoonshotFiles::MAX_FILE_BYTES + 1);
    fclose($resource);

    expect(fn () => $files->upload($bigPath))
        ->toThrow(MoonshotFilesException::class, 'exceeds Moonshot Files API limit');

    Http::assertNothingSent();
});

it('retrieves extracted content as a plain string', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/files/file-abc123/content' => Http::response(
            moonshotFilesFixture('extract-content-response.txt'),
            200,
            ['Content-Type' => 'text/plain'],
        ),
    ]);

    $files = resolve(MoonshotFiles::class);
    $content = $files->content('file-abc123');

    expect($content)->toContain('CONTRACT')
        ->and($content)->toContain('30 days written notice');
});

it('lists files', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/files' => Http::response(moonshotFilesFixture('list-response.json'), 200, [
            'Content-Type' => 'application/json',
        ]),
    ]);

    $files = resolve(MoonshotFiles::class);
    $list = $files->list();

    expect($list)->toHaveCount(2)
        ->and($list[0]->id)->toBe('file-abc123')
        ->and($list[1]->filename)->toBe('policy.docx');
});

it('retrieves a single file', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/files/file-abc123' => Http::response(moonshotFilesFixture('upload-response.json'), 200, [
            'Content-Type' => 'application/json',
        ]),
    ]);

    $files = resolve(MoonshotFiles::class);
    $file = $files->get('file-abc123');

    expect($file->id)->toBe('file-abc123');
});

it('deletes a file', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/files/file-abc123' => Http::response(
            ['id' => 'file-abc123', 'object' => 'file', 'deleted' => true],
            200,
        ),
    ]);

    $files = resolve(MoonshotFiles::class);

    expect($files->delete('file-abc123'))->toBeTrue();
});

it('throws MoonshotFilesException with the API error code on 4xx', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/files' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Invalid purpose',
            ],
        ], 400),
    ]);

    $files = resolve(MoonshotFiles::class);
    $path = moonshotTempFile('x', 'a.txt');

    expect(fn () => $files->upload($path))
        ->toThrow(MoonshotFilesException::class, 'invalid_request_error');
});

it('translates quota errors to quotaExceeded()', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/files' => Http::response([
            'error' => [
                'type' => 'exceeded_current_quota_error',
                'message' => 'Account quota reached',
            ],
        ], 429),
    ]);

    $files = resolve(MoonshotFiles::class);
    $path = moonshotTempFile('x', 'a.txt');

    expect(fn () => $files->upload($path))
        ->toThrow(MoonshotFilesException::class, 'quota exceeded');
});

it('translates 404 on a known file id to notFound()', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/files/file-missing' => Http::response([], 404),
    ]);

    $files = resolve(MoonshotFiles::class);

    expect(fn () => $files->get('file-missing'))
        ->toThrow(MoonshotFilesException::class, 'was not found');
});

it('translates extraction failures with the file id', function (): void {
    Http::fake([
        'api.moonshot.ai/v1/files/file-abc123/content' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'extraction failed',
            ],
        ], 422),
    ]);

    $files = resolve(MoonshotFiles::class);

    expect(fn () => $files->content('file-abc123'))
        ->toThrow(MoonshotFilesException::class, 'extraction failed for [file-abc123]');
});
