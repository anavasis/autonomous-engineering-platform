<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Policy;
final class PinProviderPolicy implements OptimizationPolicy {
    public function id(): string { return 'pin_provider'; }
    public function evaluate(array $intent, array $candidate, array $context = []): array {
        $pin = is_string($intent['preferredProviderId'] ?? null) ? $intent['preferredProviderId'] : null;
        if ($pin === null || $pin === '') {
            return ['admit' => true, 'scoreDelta' => 0.0, 'reason' => 'no pin'];
        }
        $id = is_string($candidate['providerId'] ?? null) ? $candidate['providerId'] : '';
        $ok = $id === $pin;
        return ['admit' => $ok, 'scoreDelta' => $ok ? 100.0 : 0.0, 'reason' => $ok ? 'pinned' : 'not pinned'];
    }
}
