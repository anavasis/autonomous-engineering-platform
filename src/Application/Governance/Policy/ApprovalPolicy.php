<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Policy;

final class ApprovalPolicy
{
    /**
     * @param array<string, mixed> $settings
     * @return list<array<string, mixed>>
     */
    public static function stagesFromSettings(array $settings): array
    {
        $stages = is_array($settings['approvalStages'] ?? null) ? $settings['approvalStages'] : null;
        if (!is_array($stages) || $stages === []) {
            return [
                ['stageId' => 'engineering', 'name' => 'Engineering', 'minApprovals' => 1, 'status' => 'pending', 'grants' => []],
                ['stageId' => 'security', 'name' => 'Security', 'minApprovals' => 1, 'status' => 'pending', 'grants' => []],
            ];
        }
        $out = [];
        foreach ($stages as $s) {
            if (!is_array($s)) { continue; }
            $out[] = [
                'stageId' => is_string($s['stageId'] ?? null) ? $s['stageId'] : ('stage_' . count($out)),
                'name' => is_string($s['name'] ?? null) ? $s['name'] : 'Stage',
                'minApprovals' => is_int($s['minApprovals'] ?? null) ? $s['minApprovals'] : 1,
                'status' => 'pending',
                'grants' => [],
            ];
        }
        return $out !== [] ? $out : self::stagesFromSettings(['approvalStages' => null]);
    }

    /**
     * @param list<array<string, mixed>> $approvals
     * @return list<array<string, mixed>>
     */
    public function grant(array $approvals, string $stageId, string $actorId, string $atUtc): array
    {
        foreach ($approvals as $i => $stage) {
            if (($stage['stageId'] ?? '') !== $stageId) { continue; }
            $grants = is_array($stage['grants'] ?? null) ? $stage['grants'] : [];
            foreach ($grants as $g) {
                if (($g['actorId'] ?? '') === $actorId) { return $approvals; }
            }
            $grants[] = ['actorId' => $actorId, 'atUtc' => $atUtc, 'decision' => 'granted'];
            $min = is_int($stage['minApprovals'] ?? null) ? $stage['minApprovals'] : 1;
            $stage['grants'] = $grants;
            $stage['status'] = count($grants) >= $min ? 'granted' : 'pending';
            $approvals[$i] = $stage;
        }
        return $approvals;
    }

    /**
     * @param list<array<string, mixed>> $approvals
     * @return list<array<string, mixed>>
     */
    public function reject(array $approvals, string $stageId, string $actorId, string $atUtc): array
    {
        foreach ($approvals as $i => $stage) {
            if (($stage['stageId'] ?? '') !== $stageId) { continue; }
            $stage['status'] = 'rejected';
            $grants = is_array($stage['grants'] ?? null) ? $stage['grants'] : [];
            $grants[] = ['actorId' => $actorId, 'atUtc' => $atUtc, 'decision' => 'rejected'];
            $stage['grants'] = $grants;
            $approvals[$i] = $stage;
        }
        return $approvals;
    }

    /** @param list<array<string, mixed>> $approvals */
    public function satisfied(array $approvals): bool
    {
        if ($approvals === []) { return false; }
        foreach ($approvals as $stage) {
            if (($stage['status'] ?? '') !== 'granted') { return false; }
        }
        return true;
    }
}
