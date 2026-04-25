<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Files;

/**
 * Upload purposes accepted by Moonshot's POST /v1/files endpoint.
 *
 * Only FileExtract is exposed publicly in v1.2.0. Image and Video are
 * reserved for future versions; the chat gateway already handles vision
 * via the SDK's image attachment contracts.
 */
enum MoonshotFilePurpose: string
{
    case FileExtract = 'file-extract';
    case Image = 'image';
    case Video = 'video';
    case Batch = 'batch';
}
