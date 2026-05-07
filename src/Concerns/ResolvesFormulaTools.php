<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Concerns;

use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Providers\Provider;

trait ResolvesFormulaTools
{
    /**
     * Per-request registry: formula function name → formula URI.
     *
     * Reset at the start of every generateText()/streamText() call to prevent
     * Octane memory leaks — the gateway instance is reused across requests.
     *
     * @var array<string, string>
     */
    private array $formulaToolRegistry = [];

    /**
     * Per-request cache of fetched formula tool definitions, keyed by URI.
     *
     * Prevents duplicate GET requests when the same formula appears multiple
     * times or when mapTools() is called more than once per request.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $formulaToolDefinitionsCache = [];

    /**
     * Reset formula state at the start of each request.
     */
    protected function resetFormulaState(): void
    {
        $this->formulaToolRegistry = [];
        $this->formulaToolDefinitionsCache = [];
    }

    /**
     * Resolve the formula URI for a tool call name, or null if not a formula tool.
     */
    protected function formulaToolUriFor(string $name): ?string
    {
        return $this->formulaToolRegistry[$name] ?? null;
    }

    /**
     * Fetch the function tool definitions for a formula URI.
     *
     * Results are cached in-memory for the request lifecycle to avoid
     * duplicate network calls when mapTools() is invoked more than once.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws AiException
     */
    protected function fetchFormulaToolDefinitions(Provider $provider, string $uri, ?int $timeout = null): array
    {
        if (isset($this->formulaToolDefinitionsCache[$uri])) {
            return $this->formulaToolDefinitionsCache[$uri];
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->get("formulas/{$uri}/tools"),
        );

        /** @var array<string, mixed> $data */
        $data = $response->json();

        /** @var array<int, array<string, mixed>> $tools */
        $tools = is_array($data['tools'] ?? null) ? array_values($data['tools']) : [];

        $this->formulaToolDefinitionsCache[$uri] = $tools;

        return $tools;
    }

    /**
     * Execute a formula tool call via the fibers endpoint and return its output.
     *
     * @throws AiException
     */
    protected function executeFormulaTool(Provider $provider, string $uri, string $name, string $argumentsJson, ?int $timeout = null): string
    {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post("formulas/{$uri}/fibers", [
                'name' => $name,
                'arguments' => $argumentsJson,
            ]),
        );

        /** @var array<string, mixed> $data */
        $data = $response->json();

        /** @var array<string, mixed> $context */
        $context = is_array($data['context'] ?? null) ? $data['context'] : [];

        return is_string($context['output'] ?? null) ? $context['output'] : '';
    }

    /**
     * Register all function names returned by a formula URI in the registry.
     *
     * @param  array<int, array<string, mixed>>  $toolDefinitions
     */
    protected function registerFormulaTools(string $uri, array $toolDefinitions): void
    {
        foreach ($toolDefinitions as $toolDef) {
            /** @var array<string, mixed> $function */
            $function = is_array($toolDef['function'] ?? null) ? $toolDef['function'] : [];
            $name = is_string($function['name'] ?? null) ? $function['name'] : '';

            if ($name !== '') {
                $this->formulaToolRegistry[$name] = $uri;
            }
        }
    }
}
