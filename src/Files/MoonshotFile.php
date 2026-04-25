<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Files;

use DateTimeImmutable;

final readonly class MoonshotFile
{
    public function __construct(
        public string $id,
        public int $bytes,
        public DateTimeImmutable $createdAt,
        public string $filename,
        public MoonshotFilePurpose $purpose,
        public string $status,
        public ?string $statusDetails,
    ) {}

    /**
     * Build a DTO from a Moonshot Files API JSON object.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $id = is_string($payload['id'] ?? null) ? $payload['id'] : '';
        $bytes = is_int($payload['bytes'] ?? null) ? $payload['bytes'] : 0;
        $createdAt = is_int($payload['created_at'] ?? null) ? $payload['created_at'] : 0;
        $filename = is_string($payload['filename'] ?? null) ? $payload['filename'] : '';
        $purposeRaw = is_string($payload['purpose'] ?? null) ? $payload['purpose'] : 'file-extract';
        $status = is_string($payload['status'] ?? null) ? $payload['status'] : '';
        $statusDetails = is_string($payload['status_details'] ?? null) ? $payload['status_details'] : null;

        return new self(
            id: $id,
            bytes: $bytes,
            createdAt: (new DateTimeImmutable)->setTimestamp($createdAt),
            filename: $filename,
            purpose: MoonshotFilePurpose::tryFrom($purposeRaw) ?? MoonshotFilePurpose::FileExtract,
            status: $status,
            statusDetails: $statusDetails,
        );
    }
}
