<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Events;

use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;

final readonly class TextGenerationStepCompleted
{
    public function __construct(
        public string $invocationId,
        public StepContext $context,
        public StepResponse $response,
    ) {
        //
    }
}
