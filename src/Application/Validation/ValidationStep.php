<?php

declare(strict_types=1);

namespace Aep\Application\Validation;

/**
 * Application port for a single validation step.
 */
interface ValidationStep
{
    public function id(): string;

    public function run(ValidationRequest $request): ValidationOutcome;
}
