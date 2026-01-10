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
                $this->phoneFrom($data),
                $this->stringFrom($data, 'additionalNotes', true),
                $this->localeFrom($request, $data)
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
            $accessToken = $this->accessTokenFrom($data);
            if ($accessToken !== null) {
                $this->assertAccessTokenMatches($order, $accessToken);
            } else {
                $currentEmail = array_key_exists('currentEmailAddress', $data)
                    ? $this->stringFrom($data, 'currentEmailAddress')
                    : $this->stringFrom($data, 'emailAddress');
                $this->assertEmailMatches($order, $currentEmail);
            }
            $this->assertEditable($order);
            $wasConfirmed = $order->status() === Order::STATUS_CONFIRMED;

            $adminUpdateRequest = isset($data['adminUpdateRequest']) && $data['adminUpdateRequest'] === true;
            $adminUpdateFields = $data['adminUpdateFields'] ?? [];

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
                $this->phoneFrom($data),
                $this->stringFrom($data, 'additionalNotes', true),
                $adminUpdateRequest,
                $this->optionalLocaleFrom($data)
            );

            ($handler)($command);
            if ($wasConfirmed && $order->status() === Order::STATUS_PENDING) {
                $emailSender->sendOrderUpdatedToAdmin($order);
            } elseif ($adminUpdateRequest) {
                $emailSender->sendCustomerUpdatedRequestToAdmin($order, is_array($adminUpdateFields) ? $adminUpdateFields : []);
            }
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
            $accessToken = $this->accessTokenFrom($data);
            if ($accessToken !== null) {
                $this->assertAccessTokenMatches($order, $accessToken);
            } else {
                $this->assertEmailMatches($order, $this->stringFrom($data, 'emailAddress'));
            }
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

            $redirectUrl = $this->adminFrontendBaseUrl() . '/admin/orders/' . $order->id()->toRfc4122();
            if ($this->adminPanelToken() !== '') {
                $redirectUrl .= '?token=' . urlencode($this->adminPanelToken());
            }
            return new RedirectResponse($redirectUrl);
        }

        $redirectUrl = $this->adminFrontendBaseUrl() . '/admin/orders/' . $order->id()->toRfc4122();
        if ($this->adminPanelToken() !== '') {
            $redirectUrl .= '?token=' . urlencode($this->adminPanelToken()) . '&status=processed';
        }
        return new RedirectResponse($redirectUrl);
    }

    #[Route('/admin/orders/{id}', name: 'admin_orders_manage', methods: ['GET'])]
    public function manage(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $token = $request->query->get('token');
        $frontendUrl = $this->adminFrontendBaseUrl() . '/admin/orders/' . $id;
        if (is_string($token) && trim($token) !== '') {
            $frontendUrl .= '?token=' . urlencode($token);
        }

        return new RedirectResponse($frontendUrl);
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

        $action = $request->request->get('action');
        $message = $request->request->get('message');
        $price = $request->request->get('price');

        $result = $this->handleAdminDecision(
            $id,
            $token,
            $action,
            $message,
            $price,
            $entityManager,
            $emailSender
        );

        $frontendUrl = rtrim($this->frontendBaseUrl(), '/') . '/admin/orders/' . $id . '?token=' . urlencode($token);
        if ($result->getStatusCode() === Response::HTTP_OK) {
            $frontendUrl .= '&status=updated';
        }

        return new RedirectResponse($frontendUrl);
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

        $redirectUrl = $this->customerAccessUrl($order, ['priceAccepted' => 1]);
        return new RedirectResponse($redirectUrl);
    }

    #[Route('/api/admin/orders', name: 'admin_orders_list', methods: ['GET'])]
    public function adminList(
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $token = $request->query->get('token');
        if (!is_string($token) || !$this->isAdminTokenValid($token)) {
            return $this->errorResponse('Invalid admin token.', Response::HTTP_FORBIDDEN);
        }

        $orders = $entityManager->getRepository(Order::class)->findBy([], ['date' => 'DESC']);
        $payload = array_map(fn (Order $order) => $this->adminOrderPayload($order), $orders);

        return new JsonResponse(['orders' => $payload]);
    }

    #[Route('/api/admin/orders/{id}', name: 'admin_orders_show', methods: ['GET'])]
    public function adminShow(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        try {
            $orderId = $this->uuidFrom($id);
            $order = $this->fetchOrder($entityManager, $orderId);
        } catch (OrderNotFound|InvalidArgumentException|ValueError $exception) {
            return $this->errorResponse('Invalid order.', Response::HTTP_NOT_FOUND);
        }

        $token = $request->query->get('token');
        if (!is_string($token) || !$this->canAccessAdminOrder($order, $token)) {
            return $this->errorResponse('Invalid admin token.', Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(array_merge(
            $this->adminOrderPayload($order),
            ['canViewAll' => $this->isAdminTokenValid($token)]
        ));
    }

    #[Route('/api/admin/orders/{id}/decision', name: 'admin_orders_decision_api', methods: ['POST'])]
    public function adminDecision(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager,
        \App\Application\Order\Notification\OrderEmailSender $emailSender
    ): JsonResponse {
        $token = $request->query->get('token');
        if (!is_string($token) || trim($token) === '') {
            return $this->errorResponse('Missing confirmation token.', Response::HTTP_BAD_REQUEST);
        }

        $data = $this->readJson($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $action = $data['action'] ?? null;
        $message = $data['message'] ?? null;
        $price = $data['price'] ?? null;

        return $this->handleAdminDecision(
            $id,
            $token,
            $action,
            $message,
            $price,
            $entityManager,
            $emailSender
        );
    }

    #[Route('/api/admin/orders/{id}/request-update', name: 'admin_orders_request_update', methods: ['POST'])]
    public function adminRequestUpdate(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager,
        \App\Application\Order\Notification\OrderEmailSender $emailSender
    ): JsonResponse {
        $token = $request->query->get('token');
        if (!is_string($token) || !$this->isAdminTokenValid($token)) {
            return $this->errorResponse('Invalid admin token.', Response::HTTP_FORBIDDEN);
        }

        $data = $this->readJson($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $fields = $data['fields'] ?? null;
        if (!is_array($fields) || $fields === []) {
            return $this->errorResponse('Missing fields.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $orderId = $this->uuidFrom($id);
            $order = $this->fetchOrder($entityManager, $orderId);
        } catch (OrderNotFound|InvalidArgumentException|ValueError $exception) {
            return $this->errorResponse('Invalid order.', Response::HTTP_NOT_FOUND);
        }

        $emailSender->sendOrderUpdateRequestToCustomer($order, $fields);

        return new JsonResponse(['status' => 'requested']);
    }

    #[Route('/api/admin/orders/{id}/fulfillment', name: 'admin_orders_fulfillment', methods: ['POST'])]
    public function adminFulfillment(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $token = $request->query->get('token');
        if (!is_string($token) || trim($token) === '') {
            return $this->errorResponse('Missing confirmation token.', Response::HTTP_BAD_REQUEST);
        }

        $data = $this->readJson($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $action = $data['action'] ?? null;
        if (!is_string($action) || !in_array($action, ['completed', 'failed'], true)) {
            return $this->errorResponse('Invalid action.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $orderId = $this->uuidFrom($id);
            $order = $this->fetchOrder($entityManager, $orderId);
        } catch (OrderNotFound|InvalidArgumentException|ValueError $exception) {
            return $this->errorResponse('Invalid order.', Response::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessAdminOrder($order, $token)) {
            return $this->errorResponse('Invalid confirmation token.', Response::HTTP_FORBIDDEN);
        }

        if ($order->status() !== Order::STATUS_CONFIRMED) {
            return $this->errorResponse('Order is not confirmed.', Response::HTTP_BAD_REQUEST);
        }

        if ($action === 'completed') {
            $order->markCompleted();
        } else {
            $order->markFailed();
        }

        $entityManager->flush();

        return new JsonResponse(['status' => $order->status()]);
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

        $redirectUrl = $this->customerAccessUrl($order, ['cancelled' => 1]);

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
            $accessToken = $this->accessTokenFrom($data);
            if ($accessToken !== null) {
                $this->assertAccessTokenMatches($order, $accessToken);
            } else {
                $this->assertEmailMatches($order, $this->stringFrom($data, 'emailAddress'));
            }
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

    private function phoneFrom(array $data): string
    {
        $value = $this->stringFrom($data, 'phoneNumber');
        $normalized = preg_replace('/[\\s\\-()]/', '', $value);
        if ($normalized === null) {
            throw new InvalidArgumentException('Invalid "phoneNumber" value.');
        }

        if (str_starts_with($normalized, '00')) {
            $normalized = '+' . substr($normalized, 2);
        }

        if (!preg_match('/^\\+?[0-9]{7,15}$/', $normalized)) {
            throw new InvalidArgumentException('Invalid "phoneNumber" value.');
        }

        return $normalized;
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }

    private function localeFrom(Request $request, array $data): string
    {
        if (array_key_exists('locale', $data)) {
            $locale = strtolower($this->stringFrom($data, 'locale'));
            if (!in_array($locale, ['en', 'pl', 'de', 'fi', 'no', 'sv', 'da'], true)) {
                throw new InvalidArgumentException('Invalid "locale" value.');
            }

            return $locale;
        }

        $header = $request->headers->get('Accept-Language');
        if (is_string($header)) {
            $first = strtolower(trim(explode(',', $header)[0] ?? ''));
            $first = trim(explode(';', $first)[0] ?? '');
            if (str_starts_with($first, 'pl')) {
                return 'pl';
            }
            if (str_starts_with($first, 'de')) {
                return 'de';
            }
            if (str_starts_with($first, 'fi')) {
                return 'fi';
            }
            if (str_starts_with($first, 'no') || str_starts_with($first, 'nb') || str_starts_with($first, 'nn')) {
                return 'no';
            }
            if (str_starts_with($first, 'sv')) {
                return 'sv';
            }
            if (str_starts_with($first, 'da')) {
                return 'da';
            }
        }

        return 'en';
    }

    private function optionalLocaleFrom(array $data): ?string
    {
        if (!array_key_exists('locale', $data)) {
            return null;
        }

        $locale = strtolower($this->stringFrom($data, 'locale'));
        if (!in_array($locale, ['en', 'pl', 'de', 'fi', 'no', 'sv', 'da'], true)) {
            throw new InvalidArgumentException('Invalid "locale" value.');
        }

        return $locale;
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

    private function accessTokenFrom(array $data): ?string
    {
        if (!array_key_exists('accessToken', $data)) {
            return null;
        }

        $value = $data['accessToken'];
        if (!is_string($value)) {
            throw new InvalidArgumentException('Field "accessToken" must be a string.');
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $value;
    }

    private function assertAccessTokenMatches(Order $order, string $accessToken): void
    {
        $expected = $order->customerAccessToken();
        if ($expected === null || $expected === '' || !hash_equals($expected, $accessToken)) {
            throw new \RuntimeException('Access token does not match this order.');
        }
    }

    private function assertEditable(Order $order): void
    {
        if (!in_array($order->status(), [Order::STATUS_PENDING, Order::STATUS_CONFIRMED], true)) {
            throw new \RuntimeException('This order can no longer be edited.');
        }
    }

    private function backendBaseUrl(): string
    {
        $baseUrl = getenv('BACKEND_BASE_URL') ?: 'http://localhost:8000';
        $trimmed = rtrim($baseUrl, '/');
        if (str_ends_with($trimmed, '/api')) {
            return substr($trimmed, 0, -4);
        }

        return $trimmed;
    }

    private function frontendBaseUrl(): string
    {
        return getenv('FRONTEND_BASE_URL') ?: 'http://localhost:3000';
    }

    private function customerFrontendBaseUrl(Order $order): string
    {
        $base = rtrim($this->frontendBaseUrl(), '/');
        $locale = $order->locale();
        if (!in_array($locale, ['en', 'pl', 'de', 'fi', 'no', 'sv', 'da'], true)) {
            $locale = 'en';
        }

        return $base . '/' . $locale;
    }

    private function customerAccessUrl(Order $order, array $params = []): string
    {
        $query = array_merge(['orderId' => $order->id()->toRfc4122()], $params);
        $token = $order->customerAccessToken();
        if ($token !== null && $token !== '') {
            $query['token'] = $token;
        }

        return $this->customerFrontendBaseUrl($order) . '/?' . http_build_query($query);
    }

    private function adminFrontendBaseUrl(): string
    {
        $base = rtrim($this->frontendBaseUrl(), '/');

        return $base . '/pl';
    }

    private function adminPanelToken(): string
    {
        $value = getenv('ADMIN_PANEL_TOKEN');
        if ($value === false) {
            return '';
        }

        return trim($value);
    }

    private function isAdminTokenValid(string $token): bool
    {
        $adminToken = $this->adminPanelToken();
        if ($adminToken === '') {
            return false;
        }

        return hash_equals($adminToken, $token);
    }

    private function canAccessAdminOrder(Order $order, string $token): bool
    {
        if ($this->isAdminTokenValid($token)) {
            return true;
        }

        $confirmationToken = $order->confirmationToken();
        return $confirmationToken !== null && hash_equals($confirmationToken, $token);
    }

    private function adminOrderPayload(Order $order): array
    {
        return [
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
            'confirmationToken' => $order->confirmationToken(),
            'completionReminderSentAt' => $order->completionReminderSentAt()?->format(DATE_ATOM),
        ];
    }

    private function handleAdminDecision(
        string $id,
        string $token,
        mixed $action,
        mixed $message,
        mixed $price,
        EntityManagerInterface $entityManager,
        \App\Application\Order\Notification\OrderEmailSender $emailSender
    ): JsonResponse {
        if (!is_string($action) || !in_array($action, ['confirm', 'reject', 'price'], true)) {
            return $this->errorResponse('Invalid action.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $orderId = $this->uuidFrom($id);
            $order = $this->fetchOrder($entityManager, $orderId);
        } catch (OrderNotFound|InvalidArgumentException|ValueError $exception) {
            return $this->errorResponse('Invalid order.', Response::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessAdminOrder($order, $token)) {
            return $this->errorResponse('Invalid confirmation token.', Response::HTTP_FORBIDDEN);
        }

        if ($action === 'confirm') {
            if ($order->status() === Order::STATUS_PENDING) {
                $order->confirm();
                $entityManager->flush();
                $emailSender->sendOrderConfirmedToCustomer($order);

                return new JsonResponse(['status' => $order->status()]);
            }

            return $this->errorResponse('Order is already processed.', Response::HTTP_BAD_REQUEST);
        }

        if ($action === 'price') {
            if (!is_string($price) || trim($price) === '') {
                return $this->errorResponse('Missing price.', Response::HTTP_BAD_REQUEST);
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

                return new JsonResponse(['status' => $order->status()]);
            }

            return $this->errorResponse('Order is already processed.', Response::HTTP_BAD_REQUEST);
        }

        $reason = is_string($message) && trim($message) !== ''
            ? trim($message)
            : ($order->locale() === 'pl'
                ? 'Zamówienie zostało odrzucone, ponieważ nie możemy zrealizować go w wybranym terminie.'
                : 'The order was rejected because we cannot fulfill it at the requested time.');

        if ($order->status() === Order::STATUS_PENDING) {
            $order->reject($reason);
            $entityManager->flush();
            $emailSender->sendOrderRejectedToCustomer($order, $reason);

            return new JsonResponse(['status' => $order->status()]);
        }

        return $this->errorResponse('Order is already processed.', Response::HTTP_BAD_REQUEST);
    }
}
