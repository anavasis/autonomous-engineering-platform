<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Model;

final class MissionGraph
{
    /**
     * @param list<ProgramNode> $nodes
     * @param list<array{from: string, to: string, type: string}> $edges
     */
    public function __construct(
        private array $nodes = [],
        private array $edges = [],
        private int $generation = 1,
    ) {
    }

    /** @return list<ProgramNode> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** @return list<array{from: string, to: string, type: string}> */
    public function edges(): array
    {
        return $this->edges;
    }

    public function generation(): int
    {
        return $this->generation;
    }

    public function find(string $nodeId): ?ProgramNode
    {
        foreach ($this->nodes as $node) {
            if ($node->nodeId() === $nodeId) {
                return $node;
            }
        }

        return null;
    }

    public function replaceNode(ProgramNode $node): self
    {
        $nodes = [];
        foreach ($this->nodes as $existing) {
            $nodes[] = $existing->nodeId() === $node->nodeId() ? $node : $existing;
        }

        return new self($nodes, $this->edges, $this->generation);
    }

    public function withGeneration(int $generation): self
    {
        return new self($this->nodes, $this->edges, $generation);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'generation' => $this->generation,
            'nodes' => array_map(static fn (ProgramNode $n) => $n->toArray(), $this->nodes),
            'edges' => $this->edges,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $nodes = [];
        if (isset($data['nodes']) && is_array($data['nodes'])) {
            foreach ($data['nodes'] as $n) {
                if (is_array($n)) {
                    $nodes[] = ProgramNode::fromArray($n);
                }
            }
        }
        $edges = [];
        if (isset($data['edges']) && is_array($data['edges'])) {
            foreach ($data['edges'] as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $from = is_string($e['from'] ?? null) ? $e['from'] : '';
                $to = is_string($e['to'] ?? null) ? $e['to'] : '';
                $type = is_string($e['type'] ?? null) ? $e['type'] : 'succeeds_before';
                if ($from !== '' && $to !== '') {
                    $edges[] = ['from' => $from, 'to' => $to, 'type' => $type];
                }
            }
        }

        return new self($nodes, $edges, is_int($data['generation'] ?? null) ? $data['generation'] : 1);
    }
}
