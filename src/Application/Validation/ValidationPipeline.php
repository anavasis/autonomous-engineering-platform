<?php

declare(strict_types=1);

namespace Aep\Application\Validation;

/**
 * Ordered validation pipeline. Produces a ValidationReport for callers to map into RecordValidation.
 */
final class ValidationPipeline
{
    /**
     * @param list<ValidationStep> $steps
     */
    public function __construct(
        private array $steps
    ) {
        if ($this->steps === []) {
            throw new \InvalidArgumentException('ValidationPipeline requires at least one ValidationStep.');
        }
        foreach ($this->steps as $step) {
            if (!$step instanceof ValidationStep) {
                throw new \InvalidArgumentException('ValidationPipeline steps must implement ValidationStep.');
            }
        }
    }

    public function run(ValidationRequest $request): ValidationReport
    {
        $outcomes = [];
        foreach ($this->steps as $step) {
            try {
                $outcome = $step->run($request);
            } catch (\InvalidArgumentException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Validation step execution failed: ' . $step->id() . ' — ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            if ($outcome->stepId() !== $step->id()) {
                throw new \RuntimeException(
                    'Validation step returned mismatched stepId. Expected ' . $step->id() . ', got ' . $outcome->stepId() . '.'
                );
            }
            $outcomes[] = $outcome;
        }

        return ValidationReport::fromOutcomes($outcomes);
    }
}
