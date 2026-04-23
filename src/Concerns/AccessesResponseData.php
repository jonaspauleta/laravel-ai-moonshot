<?php

declare(strict_types=1);

namespace Jonaspauleta\PrismMoonshot\Concerns;

/**
 * Typed accessors for the untyped JSON arrays returned by Moonshot's
 * OpenAI-compatible endpoints. Keeps `data_get()`'s mixed return out of
 * downstream code paths so PHPStan can analyse handlers at level max.
 */
trait AccessesResponseData
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function dataString(array $data, string $path, string $default = ''): string
    {
        $value = data_get($data, $path, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function dataNullableString(array $data, string $path): ?string
    {
        $value = data_get($data, $path);

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function dataInt(array $data, string $path, int $default = 0): int
    {
        $value = data_get($data, $path, $default);

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>
     */
    protected function dataList(array $data, string $path): array
    {
        $value = data_get($data, $path, []);

        if (! is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>|null
     */
    protected function dataArray(array $data, string $path): ?array
    {
        $value = data_get($data, $path);

        return is_array($value) ? $value : null;
    }
}
