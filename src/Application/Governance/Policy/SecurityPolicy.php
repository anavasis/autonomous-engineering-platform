<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Policy;

final class SecurityPolicy
{
    /**
     * @param array<string, mixed> $evidence
     * @return array{pass: bool, detail: string}
     */
    public function evaluate(array $evidence): array
    {
        if (($evidence['secretsFound'] ?? false) === true) {
            return ['pass' => false, 'detail' => 'secrets detected'];
        }
        if (($evidence['securityScan'] ?? 'unknown') === 'failed') {
            return ['pass' => false, 'detail' => 'security scan failed'];
        }
        return ['pass' => true, 'detail' => 'security ok'];
    }
}
