<?php

declare(strict_types=1);

namespace App\Application\Order\Command;

use Symfony\Component\Uid\Uuid;

final class DeleteOrder
{
    public function __construct(
        public readonly Uuid $id
    ) {
    }
}
