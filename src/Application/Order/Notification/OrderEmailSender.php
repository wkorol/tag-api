<?php

declare(strict_types=1);

namespace App\Application\Order\Notification;

use App\Domain\Order\Order;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Throwable;

final class OrderEmailSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.order_email_from%')]
        private readonly string $fromAddress,
        #[Autowire('%app.order_admin_email%')]
        private readonly string $adminAddress,
        #[Autowire('%app.admin_panel_token%')]
        private readonly string $adminPanelToken,
        #[Autowire('%app.frontend_base_url%')]
        private readonly string $frontendBaseUrl,
        #[Autowire('%app.backend_base_url%')]
        private readonly string $backendBaseUrl
    ) {
    }

    public function sendOrderCreatedToCustomer(Order $order): void
    {
        $editUrl = rtrim($this->frontendBaseUrl, '/') . '/?orderId=' . $order->id()->toRfc4122();
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($order->emailAddress())
            ->subject($this->subject('Order received', $order))
            ->text(implode("\n", [
                'Thank you for your booking.',
                '',
                'You can edit or cancel your order using the link below:',
                $editUrl,
                '',
                ...$this->orderDetailsLines($order),
            ]));

        $this->sendEmail($message, $order, 'order confirmation');
    }

    public function sendOrderCreatedToAdmin(Order $order): void
    {
        $manageUrl = $this->adminManageUrl($order);
        $listUrl = $this->adminPanelToken !== ''
            ? rtrim($this->frontendBaseUrl, '/') . '/admin?token=' . $this->adminPanelToken
            : null;
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($this->adminAddress)
            ->subject($this->subject('New order awaiting confirmation', $order))
            ->text(implode("\n", [
                'A new order has been placed.',
                '',
                'Manage (confirm/reject) using this link:',
                $manageUrl,
                '',
                ...($listUrl ? ['All orders panel:', $listUrl, ''] : []),
                ...$this->orderDetailsLines($order),
            ]));

        $this->sendEmail($message, $order, 'admin notification');
    }

    public function sendOrderUpdatedToAdmin(Order $order): void
    {
        $manageUrl = $this->adminManageUrl($order);
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($this->adminAddress)
            ->subject($this->subject('Order updated, needs reconfirmation', $order))
            ->text(implode("\n", [
                'The customer updated a previously confirmed order.',
                '',
                'Please review and confirm again:',
                $manageUrl,
                '',
                ...$this->orderDetailsLines($order),
            ]));

        $this->sendEmail($message, $order, 'order updated admin');
    }

    public function sendOrderCompletionReminderToAdmin(Order $order): void
    {
        $manageUrl = $this->adminManageUrl($order);
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($this->adminAddress)
            ->subject($this->subject('Order ready to mark as completed', $order))
            ->text(implode("\n", [
                'Pickup time has passed for a confirmed order.',
                'Please mark it as completed or not completed.',
                '',
                'Open order:',
                $manageUrl,
                '',
                ...$this->orderDetailsLines($order),
            ]));

        $this->sendEmail($message, $order, 'order completion reminder');
    }

    public function sendOrderConfirmedToCustomer(Order $order): void
    {
        $manageUrl = rtrim($this->frontendBaseUrl, '/') . '/?orderId=' . $order->id()->toRfc4122();
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($order->emailAddress())
            ->subject($this->subject('Order confirmed', $order))
            ->text(implode("\n", [
                'Your booking has been confirmed.',
                '',
                'You can edit or cancel your order using the link below:',
                $manageUrl,
                '',
                ...$this->orderDetailsLines($order),
            ]));

        $this->sendEmail($message, $order, 'order confirmed');
    }

    public function sendOrderUpdateRequestToCustomer(Order $order, array $fields): void
    {
        $fields = $this->normalizeUpdateFields($fields);
        if ($fields === []) {
            return;
        }

        $manageUrl = rtrim($this->frontendBaseUrl, '/') . '/?orderId=' . $order->id()->toRfc4122()
            . '&update=' . implode(',', $fields);
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($order->emailAddress())
            ->subject($this->subject('Please update your booking details', $order))
            ->text(implode("\n", [
                'We need a quick update to your booking details.',
                '',
                'Please update the highlighted fields:',
                $this->formatUpdateFieldList($fields),
                '',
                'Open your booking here:',
                $manageUrl,
                '',
                ...$this->orderDetailsLines($order),
            ]));

        $this->sendEmail($message, $order, 'order update request');
    }

    public function sendOrderReminderToCustomer(Order $order): void
    {
        $manageUrl = rtrim($this->frontendBaseUrl, '/') . '/?orderId=' . $order->id()->toRfc4122();
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($order->emailAddress())
            ->subject($this->subject('24h reminder', $order))
            ->text(implode("\n", [
                'This is a friendly reminder that your booking is scheduled within 24 hours.',
                '',
                'You can edit or cancel your order using the link below:',
                $manageUrl,
                '',
                ...$this->orderDetailsLines($order),
            ]));

        $this->sendEmail($message, $order, 'order reminder');
    }

    public function sendPriceProposalToCustomer(Order $order, string $price, string $acceptUrl, string $rejectUrl): void
    {
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($order->emailAddress())
            ->subject($this->subject('New price proposed', $order))
            ->text(implode("\n", [
                'We have proposed a new price for your booking.',
                '',
                'Proposed price: ' . $price,
                '',
                'Accept the new price:',
                $acceptUrl,
                '',
                'Reject and cancel the order:',
                $rejectUrl,
                '',
                ...$this->orderDetailsLines($order),
            ]));

        $this->sendEmail($message, $order, 'price proposal');
    }

    public function sendOrderRejectedToCustomer(Order $order, string $reason): void
    {
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($order->emailAddress())
            ->subject($this->subject('Order rejected', $order))
            ->text(implode("\n", [
                'We are sorry, but your booking has been rejected.',
                '',
                'Reason:',
                $reason,
                '',
                ...$this->orderDetailsLines($order),
            ]));

        $this->sendEmail($message, $order, 'order rejected');
    }

    public function sendOrderCancelledToCustomer(Order $order): void
    {
        $cancelUrl = rtrim($this->frontendBaseUrl, '/') . '/?orderId=' . $order->id()->toRfc4122() . '&cancelled=1';
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($order->emailAddress())
            ->subject($this->subject('Order cancelled', $order))
            ->text(implode("\n", [
                'Your booking has been cancelled as requested.',
                '',
                'View the cancellation summary:',
                $cancelUrl,
                '',
                ...$this->orderDetailsLines($order),
            ]));

        $this->sendEmail($message, $order, 'order cancelled');
    }

    public function sendOrderCancelledToAdmin(Order $order): void
    {
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($this->adminAddress)
            ->subject($this->subject('Order cancelled by customer', $order))
            ->text(implode("\n", [
                'The customer cancelled the order.',
                '',
                ...$this->orderDetailsLines($order),
            ]));

        $this->sendEmail($message, $order, 'order cancelled admin');
    }

    private function sendEmail(Email $message, Order $order, string $context): void
    {
        try {
            $this->mailer->send($message);
        } catch (Throwable $exception) {
            // Avoid blocking order creation when SMTP is unavailable (e.g. dev).
            $this->logger->error(sprintf('Failed to send %s email.', $context), [
                'orderId' => $order->id()->toRfc4122(),
                'exception' => $exception,
            ]);
            error_log(sprintf(
                'Order email failed (%s): %s',
                $exception::class,
                $exception->getMessage()
            ));
        }
    }

    private function subject(string $label, Order $order): string
    {
        return sprintf('Taxi Airport Gdańsk - %s #%s', $label, $order->generatedId());
    }

    private function adminManageUrl(Order $order): string
    {
        $manageUrl = rtrim($this->frontendBaseUrl, '/') . '/admin/orders/' . $order->id()->toRfc4122();
        $token = $this->adminPanelToken !== ''
            ? $this->adminPanelToken
            : ($order->confirmationToken() ?? '');
        if ($token !== '') {
            $manageUrl .= '?token=' . $token;
        }

        return $manageUrl;
    }

    private function normalizeUpdateFields(array $fields): array
    {
        $allowed = ['phone', 'email', 'flight'];
        $normalized = [];
        foreach ($fields as $field) {
            if (!is_string($field)) {
                continue;
            }
            $value = strtolower(trim($field));
            if (in_array($value, $allowed, true)) {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function formatUpdateFieldList(array $fields): string
    {
        $labels = [
            'phone' => 'Phone number',
            'email' => 'Email address',
            'flight' => 'Flight number',
        ];

        $items = array_map(
            fn (string $field) => '- ' . ($labels[$field] ?? $field),
            $fields
        );

        return implode("\n", $items);
    }

    private function orderDetailsLines(Order $order): array
    {
        $details = [
            'Order number: ' . $order->generatedId(),
            'Order ID: ' . $order->id()->toRfc4122(),
            'Customer: ' . $order->fullName(),
            'Customer email: ' . $order->emailAddress(),
            'Phone: ' . $order->phoneNumber(),
            'Pickup address: ' . $order->pickupAddress(),
            'Date: ' . $order->date()->format('Y-m-d'),
            'Pickup time: ' . $order->pickupTime(),
            'Flight number: ' . $order->flightNumber(),
            'Price: ' . $order->proposedPrice() . ' PLN',
        ];

        $passengers = $this->extractPassengers($order->additionalNotes());
        if ($passengers !== null) {
            $details[] = 'Passengers: ' . $passengers;
        }

        if ($order->pendingPrice() !== null) {
            $details[] = 'Pending price proposal: ' . $order->pendingPrice() . ' PLN';
        }

        $customerMessage = $this->extractCustomerMessage($order->additionalNotes());
        if ($customerMessage !== null) {
            $details[] = 'Customer message: ' . $customerMessage;
        }

        $route = $this->extractRoute($order->additionalNotes());
        if ($route !== null) {
            $details[] = 'Route: ' . $route;
        }

        return $details;
    }

    private function extractPassengers(string $additionalNotes): ?string
    {
        $decoded = json_decode($additionalNotes, true);
        if (!is_array($decoded)) {
            return null;
        }

        $passengers = $decoded['passengers'] ?? null;
        if (is_int($passengers)) {
            return (string) $passengers;
        }
        if (!is_string($passengers)) {
            return null;
        }

        $passengers = trim($passengers);
        if ($passengers === '') {
            return null;
        }

        return $passengers;
    }

    private function extractCustomerMessage(string $additionalNotes): ?string
    {
        $decoded = json_decode($additionalNotes, true);
        if (!is_array($decoded)) {
            return null;
        }

        $notes = $decoded['notes'] ?? null;
        if (!is_string($notes)) {
            return null;
        }

        $notes = trim($notes);
        if ($notes === '') {
            return null;
        }

        return $notes;
    }

    private function extractRoute(string $additionalNotes): ?string
    {
        $decoded = json_decode($additionalNotes, true);
        if (!is_array($decoded)) {
            return null;
        }

        $route = $decoded['route'] ?? null;
        if (!is_array($route)) {
            return null;
        }

        $from = $route['from'] ?? null;
        $to = $route['to'] ?? null;
        if (!is_string($from) || !is_string($to) || $from === '' || $to === '') {
            return null;
        }

        return sprintf('%s → %s', $from, $to);
    }

    private function normalizeBackendBaseUrl(string $baseUrl): string
    {
        $trimmed = rtrim($baseUrl, '/');
        if (str_ends_with($trimmed, '/api')) {
            return substr($trimmed, 0, -4);
        }

        return $trimmed;
    }
}
