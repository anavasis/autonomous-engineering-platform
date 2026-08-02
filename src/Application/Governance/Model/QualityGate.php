<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Model;

final class QualityGate
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /** @param array<string, mixed> $evidence */
    public function __construct(
        private string $gateId,
        private string $name,
        private string $kind,
        private string $status = self::STATUS_PENDING,
        private array $evidence = [],
        private string $detail = '',
    ) {}

    public function gateId(): string { return $this->gateId; }
    public function name(): string { return $this->name; }
    public function kind(): string { return $this->kind; }
    public function status(): string { return $this->status; }
    public function passed(): bool { return $this->status === self::STATUS_PASSED || $this->status === self::STATUS_SKIPPED; }

    public function withResult(string $status, array $evidence = [], string $detail = ''): self
    {
        $c = clone $this; $c->status = $status; $c->evidence = $evidence; $c->detail = $detail; return $c;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'gateId' => $this->gateId,
            'name' => $this->name,
            'kind' => $this->kind,
            'status' => $this->status,
            'evidence' => $this->evidence,
            'detail' => $this->detail,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['gateId'] ?? null) ? $data['gateId'] : self::makeId(),
            is_string($data['name'] ?? null) ? $data['name'] : 'Gate',
            is_string($data['kind'] ?? null) ? $data['kind'] : 'generic',
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_PENDING,
            is_array($data['evidence'] ?? null) ? $data['evidence'] : [],
            is_string($data['detail'] ?? null) ? $data['detail'] : '',
        );
    }

    public static function makeId(): string { return 'qg_' . bin2hex(random_bytes(4)); }

    /** @return list<self> */
    public static function defaultCatalog(): array
    {
        return [
            new self('qg_tests', 'Tests', 'tests'),
            new self('qg_review', 'Code Review', 'review'),
            new self('qg_coverage', 'Coverage', 'coverage'),
            new self('qg_security', 'Security', 'security'),
            new self('qg_performance', 'Performance', 'performance'),
            new self('qg_documentation', 'Documentation', 'documentation'),
            new self('qg_approval', 'Approval', 'approval'),
        ];
    }
}
