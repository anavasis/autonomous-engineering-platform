<?php

declare(strict_types=1);

namespace Aep\Domain\Mission\ValueObject;

/**
 * Actor who creates or approves mission actions (no secrets).
 */
final class ActorRef
{
    public function __construct(
        private string $type,
        private string $id
    ) {
        $type = trim($type);
        $id = trim($id);
        if ($type === '' || $id === '') {
            throw new \InvalidArgumentException('ActorRef type and id must be non-empty.');
        }
        $this->type = $type;
        $this->id = $id;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function toString(): string
    {
        return $this->type . ':' . $this->id;
    }
}
