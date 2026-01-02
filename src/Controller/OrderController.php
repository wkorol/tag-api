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
use App\Domain\Order\Order;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\RedirectResponse;
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

        $order = ($handler)($command);

        return new JsonResponse([
            'id' => $order->id()->toRfc4122(),
            'generatedId' => $order->generatedId(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/orders/{id}', name: 'orders_edit', methods: ['PUT'])]
    public function edit(
        string $id,
        Request $request,
        EditOrderHandler $handler,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = $this->readJson($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        try {
            $orderId = $this->uuidFrom($id);
            $order = $this->fetchOrder($entityManager, $orderId);
            $this->assertEmailMatches($order, $this->stringFrom($data, 'emailAddress'));
            $this->assertEditable($order);

            $command = new EditOrder(
                $orderId,
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
        } catch (\RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/orders/{id}', name: 'orders_show', methods: ['GET'])]
    public function show(
        string $id,
        EntityManagerInterface $entityManager,
        Request $request
    ): JsonResponse {
        try {
            $orderId = $this->uuidFrom($id);
        } catch (InvalidArgumentException|ValueError $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        try {
            $order = $this->fetchOrder($entityManager, $orderId);
            $emailAddress = $request->query->get('emailAddress');
            if (!is_string($emailAddress) || trim($emailAddress) === '') {
                return $this->errorResponse('Missing "emailAddress".', Response::HTTP_BAD_REQUEST);
            }
            $this->assertEmailMatches($order, $emailAddress);
        } catch (OrderNotFound $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (\RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'id' => $order->id()->toRfc4122(),
            'generatedId' => $order->generatedId(),
            'carType' => $order->carType()->value,
            'pickupAddress' => $order->pickupAddress(),
            'proposedPrice' => $order->proposedPrice(),
            'date' => $order->date()->format('Y-m-d'),
            'pickupTime' => $order->pickupTime(),
            'flightNumber' => $order->flightNumber(),
            'fullName' => $order->fullName(),
            'emailAddress' => $order->emailAddress(),
            'phoneNumber' => $order->phoneNumber(),
            'additionalNotes' => $order->additionalNotes(),
            'status' => $order->status(),
            'rejectionReason' => $order->rejectionReason(),
            'pendingPrice' => $order->pendingPrice(),
        ]);
    }

    #[Route('/api/orders/{id}/access', name: 'orders_access', methods: ['POST'])]
    public function access(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = $this->readJson($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        try {
            $orderId = $this->uuidFrom($id);
            $order = $this->fetchOrder($entityManager, $orderId);
            $this->assertEmailMatches($order, $this->stringFrom($data, 'emailAddress'));
        } catch (OrderNotFound $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (InvalidArgumentException|ValueError $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'id' => $order->id()->toRfc4122(),
            'generatedId' => $order->generatedId(),
            'carType' => $order->carType()->value,
            'pickupAddress' => $order->pickupAddress(),
            'proposedPrice' => $order->proposedPrice(),
            'date' => $order->date()->format('Y-m-d'),
            'pickupTime' => $order->pickupTime(),
            'flightNumber' => $order->flightNumber(),
            'fullName' => $order->fullName(),
            'emailAddress' => $order->emailAddress(),
            'phoneNumber' => $order->phoneNumber(),
            'additionalNotes' => $order->additionalNotes(),
            'status' => $order->status(),
            'rejectionReason' => $order->rejectionReason(),
            'pendingPrice' => $order->pendingPrice(),
        ]);
    }

    #[Route('/api/orders/{id}/confirm', name: 'orders_confirm', methods: ['GET'])]
    public function confirm(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager,
        \App\Application\Order\Notification\OrderEmailSender $emailSender
    ): Response {
        $token = $request->query->get('token');
        if (!is_string($token) || trim($token) === '') {
            return new Response('Missing confirmation token.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $orderId = $this->uuidFrom($id);
            $order = $this->fetchOrder($entityManager, $orderId);
        } catch (OrderNotFound|InvalidArgumentException|ValueError $exception) {
            return new Response('Invalid order.', Response::HTTP_NOT_FOUND);
        }

        if ($order->confirmationToken() !== $token) {
            return new Response('Invalid confirmation token.', Response::HTTP_FORBIDDEN);
        }

        if ($order->status() === Order::STATUS_PENDING) {
            $order->confirm();
            $entityManager->flush();
            $emailSender->sendOrderConfirmedToCustomer($order);

            return new Response('Order confirmed. You can close this page now.');
        }

        return new Response('Order is already processed.');
    }

    #[Route('/admin/orders/{id}', name: 'admin_orders_manage', methods: ['GET'])]
    public function manage(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $token = $request->query->get('token');
        if (!is_string($token) || trim($token) === '') {
            return new Response('Missing confirmation token.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $orderId = $this->uuidFrom($id);
            $order = $this->fetchOrder($entityManager, $orderId);
        } catch (OrderNotFound|InvalidArgumentException|ValueError $exception) {
            return new Response('Invalid order.', Response::HTTP_NOT_FOUND);
        }

        if ($order->confirmationToken() !== $token) {
            return new Response('Invalid confirmation token.', Response::HTTP_FORBIDDEN);
        }

        $statusMessage = match ($order->status()) {
            Order::STATUS_CONFIRMED => 'This order is already confirmed.',
            Order::STATUS_REJECTED => 'This order has already been rejected.',
            Order::STATUS_PRICE_PROPOSED => 'A new price has been proposed to the customer. Waiting for their response.',
            default => null,
        };

        $body = '<!doctype html><html lang="pl"><head><meta charset="utf-8">';
        $body .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $body .= '<title>Taxi Airport Gdańsk – zarządzanie zleceniem</title>';
        $body .= '<style>';
        $body .= 'body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:linear-gradient(135deg,#f8f9fb,#e9f0ff);padding:32px;color:#0f172a;}';
        $body .= '.card{max-width:820px;margin:0 auto;background:#fff;border-radius:18px;padding:28px;';
        $body .= 'box-shadow:0 20px 45px rgba(15,23,42,.12);}';
        $body .= '.header{display:flex;align-items:center;gap:12px;margin-bottom:20px;}';
        $body .= '.badge{background:#0b5ed7;color:#fff;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:600;}';
        $body .= '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-top:16px;}';
        $body .= '.tile{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;}';
        $body .= 'label{font-weight:600;}';
        $body .= 'textarea{width:100%;margin-top:8px;padding:10px;border-radius:10px;border:1px solid #cbd5f5;font-size:14px;}';
        $body .= 'button{padding:12px 18px;border-radius:10px;border:none;cursor:pointer;font-weight:600;}';
        $body .= '.primary{background:#0b5ed7;color:#fff;}';
        $body .= '.danger{background:#dc3545;color:#fff;}';
        $body .= '.row{display:flex;flex-wrap:wrap;gap:12px;margin-top:16px;}';
        $body .= '.note{margin-top:12px;font-size:13px;color:#64748b;}';
        $body .= '</style></head><body>';
        $body .= '<div class="card">';
        $body .= '<div class="header"><div class="badge">Taxi Airport Gdańsk</div>';
        $body .= '<h2>Zlecenie #' . $order->generatedId() . '</h2></div>';
        $body .= '<div class="grid">';
        $body .= '<div class="tile"><strong>ID zamówienia</strong><br>' . $order->id()->toRfc4122() . '</div>';
        $body .= '<div class="tile"><strong>Klient</strong><br>' . $order->fullName() . '<br>' . $order->emailAddress() . '</div>';
        $body .= '<div class="tile"><strong>Termin</strong><br>' . $order->date()->format('Y-m-d') . ' ' . $order->pickupTime() . '</div>';
        $body .= '<div class="tile"><strong>Status</strong><br>' . $order->status() . '</div>';
        $body .= '<div class="tile"><strong>Cena</strong><br>' . htmlspecialchars($order->proposedPrice(), ENT_QUOTES) . '</div>';
        $body .= '</div>';

        if ($statusMessage !== null) {
            $body .= '<p class="note"><strong>' . $statusMessage . '</strong></p>';
            if ($order->status() === Order::STATUS_REJECTED && $order->rejectionReason()) {
                $body .= '<div class="tile" style="margin-top:16px;"><strong>Powód odrzucenia</strong><br>'
                    . nl2br(htmlspecialchars($order->rejectionReason(), ENT_QUOTES)) . '</div>';
            }
        } else {
            $body .= '<form method="post" action="' . rtrim($this->backendBaseUrl(), '/') . '/admin/orders/' . $order->id()->toRfc4122() . '/decision?token=' . $token . '">';
            $body .= '<label for="price">Proponowana cena</label><br>';
            $body .= '<input id="price" name="price" value="' . htmlspecialchars($order->proposedPrice(), ENT_QUOTES) . '" style="width:100%;margin-top:8px;padding:10px;border-radius:10px;border:1px solid #cbd5f5;">';
            $body .= '<label for="message">Powód odrzucenia (opcjonalnie)</label><br>';
            $body .= '<textarea id="message" name="message" rows="4" style="width:100%;margin-top:8px;"></textarea>';
            $body .= '<div class="row">';
            $body .= '<button class="primary" type="submit" name="action" value="confirm">Akceptuj zlecenie</button>';
            $body .= '<button class="primary" type="submit" name="action" value="price">Zaproponuj nową cenę</button>';
            $body .= '<button class="danger" type="submit" name="action" value="reject">Odrzuć zlecenie</button>';
            $body .= '</div></form>';
            $body .= '<p class="note">Jeśli nie podasz powodu, klient dostanie standardowy komunikat o braku dostępności.</p>';
        }

        $body .= '</div></body></html>';

        return new Response($body);
    }

    #[Route('/admin/orders/{id}/decision', name: 'admin_orders_decision', methods: ['POST'])]
    public function decision(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager,
        \App\Application\Order\Notification\OrderEmailSender $emailSender
    ): Response {
        $token = $request->query->get('token');
        if (!is_string($token) || trim($token) === '') {
            return new Response('Missing confirmation token.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $orderId = $this->uuidFrom($id);
            $order = $this->fetchOrder($entityManager, $orderId);
        } catch (OrderNotFound|InvalidArgumentException|ValueError $exception) {
            return new Response('Invalid order.', Response::HTTP_NOT_FOUND);
        }

        if ($order->confirmationToken() !== $token) {
            return new Response('Invalid confirmation token.', Response::HTTP_FORBIDDEN);
        }

        $action = $request->request->get('action');
        if (!is_string($action) || !in_array($action, ['confirm', 'reject', 'price'], true)) {
            return new Response('Invalid action.', Response::HTTP_BAD_REQUEST);
        }

        if ($action === 'confirm') {
            if ($order->status() === Order::STATUS_PENDING) {
                $order->confirm();
                $entityManager->flush();
                $emailSender->sendOrderConfirmedToCustomer($order);

                return new Response('Order confirmed. You can close this page now.');
            }

            return new Response('Order is already processed.');
        }

        if ($action === 'price') {
            $price = $request->request->get('price');
            if (!is_string($price) || trim($price) === '') {
                return new Response('Missing price.', Response::HTTP_BAD_REQUEST);
            }

            if ($order->status() === Order::STATUS_PENDING) {
                $tokenValue = bin2hex(random_bytes(16));
                $order->proposePrice(trim($price), $tokenValue);
                $entityManager->flush();

                $acceptUrl = rtrim($this->backendBaseUrl(), '/') . '/api/orders/' . $order->id()->toRfc4122()
                    . '/price/accept?token=' . $tokenValue;
                $rejectUrl = rtrim($this->backendBaseUrl(), '/') . '/api/orders/' . $order->id()->toRfc4122()
                    . '/price/reject?token=' . $tokenValue;
                $emailSender->sendPriceProposalToCustomer($order, trim($price), $acceptUrl, $rejectUrl);

                return new Response('Price proposal sent to customer.');
            }

            return new Response('Order is already processed.');
        }

        $message = $request->request->get('message');
        $reason = is_string($message) && trim($message) !== ''
            ? trim($message)
            : 'The order was rejected because we cannot fulfill it at the requested time.';

        if ($order->status() === Order::STATUS_PENDING) {
            $order->reject($reason);
            $entityManager->flush();
            $emailSender->sendOrderRejectedToCustomer($order, $reason);

            return new Response('Order rejected. The customer has been notified.');
        }

        return new Response('Order is already processed.');
    }

    #[Route('/api/orders/{id}/price/accept', name: 'orders_price_accept', methods: ['GET'])]
    public function acceptPrice(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager,
        \App\Application\Order\Notification\OrderEmailSender $emailSender
    ): Response {
        $token = $request->query->get('token');
        if (!is_string($token) || trim($token) === '') {
            return new Response('Missing price token.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $orderId = $this->uuidFrom($id);
            $order = $this->fetchOrder($entityManager, $orderId);
        } catch (OrderNotFound|InvalidArgumentException|ValueError $exception) {
            return new Response('Invalid order.', Response::HTTP_NOT_FOUND);
        }

        if ($order->priceProposalToken() !== $token || $order->status() !== Order::STATUS_PRICE_PROPOSED) {
            return new Response('Invalid price confirmation token.', Response::HTTP_FORBIDDEN);
        }

        $order->acceptProposedPrice();
        $entityManager->flush();
        $emailSender->sendOrderConfirmedToCustomer($order);

        return new Response('Price accepted. Your order is confirmed.');
    }

    #[Route('/api/orders/{id}/price/reject', name: 'orders_price_reject', methods: ['GET'])]
    public function rejectPrice(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager,
        DeleteOrderHandler $handler,
        \App\Application\Order\Notification\OrderEmailSender $emailSender
    ): Response {
        $token = $request->query->get('token');
        if (!is_string($token) || trim($token) === '') {
            return new Response('Missing price token.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $orderId = $this->uuidFrom($id);
            $order = $this->fetchOrder($entityManager, $orderId);
        } catch (OrderNotFound|InvalidArgumentException|ValueError $exception) {
            return new Response('Invalid order.', Response::HTTP_NOT_FOUND);
        }

        if ($order->priceProposalToken() !== $token || $order->status() !== Order::STATUS_PRICE_PROPOSED) {
            return new Response('Invalid price rejection token.', Response::HTTP_FORBIDDEN);
        }

        $emailSender->sendOrderCancelledToCustomer($order);
        $emailSender->sendOrderCancelledToAdmin($order);
        ($handler)(new DeleteOrder($orderId));

        $redirectUrl = rtrim($this->frontendBaseUrl(), '/') . '/?orderId=' . $order->id()->toRfc4122() . '&cancelled=1';

        return new RedirectResponse($redirectUrl);
    }

    #[Route('/api/orders/{id}', name: 'orders_delete', methods: ['DELETE'])]
    public function delete(
        string $id,
        DeleteOrderHandler $handler,
        Request $request,
        EntityManagerInterface $entityManager,
        \App\Application\Order\Notification\OrderEmailSender $emailSender
    ): JsonResponse {
        $data = $this->readJson($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        try {
            $orderId = $this->uuidFrom($id);
            $order = $this->fetchOrder($entityManager, $orderId);
            $this->assertEmailMatches($order, $this->stringFrom($data, 'emailAddress'));
            ($handler)(new DeleteOrder($orderId));
            $emailSender->sendOrderCancelledToCustomer($order);
            $emailSender->sendOrderCancelledToAdmin($order);
        } catch (OrderNotFound $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (InvalidArgumentException|ValueError $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_FORBIDDEN);
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

    private function fetchOrder(EntityManagerInterface $entityManager, Uuid $id): Order
    {
        $order = $entityManager->find(Order::class, $id);
        if (!$order instanceof Order) {
            throw OrderNotFound::withId($id->toRfc4122());
        }

        return $order;
    }

    private function assertEmailMatches(Order $order, string $emailAddress): void
    {
        if (strcasecmp($order->emailAddress(), $emailAddress) !== 0) {
            throw new \RuntimeException('Email address does not match this order.');
        }
    }

    private function assertEditable(Order $order): void
    {
        if ($order->status() !== Order::STATUS_PENDING) {
            throw new \RuntimeException('This order can no longer be edited.');
        }
    }

    private function backendBaseUrl(): string
    {
        return getenv('BACKEND_BASE_URL') ?: 'http://localhost:8000';
    }

    private function frontendBaseUrl(): string
    {
        return getenv('FRONTEND_BASE_URL') ?: 'http://localhost:5173';
    }
}
