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
        $locale = $this->customerLocale($order);
        $t = $this->translations($locale);
        $editUrl = $this->customerBaseUrl($order) . '/?orderId=' . $order->id()->toRfc4122();
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($order->emailAddress())
            ->subject($this->subject($t['subject_order_received'], $order))
            ->text(implode("\n", [
                $t['line_thank_you'],
                '',
                $t['line_edit_cancel'],
                $editUrl,
                '',
                ...$this->orderDetailsLines($order, $locale),
            ]));

        $this->sendEmail($message, $order, 'order confirmation');
    }

    public function sendOrderCreatedToAdmin(Order $order): void
    {
        $manageUrl = $this->adminManageUrl($order);
        $adminToken = $this->adminPanelTokenValue();
        $listUrl = $adminToken !== ''
            ? $this->adminBaseUrl() . '/admin?token=' . $adminToken
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
                ...$this->orderDetailsLines($order, 'en'),
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
                ...$this->orderDetailsLines($order, 'en'),
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
                ...$this->orderDetailsLines($order, 'en'),
            ]));

        $this->sendEmail($message, $order, 'order completion reminder');
    }

    public function sendOrderConfirmedToCustomer(Order $order): void
    {
        $locale = $this->customerLocale($order);
        $t = $this->translations($locale);
        $manageUrl = $this->customerBaseUrl($order) . '/?orderId=' . $order->id()->toRfc4122();
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($order->emailAddress())
            ->subject($this->subject($t['subject_order_confirmed'], $order))
            ->text(implode("\n", [
                $t['line_booking_confirmed'],
                '',
                $t['line_edit_cancel'],
                $manageUrl,
                '',
                ...$this->orderDetailsLines($order, $locale),
            ]));

        $this->sendEmail($message, $order, 'order confirmed');
    }

    public function sendOrderUpdateRequestToCustomer(Order $order, array $fields): void
    {
        $fields = $this->normalizeUpdateFields($fields);
        if ($fields === []) {
            return;
        }

        $locale = $this->customerLocale($order);
        $t = $this->translations($locale);
        $manageUrl = $this->customerBaseUrl($order) . '/?orderId=' . $order->id()->toRfc4122()
            . '&update=' . implode(',', $fields);
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($order->emailAddress())
            ->subject($this->subject($t['subject_update_request'], $order))
            ->text(implode("\n", [
                $t['line_update_prompt'],
                '',
                $t['line_update_fields'],
                $this->formatUpdateFieldList($fields, $locale),
                '',
                $t['line_open_booking'],
                $manageUrl,
                '',
                ...$this->orderDetailsLines($order, $locale),
            ]));

        $this->sendEmail($message, $order, 'order update request');
    }

    public function sendCustomerUpdatedRequestToAdmin(Order $order, array $fields): void
    {
        $fields = $this->normalizeUpdateFields($fields);
        $manageUrl = $this->adminManageUrl($order);
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($this->adminAddress)
            ->subject($this->subject('Customer updated requested details', $order))
            ->text(implode("\n", [
                'The customer has updated the requested booking details.',
                '',
                $fields !== [] ? 'Updated fields:' : 'Updated fields: (not specified)',
                $fields !== [] ? $this->formatUpdateFieldList($fields, 'en') : '',
                '',
                'Review the booking here:',
                $manageUrl,
                '',
                ...$this->orderDetailsLines($order, 'en'),
            ]));

        $this->sendEmail($message, $order, 'order update completed');
    }

    public function sendOrderReminderToCustomer(Order $order): void
    {
        $locale = $this->customerLocale($order);
        $t = $this->translations($locale);
        $manageUrl = $this->customerBaseUrl($order) . '/?orderId=' . $order->id()->toRfc4122();
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($order->emailAddress())
            ->subject($this->subject($t['subject_reminder'], $order))
            ->text(implode("\n", [
                $t['line_reminder'],
                '',
                $t['line_edit_cancel'],
                $manageUrl,
                '',
                ...$this->orderDetailsLines($order, $locale),
            ]));

        $this->sendEmail($message, $order, 'order reminder');
    }

    public function sendPriceProposalToCustomer(Order $order, string $price, string $acceptUrl, string $rejectUrl): void
    {
        $locale = $this->customerLocale($order);
        $t = $this->translations($locale);
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($order->emailAddress())
            ->subject($this->subject($t['subject_price_proposed'], $order))
            ->text(implode("\n", [
                $t['line_price_proposed'],
                '',
                $t['line_proposed_price'] . ' ' . $price,
                '',
                $t['line_accept_price'],
                $acceptUrl,
                '',
                $t['line_reject_price'],
                $rejectUrl,
                '',
                ...$this->orderDetailsLines($order, $locale),
            ]));

        $this->sendEmail($message, $order, 'price proposal');
    }

    public function sendOrderRejectedToCustomer(Order $order, string $reason): void
    {
        $locale = $this->customerLocale($order);
        $t = $this->translations($locale);
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($order->emailAddress())
            ->subject($this->subject($t['subject_order_rejected'], $order))
            ->text(implode("\n", [
                $t['line_rejected_intro'],
                '',
                $t['line_reason'],
                $reason,
                '',
                ...$this->orderDetailsLines($order, $locale),
            ]));

        $this->sendEmail($message, $order, 'order rejected');
    }

    public function sendOrderCancelledToCustomer(Order $order): void
    {
        $locale = $this->customerLocale($order);
        $t = $this->translations($locale);
        $cancelUrl = $this->customerBaseUrl($order) . '/?orderId=' . $order->id()->toRfc4122() . '&cancelled=1';
        $message = (new Email())
            ->from($this->fromAddress)
            ->to($order->emailAddress())
            ->subject($this->subject($t['subject_order_cancelled'], $order))
            ->text(implode("\n", [
                $t['line_cancelled'],
                '',
                $t['line_view_cancel'],
                $cancelUrl,
                '',
                ...$this->orderDetailsLines($order, $locale),
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
                ...$this->orderDetailsLines($order, 'en'),
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
        $manageUrl = $this->adminBaseUrl() . '/admin/orders/' . $order->id()->toRfc4122();
        $token = $this->adminPanelTokenValue() !== ''
            ? $this->adminPanelTokenValue()
            : ($order->confirmationToken() ?? '');
        if ($token !== '') {
            $manageUrl .= '?token=' . $token;
        }

        return $manageUrl;
    }

    private function adminPanelTokenValue(): string
    {
        return trim($this->adminPanelToken);
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

    private function formatUpdateFieldList(array $fields, string $locale): string
    {
        $labels = $locale === 'pl'
            ? [
                'phone' => 'Numer telefonu',
                'email' => 'Adres e-mail',
                'flight' => 'Numer lotu',
            ]
            : [
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

    private function orderDetailsLines(Order $order, string $locale): array
    {
        $labels = $locale === 'pl'
            ? [
                'order_number' => 'Nr zamówienia',
                'order_id' => 'ID zamówienia',
                'customer' => 'Klient',
                'customer_email' => 'E-mail klienta',
                'phone' => 'Telefon',
                'pickup_address' => 'Adres odbioru',
                'date' => 'Data',
                'pickup_time' => 'Godzina odbioru',
                'flight_number' => 'Numer lotu',
                'price' => 'Cena',
                'passengers' => 'Pasażerowie',
                'pending_price' => 'Oczekująca propozycja ceny',
                'customer_message' => 'Wiadomość klienta',
                'route' => 'Trasa',
            ]
            : [
                'order_number' => 'Order number',
                'order_id' => 'Order ID',
                'customer' => 'Customer',
                'customer_email' => 'Customer email',
                'phone' => 'Phone',
                'pickup_address' => 'Pickup address',
                'date' => 'Date',
                'pickup_time' => 'Pickup time',
                'flight_number' => 'Flight number',
                'price' => 'Price',
                'passengers' => 'Passengers',
                'pending_price' => 'Pending price proposal',
                'customer_message' => 'Customer message',
                'route' => 'Route',
            ];
        $details = [
            $labels['order_number'] . ': ' . $order->generatedId(),
            $labels['order_id'] . ': ' . $order->id()->toRfc4122(),
            $labels['customer'] . ': ' . $order->fullName(),
            $labels['customer_email'] . ': ' . $order->emailAddress(),
            $labels['phone'] . ': ' . $order->phoneNumber(),
            $labels['pickup_address'] . ': ' . $order->pickupAddress(),
            $labels['date'] . ': ' . $order->date()->format('Y-m-d'),
            $labels['pickup_time'] . ': ' . $order->pickupTime(),
            $labels['flight_number'] . ': ' . $order->flightNumber(),
            $labels['price'] . ': ' . $order->proposedPrice() . ' PLN',
        ];

        $passengers = $this->extractPassengers($order->additionalNotes());
        if ($passengers !== null) {
            $details[] = $labels['passengers'] . ': ' . $passengers;
        }

        if ($order->pendingPrice() !== null) {
            $details[] = $labels['pending_price'] . ': ' . $order->pendingPrice() . ' PLN';
        }

        $customerMessage = $this->extractCustomerMessage($order->additionalNotes());
        if ($customerMessage !== null) {
            $details[] = $labels['customer_message'] . ': ' . $customerMessage;
        }

        $route = $this->extractRoute($order->additionalNotes());
        if ($route !== null) {
            $details[] = $labels['route'] . ': ' . $route;
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

    private function customerLocale(Order $order): string
    {
        return $order->locale() === 'pl' ? 'pl' : 'en';
    }

    private function customerBaseUrl(Order $order): string
    {
        $base = rtrim($this->frontendBaseUrl, '/');
        $localePath = $this->customerLocale($order) === 'pl' ? '/pl' : '/en';

        return $base . $localePath;
    }

    private function adminBaseUrl(): string
    {
        $base = rtrim($this->frontendBaseUrl, '/');

        return $base . '/en';
    }

    private function translations(string $locale): array
    {
        if ($locale === 'pl') {
            return [
                'subject_order_received' => 'Zamówienie przyjęte',
                'subject_order_confirmed' => 'Zamówienie potwierdzone',
                'subject_update_request' => 'Prośba o aktualizację rezerwacji',
                'subject_reminder' => 'Przypomnienie 24h',
                'subject_price_proposed' => 'Nowa propozycja ceny',
                'subject_order_rejected' => 'Zamówienie odrzucone',
                'subject_order_cancelled' => 'Zamówienie anulowane',
                'line_thank_you' => 'Dziękujemy za rezerwację.',
                'line_edit_cancel' => 'Możesz edytować lub anulować zamówienie, korzystając z poniższego linku:',
                'line_booking_confirmed' => 'Twoja rezerwacja została potwierdzona.',
                'line_update_prompt' => 'Potrzebujemy krótkiej aktualizacji Twoich danych rezerwacji.',
                'line_update_fields' => 'Zaktualizuj podświetlone pola:',
                'line_open_booking' => 'Otwórz rezerwację tutaj:',
                'line_reminder' => 'To przypomnienie, że Twoja rezerwacja jest zaplanowana w ciągu 24 godzin.',
                'line_price_proposed' => 'Zaproponowaliśmy nową cenę za Twoją rezerwację.',
                'line_proposed_price' => 'Proponowana cena:',
                'line_accept_price' => 'Zaakceptuj nową cenę:',
                'line_reject_price' => 'Odrzuć i anuluj zamówienie:',
                'line_rejected_intro' => 'Przykro nam, ale Twoja rezerwacja została odrzucona.',
                'line_reason' => 'Powód:',
                'line_cancelled' => 'Twoja rezerwacja została anulowana zgodnie z prośbą.',
                'line_view_cancel' => 'Zobacz podsumowanie anulowania:',
            ];
        }

        return [
            'subject_order_received' => 'Order received',
            'subject_order_confirmed' => 'Order confirmed',
            'subject_update_request' => 'Please update your booking details',
            'subject_reminder' => '24h reminder',
            'subject_price_proposed' => 'New price proposed',
            'subject_order_rejected' => 'Order rejected',
            'subject_order_cancelled' => 'Order cancelled',
            'line_thank_you' => 'Thank you for your booking.',
            'line_edit_cancel' => 'You can edit or cancel your order using the link below:',
            'line_booking_confirmed' => 'Your booking has been confirmed.',
            'line_update_prompt' => 'We need a quick update to your booking details.',
            'line_update_fields' => 'Please update the highlighted fields:',
            'line_open_booking' => 'Open your booking here:',
            'line_reminder' => 'This is a friendly reminder that your booking is scheduled within 24 hours.',
            'line_price_proposed' => 'We have proposed a new price for your booking.',
            'line_proposed_price' => 'Proposed price:',
            'line_accept_price' => 'Accept the new price:',
            'line_reject_price' => 'Reject and cancel the order:',
            'line_rejected_intro' => 'We are sorry, but your booking has been rejected.',
            'line_reason' => 'Reason:',
            'line_cancelled' => 'Your booking has been cancelled as requested.',
            'line_view_cancel' => 'View the cancellation summary:',
        ];
    }
}
