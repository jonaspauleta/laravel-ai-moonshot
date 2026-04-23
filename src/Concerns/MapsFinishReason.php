<?php

declare(strict_types=1);

namespace Jonaspauleta\PrismMoonshot\Concerns;

use Jonaspauleta\PrismMoonshot\Maps\FinishReasonMap;
use Prism\Prism\Enums\FinishReason;

trait MapsFinishReason
{
    use AccessesResponseData;

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        return FinishReasonMap::map($this->dataString($data, 'choices.0.finish_reason'));
    }
}
