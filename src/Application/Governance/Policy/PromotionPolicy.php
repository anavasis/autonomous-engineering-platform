<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Policy;

use Aep\Application\Governance\Model\Environment;

final class PromotionPolicy
{
    /**
     * @param list<Environment> $environments
     */
    public function canPromote(?Environment $from, Environment $to, array $environments): array
    {
        if ($from === null) {
            return ['admit' => true, 'reason' => 'initial promotion'];
        }
        if ($to->promotionOrder() < $from->promotionOrder()) {
            return ['admit' => false, 'reason' => 'cannot promote backwards'];
        }
        // ensure no skipped required env with higher order between
        foreach ($environments as $env) {
            if ($env->promotionOrder() > $from->promotionOrder() && $env->promotionOrder() < $to->promotionOrder()) {
                if (in_array($env->kind(), [Environment::KIND_STAGING, Environment::KIND_QA], true)) {
                    return ['admit' => false, 'reason' => 'must pass ' . $env->name() . ' first'];
                }
            }
        }
        return ['admit' => true, 'reason' => 'promotion allowed'];
    }
}
