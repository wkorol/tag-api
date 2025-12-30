<?php

declare(strict_types=1);

namespace App\Application\Order\Command;

use App\Domain\Order\CarType;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

final class EditOrder
{
    public function __construct(
        public readonly Uuid $id,
        public readonly CarType $carType,
        public readonly string $pickupAddress,
        public readonly string $proposedPrice,
        public readonly DateTimeImmutable $date,
        public readonly string $pickupTime,
        public readonly string $flightNumber,
        public readonly string $fullName,
        public readonly string $emailAddress,
        public readonly string $phoneNumber,
        public readonly string $additionalNotes
    ) {
    }
}
