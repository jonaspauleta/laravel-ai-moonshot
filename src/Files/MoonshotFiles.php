<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Files;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Jonaspauleta\LaravelAiMoonshot\Exceptions\MoonshotFilesException;
use SplFileInfo;

/**
 * Thin, typed wrapper around Moonshot's /v1/files endpoints.
 *
 * Mirrors the official OpenAI-compatible Files API pattern: upload a document
 * with purpose=file-extract, then GET /content (which returns plain text) to
 * inject into a chat completion. file_id cannot be referenced directly — the
 * extracted text must travel as a message.
 *
 * Reference: https://platform.kimi.ai/docs/api/files
 */
final readonly class MoonshotFiles
{
    /** Moonshot hard limit: 100 MB per file. */
    public const int MAX_FILE_BYTES = 100 * 1024 * 1024;

    public function __construct(
        private string $apiKey,
        private string $baseUrl,
        private HttpFactory $http,
    ) {}

    /**
     * Upload a file for extraction and return its metadata.
     */
    public function upload(
        string|UploadedFile|SplFileInfo $file,
        MoonshotFilePurpose $purpose = MoonshotFilePurpose::FileExtract,
    ): MoonshotFile {
        [$resource, $filename, $size] = $this->resolveSource($file);

        if ($size > self::MAX_FILE_BYTES) {
            if (is_resource($resource)) {
                fclose($resource);
            }
            throw MoonshotFilesException::fileTooLarge($size, self::MAX_FILE_BYTES);
        }

        $response = $this->client()
            ->attach('file', $resource, $filename)
            ->post('files', ['purpose' => $purpose->value]);

        if (is_resource($resource)) {
            fclose($resource);
        }

        $this->guardAgainstFailure($response, 'upload');

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        return MoonshotFile::fromArray($payload);
    }

    /**
     * Retrieve the extracted text content for a file uploaded with file-extract.
     *
     * The /content endpoint returns text/plain, not JSON.
     */
    public function content(string $fileId): string
    {
        $response = $this->client()->get(sprintf('files/%s/content', rawurlencode($fileId)));

        $this->guardAgainstFailure($response, 'extraction', $fileId);

        return $response->body();
    }

    /**
     * List all uploaded files for the configured account.
     *
     * @return list<MoonshotFile>
     */
    public function list(): array
    {
        $response = $this->client()->get('files');

        $this->guardAgainstFailure($response, 'list');

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $files = [];

        foreach ($data as $row) {
            /** @var array<string, mixed> $normalized */
            $normalized = is_array($row) ? $row : [];
            $files[] = MoonshotFile::fromArray($normalized);
        }

        return $files;
    }

    /**
     * Retrieve metadata for a single file.
     */
    public function get(string $fileId): MoonshotFile
    {
        $response = $this->client()->get(sprintf('files/%s', rawurlencode($fileId)));

        $this->guardAgainstFailure($response, 'get', $fileId);

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        return MoonshotFile::fromArray($payload);
    }

    /**
     * Delete a file. Returns true when Moonshot confirms deletion.
     */
    public function delete(string $fileId): bool
    {
        $response = $this->client()->delete(sprintf('files/%s', rawurlencode($fileId)));

        $this->guardAgainstFailure($response, 'delete', $fileId);

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        return ($payload['deleted'] ?? null) === true;
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim($this->baseUrl, '/').'/')
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout(120);
    }

    /**
     * Resolve a path / UploadedFile / SplFileInfo to a [resource, filename, size] tuple.
     *
     * @return array{0: resource, 1: string, 2: int}
     */
    private function resolveSource(string|UploadedFile|SplFileInfo $file): array
    {
        if (is_string($file)) {
            if (! is_file($file) || ! is_readable($file)) {
                throw MoonshotFilesException::uploadFailed(sprintf('File [%s] is not readable.', $file));
            }

            $resource = fopen($file, 'rb');

            if ($resource === false) {
                throw MoonshotFilesException::uploadFailed(sprintf('Unable to open [%s] for reading.', $file));
            }

            $size = filesize($file);

            return [$resource, basename($file), $size === false ? 0 : $size];
        }

        if ($file instanceof UploadedFile) {
            $path = $file->getRealPath();

            if ($path === false) {
                throw MoonshotFilesException::uploadFailed('UploadedFile path could not be resolved.');
            }

            $resource = fopen($path, 'rb');

            if ($resource === false) {
                throw MoonshotFilesException::uploadFailed(sprintf('Unable to open uploaded file [%s].', $path));
            }

            return [$resource, $file->getClientOriginalName(), $file->getSize()];
        }

        $path = $file->getRealPath();

        if ($path === false) {
            throw MoonshotFilesException::uploadFailed('SplFileInfo path could not be resolved.');
        }

        $resource = fopen($path, 'rb');

        if ($resource === false) {
            throw MoonshotFilesException::uploadFailed(sprintf('Unable to open [%s] for reading.', $path));
        }

        return [$resource, $file->getFilename(), (int) $file->getSize()];
    }

    private function guardAgainstFailure(Response $response, string $operation, ?string $fileId = null): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $code = $this->extractErrorCode($response);
        $message = $this->extractErrorMessage($response);
        $reason = sprintf('%s%s', $code !== '' ? "[$code] " : '', $message !== '' ? $message : trim($response->body()));

        throw match (true) {
            $status === 404 && $fileId !== null => MoonshotFilesException::notFound($fileId),
            $code === 'exceeded_current_quota_error', $code === 'rate_limit_reached_error' => MoonshotFilesException::quotaExceeded($reason),
            $operation === 'extraction' && $fileId !== null => MoonshotFilesException::extractionFailed($fileId, $reason),
            default => MoonshotFilesException::uploadFailed($reason, $status),
        };
    }

    private function extractErrorCode(Response $response): string
    {
        /** @var array<string, mixed>|null $payload */
        $payload = $response->json();

        if (! is_array($payload)) {
            return '';
        }

        $error = $payload['error'] ?? null;

        if (is_array($error) && is_string($error['type'] ?? null)) {
            return $error['type'];
        }

        return '';
    }

    private function extractErrorMessage(Response $response): string
    {
        /** @var array<string, mixed>|null $payload */
        $payload = $response->json();

        if (! is_array($payload)) {
            return '';
        }

        $error = $payload['error'] ?? null;

        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }

        return '';
    }
}
