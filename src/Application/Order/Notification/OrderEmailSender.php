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
        $labels = match ($locale) {
            'pl' => [
                'phone' => 'Numer telefonu',
                'email' => 'Adres e-mail',
                'flight' => 'Numer lotu',
            ],
            'de' => [
                'phone' => 'Telefonnummer',
                'email' => 'E-Mail-Adresse',
                'flight' => 'Flugnummer',
            ],
            'fi' => [
                'phone' => 'Puhelinnumero',
                'email' => 'Sähköpostiosoite',
                'flight' => 'Lennon numero',
            ],
            'no' => [
                'phone' => 'Telefonnummer',
                'email' => 'E-postadresse',
                'flight' => 'Flynummer',
            ],
            'sv' => [
                'phone' => 'Telefonnummer',
                'email' => 'E-postadress',
                'flight' => 'Flygnummer',
            ],
            'da' => [
                'phone' => 'Telefonnummer',
                'email' => 'E-mailadresse',
                'flight' => 'Flynummer',
            ],
            default => [
                'phone' => 'Phone number',
                'email' => 'Email address',
                'flight' => 'Flight number',
            ],
        };

        $items = array_map(
            fn (string $field) => '- ' . ($labels[$field] ?? $field),
            $fields
        );

        return implode("\n", $items);
    }

    private function orderDetailsLines(Order $order, string $locale): array
    {
        $labels = match ($locale) {
            'pl' => [
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
                'sign_service' => 'Opcja odbioru',
                'sign_service_sign' => 'Odbiór z kartką',
                'sign_service_self' => 'Samodzielne znalezienie kierowcy',
                'sign_fee' => 'Dopłata za kartkę',
                'sign_text' => 'Tekst na tabliczce',
            ],
            'de' => [
                'order_number' => 'Bestellnummer',
                'order_id' => 'Bestell-ID',
                'customer' => 'Kunde',
                'customer_email' => 'Kunden-E-Mail',
                'phone' => 'Telefon',
                'pickup_address' => 'Abholadresse',
                'date' => 'Datum',
                'pickup_time' => 'Abholzeit',
                'flight_number' => 'Flugnummer',
                'price' => 'Preis',
                'passengers' => 'Passagiere',
                'pending_price' => 'Ausstehender Preisvorschlag',
                'customer_message' => 'Nachricht des Kunden',
                'route' => 'Route',
                'sign_service' => 'Abholservice',
                'sign_service_sign' => 'Abholung mit Namensschild',
                'sign_service_self' => 'Fahrer selbst finden',
                'sign_fee' => 'Aufpreis für Schild',
                'sign_text' => 'Text für Namensschild',
            ],
            'fi' => [
                'order_number' => 'Tilausnumero',
                'order_id' => 'Tilaus-ID',
                'customer' => 'Asiakas',
                'customer_email' => 'Asiakkaan sähköposti',
                'phone' => 'Puhelin',
                'pickup_address' => 'Nouto-osoite',
                'date' => 'Päivämäärä',
                'pickup_time' => 'Noutoaika',
                'flight_number' => 'Lennon numero',
                'price' => 'Hinta',
                'passengers' => 'Matkustajat',
                'pending_price' => 'Odottava hintatarjous',
                'customer_message' => 'Asiakkaan viesti',
                'route' => 'Reitti',
                'sign_service' => 'Noutotapa',
                'sign_service_sign' => 'Nouto nimikyltillä',
                'sign_service_self' => 'Kuljettajan etsiminen itse',
                'sign_fee' => 'Kyltin lisämaksu',
                'sign_text' => 'Nimikyltti',
            ],
            'no' => [
                'order_number' => 'Bestillingsnummer',
                'order_id' => 'Bestillings-ID',
                'customer' => 'Kunde',
                'customer_email' => 'Kundens e-post',
                'phone' => 'Telefon',
                'pickup_address' => 'Henteadresse',
                'date' => 'Dato',
                'pickup_time' => 'Hentetid',
                'flight_number' => 'Flynummer',
                'price' => 'Pris',
                'passengers' => 'Passasjerer',
                'pending_price' => 'Ventende prisforslag',
                'customer_message' => 'Kundens melding',
                'route' => 'Rute',
                'sign_service' => 'Hentevalg',
                'sign_service_sign' => 'Møt med navneskilt',
                'sign_service_self' => 'Finn sjåføren selv',
                'sign_fee' => 'Skiltgebyr',
                'sign_text' => 'Tekst på skilt',
            ],
            'sv' => [
                'order_number' => 'Beställningsnummer',
                'order_id' => 'Beställnings-ID',
                'customer' => 'Kund',
                'customer_email' => 'Kundens e-post',
                'phone' => 'Telefon',
                'pickup_address' => 'Upphämtningsadress',
                'date' => 'Datum',
                'pickup_time' => 'Upphämtningstid',
                'flight_number' => 'Flygnummer',
                'price' => 'Pris',
                'passengers' => 'Passagerare',
                'pending_price' => 'Väntande prisförslag',
                'customer_message' => 'Kundens meddelande',
                'route' => 'Rutt',
                'sign_service' => 'Upphämtningssätt',
                'sign_service_sign' => 'Möt med namnskylt',
                'sign_service_self' => 'Hitta föraren själv',
                'sign_fee' => 'Skyltavgift',
                'sign_text' => 'Text på skylt',
            ],
            'da' => [
                'order_number' => 'Bestillingsnummer',
                'order_id' => 'Bestillings-ID',
                'customer' => 'Kunde',
                'customer_email' => 'Kundens e-mail',
                'phone' => 'Telefon',
                'pickup_address' => 'Afhentningsadresse',
                'date' => 'Dato',
                'pickup_time' => 'Afhentningstid',
                'flight_number' => 'Flynummer',
                'price' => 'Pris',
                'passengers' => 'Passagerer',
                'pending_price' => 'Afventende prisforslag',
                'customer_message' => 'Kundens besked',
                'route' => 'Rute',
                'sign_service' => 'Afhentningsvalg',
                'sign_service_sign' => 'Mød med navneskilt',
                'sign_service_self' => 'Find chaufføren selv',
                'sign_fee' => 'Skiltgebyr',
                'sign_text' => 'Tekst på skilt',
            ],
            default => [
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
                'sign_service' => 'Pickup service',
                'sign_service_sign' => 'Meet with a name sign',
                'sign_service_self' => 'Find the driver myself',
                'sign_fee' => 'Sign fee',
                'sign_text' => 'Sign text',
            ],
        };
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

        $signService = $this->extractSignService($order->additionalNotes());
        if ($signService !== null) {
            $signServiceLabel = $signService === 'sign'
                ? $labels['sign_service_sign']
                : $labels['sign_service_self'];
            $details[] = $labels['sign_service'] . ': ' . $signServiceLabel;
        }

        $signFee = $this->extractSignFee($order->additionalNotes());
        if ($signFee !== null) {
            $details[] = $labels['sign_fee'] . ': ' . $signFee . ' PLN';
        }

        $signText = $this->extractSignText($order->additionalNotes());
        if ($signText !== null) {
            $details[] = $labels['sign_text'] . ': ' . $signText;
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

    private function extractSignService(string $additionalNotes): ?string
    {
        $decoded = json_decode($additionalNotes, true);
        if (!is_array($decoded)) {
            return null;
        }

        $service = $decoded['signService'] ?? null;
        if (!is_string($service)) {
            return null;
        }

        $service = trim($service);
        if ($service === '') {
            return null;
        }

        return $service;
    }

    private function extractSignFee(string $additionalNotes): ?int
    {
        $decoded = json_decode($additionalNotes, true);
        if (!is_array($decoded)) {
            return null;
        }

        $fee = $decoded['signFee'] ?? null;
        if (is_int($fee)) {
            return $fee > 0 ? $fee : null;
        }
        if (is_string($fee) && is_numeric($fee)) {
            $feeValue = (int) $fee;
            return $feeValue > 0 ? $feeValue : null;
        }

        return null;
    }

    private function extractSignText(string $additionalNotes): ?string
    {
        $decoded = json_decode($additionalNotes, true);
        if (!is_array($decoded)) {
            return null;
        }

        $signText = $decoded['signText'] ?? null;
        if (!is_string($signText)) {
            return null;
        }

        $signText = trim($signText);
        if ($signText === '') {
            return null;
        }

        return $signText;
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
        $locale = $order->locale();
        return in_array($locale, ['en', 'pl', 'de', 'fi', 'no', 'sv', 'da'], true) ? $locale : 'en';
    }

    private function customerBaseUrl(Order $order): string
    {
        $base = rtrim($this->frontendBaseUrl, '/');
        $localePath = '/' . $this->customerLocale($order);

        return $base . $localePath;
    }

    private function adminBaseUrl(): string
    {
        $base = rtrim($this->frontendBaseUrl, '/');

        return $base . '/en';
    }

    private function translations(string $locale): array
    {
        return match ($locale) {
            'pl' => [
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
            ],
            'de' => [
                'subject_order_received' => 'Bestellung erhalten',
                'subject_order_confirmed' => 'Bestellung bestätigt',
                'subject_update_request' => 'Bitte aktualisieren Sie Ihre Buchungsdaten',
                'subject_reminder' => '24h Erinnerung',
                'subject_price_proposed' => 'Neuer Preis vorgeschlagen',
                'subject_order_rejected' => 'Bestellung abgelehnt',
                'subject_order_cancelled' => 'Bestellung storniert',
                'line_thank_you' => 'Vielen Dank für Ihre Buchung.',
                'line_edit_cancel' => 'Sie können Ihre Bestellung über den folgenden Link bearbeiten oder stornieren:',
                'line_booking_confirmed' => 'Ihre Buchung wurde bestätigt.',
                'line_update_prompt' => 'Wir benötigen eine kurze Aktualisierung Ihrer Buchungsdaten.',
                'line_update_fields' => 'Bitte aktualisieren Sie die markierten Felder:',
                'line_open_booking' => 'Öffnen Sie Ihre Buchung hier:',
                'line_reminder' => 'Dies ist eine freundliche Erinnerung, dass Ihre Buchung innerhalb von 24 Stunden stattfindet.',
                'line_price_proposed' => 'Wir haben einen neuen Preis für Ihre Buchung vorgeschlagen.',
                'line_proposed_price' => 'Vorgeschlagener Preis:',
                'line_accept_price' => 'Neuen Preis akzeptieren:',
                'line_reject_price' => 'Ablehnen und Bestellung stornieren:',
                'line_rejected_intro' => 'Es tut uns leid, aber Ihre Buchung wurde abgelehnt.',
                'line_reason' => 'Grund:',
                'line_cancelled' => 'Ihre Buchung wurde wie gewünscht storniert.',
                'line_view_cancel' => 'Stornierungsübersicht anzeigen:',
            ],
            'fi' => [
                'subject_order_received' => 'Tilaus vastaanotettu',
                'subject_order_confirmed' => 'Tilaus vahvistettu',
                'subject_update_request' => 'Päivitä varauksen tiedot',
                'subject_reminder' => '24 h muistutus',
                'subject_price_proposed' => 'Uusi hintaehdotus',
                'subject_order_rejected' => 'Tilaus hylätty',
                'subject_order_cancelled' => 'Tilaus peruttu',
                'line_thank_you' => 'Kiitos varauksestasi.',
                'line_edit_cancel' => 'Voit muokata tai peruuttaa tilauksen alla olevasta linkistä:',
                'line_booking_confirmed' => 'Varauksesi on vahvistettu.',
                'line_update_prompt' => 'Tarvitsemme lyhyen päivityksen varauksesi tietoihin.',
                'line_update_fields' => 'Päivitä korostetut kentät:',
                'line_open_booking' => 'Avaa varauksesi tästä:',
                'line_reminder' => 'Tämä on muistutus siitä, että varauksesi on 24 tunnin sisällä.',
                'line_price_proposed' => 'Olemme ehdottaneet uutta hintaa varauksellesi.',
                'line_proposed_price' => 'Ehdotettu hinta:',
                'line_accept_price' => 'Hyväksy uusi hinta:',
                'line_reject_price' => 'Hylkää ja peruuta tilaus:',
                'line_rejected_intro' => 'Valitettavasti varauksesi on hylätty.',
                'line_reason' => 'Syy:',
                'line_cancelled' => 'Varauksesi on peruttu pyynnöstäsi.',
                'line_view_cancel' => 'Näytä peruutuksen yhteenveto:',
            ],
            'no' => [
                'subject_order_received' => 'Bestilling mottatt',
                'subject_order_confirmed' => 'Bestilling bekreftet',
                'subject_update_request' => 'Vennligst oppdater bestillingsdetaljer',
                'subject_reminder' => '24 t påminnelse',
                'subject_price_proposed' => 'Ny pris foreslått',
                'subject_order_rejected' => 'Bestilling avvist',
                'subject_order_cancelled' => 'Bestilling avbestilt',
                'line_thank_you' => 'Takk for bestillingen.',
                'line_edit_cancel' => 'Du kan redigere eller avbestille bestillingen via lenken under:',
                'line_booking_confirmed' => 'Bestillingen din er bekreftet.',
                'line_update_prompt' => 'Vi trenger en kort oppdatering av bestillingsdetaljene.',
                'line_update_fields' => 'Vennligst oppdater de markerte feltene:',
                'line_open_booking' => 'Åpne bestillingen her:',
                'line_reminder' => 'Dette er en vennlig påminnelse om at bestillingen din er innen 24 timer.',
                'line_price_proposed' => 'Vi har foreslått en ny pris for bestillingen din.',
                'line_proposed_price' => 'Foreslått pris:',
                'line_accept_price' => 'Godta den nye prisen:',
                'line_reject_price' => 'Avslå og avbestill bestillingen:',
                'line_rejected_intro' => 'Beklager, bestillingen din er avvist.',
                'line_reason' => 'Årsak:',
                'line_cancelled' => 'Bestillingen din er avbestilt som forespurt.',
                'line_view_cancel' => 'Se avbestillingsoversikten:',
            ],
            'sv' => [
                'subject_order_received' => 'Beställning mottagen',
                'subject_order_confirmed' => 'Beställning bekräftad',
                'subject_update_request' => 'Vänligen uppdatera dina bokningsuppgifter',
                'subject_reminder' => '24 h påminnelse',
                'subject_price_proposed' => 'Nytt pris föreslaget',
                'subject_order_rejected' => 'Beställning avvisad',
                'subject_order_cancelled' => 'Beställning avbokad',
                'line_thank_you' => 'Tack för din bokning.',
                'line_edit_cancel' => 'Du kan redigera eller avboka beställningen via länken nedan:',
                'line_booking_confirmed' => 'Din bokning har bekräftats.',
                'line_update_prompt' => 'Vi behöver en snabb uppdatering av dina bokningsuppgifter.',
                'line_update_fields' => 'Vänligen uppdatera de markerade fälten:',
                'line_open_booking' => 'Öppna din bokning här:',
                'line_reminder' => 'Detta är en vänlig påminnelse om att din bokning är inom 24 timmar.',
                'line_price_proposed' => 'Vi har föreslagit ett nytt pris för din bokning.',
                'line_proposed_price' => 'Föreslaget pris:',
                'line_accept_price' => 'Acceptera det nya priset:',
                'line_reject_price' => 'Avvisa och avboka beställningen:',
                'line_rejected_intro' => 'Tyvärr har din bokning avvisats.',
                'line_reason' => 'Orsak:',
                'line_cancelled' => 'Din bokning har avbokats enligt din begäran.',
                'line_view_cancel' => 'Visa avbokningssammanfattning:',
            ],
            'da' => [
                'subject_order_received' => 'Bestilling modtaget',
                'subject_order_confirmed' => 'Bestilling bekræftet',
                'subject_update_request' => 'Opdater venligst dine bookingoplysninger',
                'subject_reminder' => '24 t påmindelse',
                'subject_price_proposed' => 'Ny pris foreslået',
                'subject_order_rejected' => 'Bestilling afvist',
                'subject_order_cancelled' => 'Bestilling annulleret',
                'line_thank_you' => 'Tak for din booking.',
                'line_edit_cancel' => 'Du kan redigere eller annullere bestillingen via linket nedenfor:',
                'line_booking_confirmed' => 'Din booking er bekræftet.',
                'line_update_prompt' => 'Vi har brug for en kort opdatering af dine bookingoplysninger.',
                'line_update_fields' => 'Opdater venligst de markerede felter:',
                'line_open_booking' => 'Åbn din booking her:',
                'line_reminder' => 'Dette er en venlig påmindelse om, at din booking er inden for 24 timer.',
                'line_price_proposed' => 'Vi har foreslået en ny pris for din booking.',
                'line_proposed_price' => 'Foreslået pris:',
                'line_accept_price' => 'Accepter den nye pris:',
                'line_reject_price' => 'Afvis og annuller bestillingen:',
                'line_rejected_intro' => 'Beklager, din booking blev afvist.',
                'line_reason' => 'Årsag:',
                'line_cancelled' => 'Din booking er annulleret efter ønske.',
                'line_view_cancel' => 'Se annulleringsoversigten:',
            ],
            default => [
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
            ],
        };
    }
}
