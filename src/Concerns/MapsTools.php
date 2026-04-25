<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Jonaspauleta\LaravelAiMoonshot\Exceptions\UnsupportedProviderToolException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Tools\ProviderTool;

trait MapsTools
{
    /**
     * Map the given tools to Chat Completions function definitions.
     *
     * @param  array<int, mixed>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function mapTools(array $tools): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ProviderTool) {
                throw UnsupportedProviderToolException::for($tool);
            }

            if ($tool instanceof Tool) {
                $mapped[] = $this->mapTool($tool);
            }
        }

        return $mapped;
    }

    /**
     * Map a regular tool to a Chat Completions function definition.
     *
     * @return array<string, mixed>
     */
    protected function mapTool(Tool $tool): array
    {
        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $schemaArray = filled($schema)
            ? new ObjectSchema($schema)->toSchema()
            : [];

        return [
            'type' => 'function',
            'function' => [
                'name' => class_basename($tool),
                'description' => (string) $tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $schemaArray['properties'] ?? (object) [],
                    'required' => $schemaArray['required'] ?? [],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }
}
