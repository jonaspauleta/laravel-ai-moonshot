<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Providers\Tools;

/**
 * Moonshot's server-side URL content extraction formula tool.
 *
 * Pass an instance to an Agent's tool list to enable Kimi's fetch
 * formula — fetches a URL and returns its content as Markdown. Tool
 * definitions are fetched at request time from the Moonshot Formulas
 * API and the gateway executes each tool call via the fibers endpoint
 * server-side.
 *
 * @see https://platform.kimi.ai/docs/guide/use-official-tools
 */
final class Fetch extends MoonshotFormulaTool
{
    public function formulaUri(): string
    {
        return 'moonshot/fetch:latest';
    }
}
