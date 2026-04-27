<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Jonaspauleta\LaravelAiMoonshot\Exceptions\UnsupportedProviderToolException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\WebSearch;

trait MapsTools
{
    /**
     * Name of Moonshot's server-side web search builtin function.
     *
     * @see https://platform.kimi.ai/docs/guide/use-web-search
     */
    public const string MOONSHOT_WEB_SEARCH = '$web_search';

    /**
     * Map the given tools to Chat Completions function definitions.
     *
     * `WebSearch` provider tools are translated to Moonshot's `$web_search`
     * builtin_function. Note that the SDK's `WebSearch` knobs (`maxSearches`,
     * `allowedDomains`, location fields) are silently dropped — Kimi exposes
     * no client-side configuration for the builtin search.
     *
     * @param  array<int, mixed>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function mapTools(array $tools): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof WebSearch) {
                $mapped[] = $this->mapBuiltinWebSearch();

                continue;
            }

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
     * Map the SDK's WebSearch ProviderTool to Moonshot's builtin_function form.
     *
     * @return array<string, mixed>
     */
    protected function mapBuiltinWebSearch(): array
    {
        return [
            'type' => 'builtin_function',
            'function' => [
                'name' => self::MOONSHOT_WEB_SEARCH,
            ],
        ];
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
