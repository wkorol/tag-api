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
        $backendBaseUrl = $this->normalizeBackendBaseUrl($this->backendBaseUrl);
        $confirmUrl = rtrim($backendBaseUrl, '/') . '/api/orders/' . $order->id()->toRfc4122()
            . '/confirm?token=' . $order->confirmationToken();
        $manageUrl = rtrim($backendBaseUrl, '/') . '/admin/orders/' . $order->id()->toRfc4122()
            . '?token=' . $order->confirmationToken();
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($this->adminAddress)
            ->subject($this->subject('New order awaiting confirmation', $order))
            ->text(implode("\n", [
                'A new order has been placed.',
                '',
                'Confirm the order using the link below:',
                $confirmUrl,
                '',
                'Manage (confirm/reject) using this link:',
                $manageUrl,
                '',
                ...$this->orderDetailsLines($order),
            ]));

        $this->sendEmail($message, $order, 'admin notification');
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
                'You can cancel your order using the link below:',
                $manageUrl,
                '',
                ...$this->orderDetailsLines($order),
            ]));

        $this->sendEmail($message, $order, 'order confirmed');
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
