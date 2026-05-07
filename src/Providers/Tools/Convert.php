<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Providers\Tools;

/**
 * Moonshot's server-side unit/currency conversion formula tool.
 *
 * Pass an instance to an Agent's tool list to enable Kimi's convert
 * formula — supports length, mass, volume, temperature, area, time,
 * energy, pressure, speed, and currency conversions. Tool definitions
 * are fetched at request time from the Moonshot Formulas API and the
 * gateway executes each tool call via the fibers endpoint server-side.
 *
 * @see https://platform.kimi.ai/docs/guide/use-official-tools
 */
final class Convert extends MoonshotFormulaTool
{
    public function formulaUri(): string
    {
        return 'moonshot/convert:latest';
    }
}
