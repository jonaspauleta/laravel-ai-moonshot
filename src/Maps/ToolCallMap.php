<?php

declare(strict_types=1);

namespace Jonaspauleta\PrismMoonshot\Maps;

use Prism\Prism\ValueObjects\ToolCall;

final class ToolCallMap
{
    /**
     * @param  list<array<array-key, mixed>>  $toolCalls
     * @return list<ToolCall>
     */
    public static function map(array $toolCalls): array
    {
        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: self::asString(data_get($toolCall, 'id')),
            name: self::asString(data_get($toolCall, 'function.name')),
            arguments: self::asArguments(data_get($toolCall, 'function.arguments')),
        ), $toolCalls);
    }

    private static function asString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>|string
     */
    private static function asArguments(mixed $value): array|string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        return '';
    }
}
