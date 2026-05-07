<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Jonaspauleta\LaravelAiMoonshot\Exceptions\UnsupportedProviderToolException;
use Jonaspauleta\LaravelAiMoonshot\Providers\Tools\Convert;
use Jonaspauleta\LaravelAiMoonshot\Providers\Tools\Fetch;
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
     * Name of Moonshot's server-side unit/currency conversion builtin function.
     *
     * @see https://platform.kimi.ai/docs/guide/use-official-tools
     */
    public const string MOONSHOT_CONVERT = '$convert';

    /**
     * Name of Moonshot's server-side URL fetch builtin function.
     *
     * @see https://platform.kimi.ai/docs/guide/use-official-tools
     */
    public const string MOONSHOT_FETCH = '$fetch';

    /**
     * Map the given tools to Chat Completions function definitions.
     *
     * Recognised SDK ProviderTools are translated to Moonshot builtin_function
     * entries: `WebSearch` → `$web_search`, `Convert` → `$convert`, `Fetch` →
     * `$fetch`. The SDK's `WebSearch` knobs (`maxSearches`, `allowedDomains`,
     * location fields) are silently dropped — Kimi exposes no client-side
     * configuration for builtin functions.
     *
     * @param  array<int, mixed>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function mapTools(array $tools): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            $builtinName = $this->moonshotBuiltinName($tool);

            if ($builtinName !== null) {
                $mapped[] = $this->mapBuiltinFunction($builtinName);

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
     * Resolve the Moonshot builtin function name for a given ProviderTool, or
     * `null` if the tool is not a recognised Moonshot builtin.
     */
    protected function moonshotBuiltinName(mixed $tool): ?string
    {
        return match (true) {
            $tool instanceof WebSearch => self::MOONSHOT_WEB_SEARCH,
            $tool instanceof Convert => self::MOONSHOT_CONVERT,
            $tool instanceof Fetch => self::MOONSHOT_FETCH,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    protected function moonshotBuiltinNames(): array
    {
        return [
            self::MOONSHOT_WEB_SEARCH,
            self::MOONSHOT_CONVERT,
            self::MOONSHOT_FETCH,
        ];
    }

    /**
     * Map a Moonshot builtin function name to its Chat Completions form.
     *
     * @return array<string, mixed>
     */
    protected function mapBuiltinFunction(string $name): array
    {
        return [
            'type' => 'builtin_function',
            'function' => [
                'name' => $name,
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
