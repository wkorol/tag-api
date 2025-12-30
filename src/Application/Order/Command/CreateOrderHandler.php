<?php

declare(strict_types=1);

namespace App\Application\Order\Command;

use App\Domain\Order\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class CreateOrderHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function __invoke(CreateOrder $command): string
    {
        $order = new Order(
            Uuid::v4(),
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

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order->id()->toRfc4122();
    }
}
