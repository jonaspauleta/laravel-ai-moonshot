<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Providers\Tools;

use Laravel\Ai\Providers\Tools\ProviderTool;

/**
 * Base class for Moonshot Formula tools.
 *
 * Formula tools are fetched from the Moonshot Formulas API at request time —
 * each URI exposes one or more regular function tool definitions that are
 * passed directly to the chat completions `tools` array. When the model emits
 * a tool_call for one of those function names, the gateway POSTs to the
 * formula's fibers endpoint and forwards the `context.output` back as the
 * ToolResult content.
 *
 * Subclasses only need to declare the formula URI (e.g. "moonshot/convert:latest").
 *
 * @see https://platform.kimi.ai/docs/guide/use-official-tools
 */
abstract class MoonshotFormulaTool extends ProviderTool
{
    /**
     * Formula URI relative to the Moonshot base API.
     *
     * Examples: "moonshot/convert:latest", "moonshot/fetch:latest".
     */
    abstract public function formulaUri(): string;
}
