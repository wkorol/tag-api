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

        if ($order->status() === Order::STATUS_CONFIRMED && !$command->adminUpdateRequest) {
            $token = bin2hex(random_bytes(16));
            $order->markPending($token);
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
        if ($command->locale !== null) {
            $order->updateLocale($command->locale);
        }

        $this->entityManager->flush();
    }
}
