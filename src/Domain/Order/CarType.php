<?php

declare(strict_types=1);

namespace App\Domain\Order;

enum CarType: int
{
    case Bus = 1;
    case Standard = 2;
}
