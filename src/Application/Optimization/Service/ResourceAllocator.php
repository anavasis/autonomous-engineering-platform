<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Service;

use Aep\Application\Optimization\Model\OptimizationDecision;
use Aep\Application\Optimization\Model\ResourceAllocation;

/**
 * Binds an OptimizationDecision to concrete capacity/budget reservations.
 * Distinct from Planning\Service\ResourceAllocator.
 */
final class ResourceAllocator
{
    public function __construct(
        private readonly CapacityManager $capacity,
        private readonly BudgetManager $budgets,
    ) {}

    /**
     * @return list<string> reservation ids
     */
    public function bind(OptimizationDecision $decision, string $ownerId): array
    {
        $ids = [];
        if (!$decision->admit()) {
            return $ids;
        }
        $providerId = $decision->selectedProviderId();
        if (is_string($providerId) && $providerId !== '') {
            $reservation = $this->capacity->reserveProvider($providerId, $ownerId);
            if ($reservation instanceof ResourceAllocation) {
                $ids[] = $reservation->reservationId();
            }
            $this->budgets->reserve($decision->estimatedCost(), $providerId);
        }

        return $ids;
    }
}
