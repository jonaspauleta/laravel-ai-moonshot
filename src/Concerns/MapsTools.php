<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Jonaspauleta\LaravelAiMoonshot\Exceptions\UnsupportedProviderToolException;
use Jonaspauleta\LaravelAiMoonshot\Providers\Tools\MoonshotFormulaTool;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
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
     * - WebSearch → existing `$web_search` builtin_function (unchanged).
     * - MoonshotFormulaTool subclasses → definitions are fetched from the
     *   Formulas API at request time and registered in the formula registry.
     * - Other ProviderTool subclasses → throws UnsupportedProviderToolException.
     * - Plain Tool instances → mapped via mapTool().
     *
     * @param  array<int, mixed>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function mapTools(array $tools, Provider $provider, ?int $timeout = null): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof WebSearch) {
                $mapped[] = $this->mapBuiltinFunction(self::MOONSHOT_WEB_SEARCH);

                continue;
            }

            if ($tool instanceof MoonshotFormulaTool) {
                $uri = $tool->formulaUri();
                $definitions = $this->fetchFormulaToolDefinitions($provider, $uri, $timeout);
                $this->registerFormulaTools($uri, $definitions);

                foreach ($definitions as $definition) {
                    $mapped[] = $definition;
                }

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
     * @return list<string>
     */
    protected function moonshotBuiltinNames(): array
    {
        return [
            self::MOONSHOT_WEB_SEARCH,
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
