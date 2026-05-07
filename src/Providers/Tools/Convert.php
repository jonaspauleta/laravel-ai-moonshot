<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Providers\Tools;

use Laravel\Ai\Providers\Tools\ProviderTool;

/**
 * Moonshot's server-side unit/currency conversion builtin.
 *
 * Pass an instance to an Agent's tool list to enable Kimi's `$convert`
 * builtin function — supports length, mass, volume, temperature, area,
 * time, energy, pressure, speed, and currency conversions. Execution
 * happens server-side; the client echoes the tool_call arguments back
 * unchanged.
 *
 * @see https://platform.kimi.ai/docs/guide/use-official-tools
 */
final class Convert extends ProviderTool
{
    //
}
