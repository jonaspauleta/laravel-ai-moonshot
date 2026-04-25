<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Exceptions;

use RuntimeException;

final class MoonshotFilesException extends RuntimeException
{
    public static function uploadFailed(string $reason, ?int $httpStatus = null): self
    {
        $suffix = $httpStatus !== null ? sprintf(' (HTTP %d)', $httpStatus) : '';

        return new self(sprintf('Moonshot file upload failed%s: %s', $suffix, $reason));
    }

    public static function extractionFailed(string $fileId, string $reason): self
    {
        return new self(sprintf(
            'Moonshot file extraction failed for [%s]: %s',
            $fileId,
            $reason,
        ));
    }

    public static function notFound(string $fileId): self
    {
        return new self(sprintf('Moonshot file [%s] was not found.', $fileId));
    }

    public static function quotaExceeded(string $reason): self
    {
        return new self(sprintf('Moonshot Files API quota exceeded: %s', $reason));
    }

    public static function unsupportedSource(mixed $given): self
    {
        return new self(sprintf(
            'Unsupported file source [%s]. Pass a file path (string), Illuminate\\Http\\UploadedFile, or SplFileInfo.',
            get_debug_type($given),
        ));
    }

    public static function fileTooLarge(int $bytes, int $maxBytes): self
    {
        return new self(sprintf(
            'File of %d bytes exceeds Moonshot Files API limit of %d bytes (100 MB).',
            $bytes,
            $maxBytes,
        ));
    }
}
