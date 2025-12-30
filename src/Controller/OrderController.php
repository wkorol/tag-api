<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Order\Command\CreateOrder;
use App\Application\Order\Command\CreateOrderHandler;
use App\Application\Order\Command\DeleteOrder;
use App\Application\Order\Command\DeleteOrderHandler;
use App\Application\Order\Command\EditOrder;
use App\Application\Order\Command\EditOrderHandler;
use App\Application\Order\OrderNotFound;
use App\Domain\Order\CarType;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use ValueError;

final class OrderController
{
    #[Route('/api/orders', name: 'orders_create', methods: ['POST'])]
    public function create(
        Request $request,
        CreateOrderHandler $handler
    ): JsonResponse {
        $data = $this->readJson($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        try {
            $command = new CreateOrder(
                $this->carTypeFrom($data),
                $this->stringFrom($data, 'pickupAddress'),
                $this->stringFrom($data, 'proposedPrice'),
                $this->dateFrom($data),
                $this->stringFrom($data, 'pickupTime'),
                $this->stringFrom($data, 'flightNumber'),
                $this->stringFrom($data, 'fullName'),
                $this->stringFrom($data, 'emailAddress'),
                $this->stringFrom($data, 'phoneNumber'),
                $this->stringFrom($data, 'additionalNotes', true)
            );
        } catch (InvalidArgumentException|ValueError $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $id = ($handler)($command);

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/api/orders/{id}', name: 'orders_edit', methods: ['PUT'])]
    public function edit(
        string $id,
        Request $request,
        EditOrderHandler $handler
    ): JsonResponse {
        $data = $this->readJson($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        try {
            $command = new EditOrder(
                $this->uuidFrom($id),
                $this->carTypeFrom($data),
                $this->stringFrom($data, 'pickupAddress'),
                $this->stringFrom($data, 'proposedPrice'),
                $this->dateFrom($data),
                $this->stringFrom($data, 'pickupTime'),
                $this->stringFrom($data, 'flightNumber'),
                $this->stringFrom($data, 'fullName'),
                $this->stringFrom($data, 'emailAddress'),
                $this->stringFrom($data, 'phoneNumber'),
                $this->stringFrom($data, 'additionalNotes', true)
            );

            ($handler)($command);
        } catch (OrderNotFound $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (InvalidArgumentException|ValueError $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/orders/{id}', name: 'orders_delete', methods: ['DELETE'])]
    public function delete(
        string $id,
        DeleteOrderHandler $handler
    ): JsonResponse {
        try {
            ($handler)(new DeleteOrder($this->uuidFrom($id)));
        } catch (OrderNotFound $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (InvalidArgumentException|ValueError $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function readJson(Request $request): array|JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return $this->errorResponse('Invalid JSON payload.', Response::HTTP_BAD_REQUEST);
        }

        return $payload;
    }

    private function carTypeFrom(array $data): CarType
    {
        if (!array_key_exists('carType', $data)) {
            throw new InvalidArgumentException('Missing "carType".');
        }

        return CarType::from((int) $data['carType']);
    }

    private function dateFrom(array $data): DateTimeImmutable
    {
        $value = $this->stringFrom($data, 'date');

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Invalid "date" value.');
        }
    }

    private function uuidFrom(string $id): Uuid
    {
        try {
            return Uuid::fromString($id);
        } catch (ValueError $exception) {
            throw new InvalidArgumentException('Invalid "id" value.');
        }
    }

    private function stringFrom(array $data, string $key, bool $allowEmpty = false): string
    {
        if (!array_key_exists($key, $data)) {
            if ($allowEmpty) {
                return '';
            }

            throw new InvalidArgumentException(sprintf('Missing "%s".', $key));
        }

        $value = $data[$key];
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be a string.', $key));
        }

        $value = trim($value);

        if (!$allowEmpty && $value == '') {
            throw new InvalidArgumentException(sprintf('Field "%s" cannot be empty.', $key));
        }

        return $value;
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
