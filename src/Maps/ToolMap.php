<?php

declare(strict_types=1);

namespace Jonaspauleta\PrismMoonshot\Maps;

use Prism\Prism\Tool;

final class ToolMap
{
    /**
     * @param  list<Tool>  $tools
     * @return list<array<string, mixed>>
     */
    public static function map(array $tools): array
    {
        return array_map(fn (Tool $tool): array => array_filter([
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                ...$tool->hasParameters() ? [
                    'parameters' => [
                        'type' => 'object',
                        'properties' => $tool->parametersAsArray(),
                        'required' => $tool->requiredParameters(),
                    ],
                ] : [],
            ],
            'strict' => $tool->providerOptions('strict'),
        ]), $tools);
    }
}
