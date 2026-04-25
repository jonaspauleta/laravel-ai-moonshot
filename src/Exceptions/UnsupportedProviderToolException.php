<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Exceptions;

use Laravel\Ai\Providers\Tools\ProviderTool;
use RuntimeException;

final class UnsupportedProviderToolException extends RuntimeException
{
    public static function for(ProviderTool $tool): self
    {
        $name = class_basename($tool);

        return new self(sprintf(
            'Moonshot does not support [%s] provider tools. Moonshot has no provider-side capabilities (web search, code execution, etc.). Pass plain function tools that implement Laravel\\Ai\\Contracts\\Tool, or remove the provider tool from the agent.',
            $name,
        ));
    }
}
