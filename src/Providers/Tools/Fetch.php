<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Providers\Tools;

use Laravel\Ai\Providers\Tools\ProviderTool;

/**
 * Moonshot's server-side URL content extraction builtin.
 *
 * Pass an instance to an Agent's tool list to enable Kimi's `$fetch`
 * builtin function — fetches a URL and returns its content as Markdown.
 * Execution happens server-side; the client echoes the tool_call
 * arguments back unchanged.
 *
 * @see https://platform.kimi.ai/docs/guide/use-official-tools
 */
final class Fetch extends ProviderTool
{
    //
}
