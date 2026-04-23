<?php

declare(strict_types=1);

namespace Jonaspauleta\PrismMoonshot\Maps;

use Prism\Prism\Enums\ToolChoice;

final class ToolChoiceMap
{
    /**
     * @return array<string, mixed>|string|null
     */
    public static function map(string|ToolChoice|null $toolChoice): string|array|null
    {
        if (is_string($toolChoice)) {
            return [
                'type' => 'function',
                'function' => [
                    'name' => $toolChoice,
                ],
            ];
        }

        return match ($toolChoice) {
            ToolChoice::Auto => 'auto',
            ToolChoice::Any => 'required',
            ToolChoice::None => 'none',
            null => null,
        };
    }
}
