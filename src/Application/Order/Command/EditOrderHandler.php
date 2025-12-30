<?php

declare(strict_types=1);

namespace App\Application\Order\Command;

use App\Application\Order\OrderNotFound;
use App\Domain\Order\Order;
use Doctrine\ORM\EntityManagerInterface;

final class EditOrderHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function __invoke(EditOrder $command): void
    {
        $order = $this->entityManager->find(Order::class, $command->id);

        if (!$order instanceof Order) {
            throw OrderNotFound::withId($command->id->toRfc4122());
        }

        $order->updateDetails(
            $command->carType,
            $command->pickupAddress,
            $command->proposedPrice,
            $command->date,
            $command->pickupTime,
            $command->flightNumber,
            $command->fullName,
            $command->emailAddress,
            $command->phoneNumber,
            $command->additionalNotes
        );

        $this->entityManager->flush();
    }
}
