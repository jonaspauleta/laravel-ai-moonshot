<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Exceptions;

use InvalidArgumentException;

final class UnsupportedAttachmentException extends InvalidArgumentException
{
    public static function for(mixed $attachment): self
    {
        return new self(sprintf(
            'Unsupported attachment type [%s]. Moonshot accepts Laravel\\Ai\\Files\\Base64Image, RemoteImage, LocalImage, StoredImage, or Illuminate\\Http\\UploadedFile (with image/jpeg|png|gif|webp MIME type).',
            get_debug_type($attachment),
        ));
    }

    public static function document(): self
    {
        return new self(
            'Moonshot does not support document attachments. Only image attachments are supported. Extract document text client-side and send it as a regular message, or use a different provider for document inputs.',
        );
    }
}
