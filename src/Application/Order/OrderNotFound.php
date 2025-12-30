<?php

declare(strict_types=1);

namespace App\Application\Order;

use RuntimeException;

final class OrderNotFound extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Order "%s" not found.', $id));
    }
}
