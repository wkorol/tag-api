<?php

declare(strict_types=1);

namespace App\Domain\Order;

use DateTimeImmutable;
use InvalidArgumentException;

final class Order
{
    private string $id;
    private CarType $carType;
    private string $pickupAddress;
    private string $proposedPrice;
    private DateTimeImmutable $date;
    private string $pickupTime;
    private string $flightNumber;
    private string $fullName;
    private string $emailAddress;
    private string $phoneNumber;
    private string $additionalNotes;

    public function __construct(
        string $id,
        CarType $carType,
        string $pickupAddress,
        string $proposedPrice,
        DateTimeImmutable $date,
        string $pickupTime,
        string $flightNumber,
        string $fullName,
        string $emailAddress,
        string $phoneNumber,
        string $additionalNotes
    ) {
        $this->assertUuid($id);
        $this->assertPickupTime($pickupTime);

        $this->id = $id;
        $this->carType = $carType;
        $this->pickupAddress = $pickupAddress;
        $this->proposedPrice = $proposedPrice;
        $this->date = $date;
        $this->pickupTime = $pickupTime;
        $this->flightNumber = $flightNumber;
        $this->fullName = $fullName;
        $this->emailAddress = $emailAddress;
        $this->phoneNumber = $phoneNumber;
        $this->additionalNotes = $additionalNotes;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function carType(): CarType
    {
        return $this->carType;
    }

    public function pickupAddress(): string
    {
        return $this->pickupAddress;
    }

    public function proposedPrice(): string
    {
        return $this->proposedPrice;
    }

    public function date(): DateTimeImmutable
    {
        return $this->date;
    }

    public function pickupTime(): string
    {
        return $this->pickupTime;
    }

    public function flightNumber(): string
    {
        return $this->flightNumber;
    }

    public function fullName(): string
    {
        return $this->fullName;
    }

    public function emailAddress(): string
    {
        return $this->emailAddress;
    }

    public function phoneNumber(): string
    {
        return $this->phoneNumber;
    }

    public function additionalNotes(): string
    {
        return $this->additionalNotes;
    }

    private function assertUuid(string $id): void
    {
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $id)) {
            throw new InvalidArgumentException('Order id must be a valid UUID.');
        }
    }

    private function assertPickupTime(string $pickupTime): void
    {
        if (!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $pickupTime)) {
            throw new InvalidArgumentException('Pickup time must be in HH:MM format.');
        }
    }
}
