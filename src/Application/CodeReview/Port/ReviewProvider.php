<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Port;

use Aep\Application\CodeReview\Model\Patch;
use Aep\Application\CodeReview\Model\ReviewRecord;

interface ReviewProvider
{
    public function id(): string;

    public function displayName(): string;

    public function review(Patch $patch): ReviewRecord;
}
