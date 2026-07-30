<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Validation;

use Aep\Application\Validation\ValidationOutcome;
use Aep\Application\Validation\ValidationRequest;
use Aep\Application\Validation\ValidationStep;

/**
 * Local step: require non-empty declaredPaths in request context.
 * No filesystem, network, Git, or CI access.
 */
final class DeclarativeContextValidationStep implements ValidationStep
{
    public const ID = 'declarative_context';

    public function id(): string
    {
        return self::ID;
    }

    public function run(ValidationRequest $request): ValidationOutcome
    {
        $paths = $request->contextValue('declaredPaths');
        if (!is_array($paths) || $paths === []) {
            return ValidationOutcome::failed(
                self::ID,
                'declaredPaths must be a non-empty list in validation context'
            );
        }

        foreach ($paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                return ValidationOutcome::failed(
                    self::ID,
                    'declaredPaths entries must be non-empty strings'
                );
            }
        }

        return ValidationOutcome::passed(self::ID, 'declaredPaths context is present');
    }
}
