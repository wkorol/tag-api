<?php

declare(strict_types=1);

namespace App\Application\Order\Command;

use App\Application\Order\OrderNotFound;
use App\Domain\Order\Order;
use Doctrine\ORM\EntityManagerInterface;

final class DeleteOrderHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function __invoke(DeleteOrder $command): void
    {
        $order = $this->entityManager->find(Order::class, $command->id);

        if (!$order instanceof Order) {
            throw OrderNotFound::withId($command->id->toRfc4122());
        }

        $this->entityManager->remove($order);
        $this->entityManager->flush();
    }
}
