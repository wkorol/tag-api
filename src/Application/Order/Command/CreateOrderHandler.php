<?php

declare(strict_types=1);

namespace App\Application\Order\Command;

use App\Domain\Order\Order;
use App\Application\Order\Notification\OrderEmailSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class CreateOrderHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderEmailSender $emailSender
    ) {
    }

    public function __invoke(CreateOrder $command): Order
    {
        $confirmationToken = bin2hex(random_bytes(16));
        $customerAccessToken = bin2hex(random_bytes(16));
        $generatedId = $this->generateGeneratedId();

        $order = new Order(
            Uuid::v4(),
            $generatedId,
            $command->carType,
            $command->pickupAddress,
            $command->proposedPrice,
            $command->date,
            $command->pickupTime,
            $command->flightNumber,
            $command->fullName,
            $command->emailAddress,
            $command->phoneNumber,
            $command->additionalNotes,
            $command->locale,
            Order::STATUS_PENDING,
            $confirmationToken,
            $customerAccessToken,
            null,
            null,
            null,
            null,
            null
        );

        $this->entityManager->persist($order);
        $this->entityManager->flush();
        $this->emailSender->sendOrderCreatedToCustomer($order);
        $this->emailSender->sendOrderCreatedToAdmin($order);

        return $order;
    }

    private function generateGeneratedId(): string
    {
        $repository = $this->entityManager->getRepository(Order::class);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $candidate = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if ($repository->findOneBy(['generatedId' => $candidate]) === null) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to generate a unique order number.');
    }
}
