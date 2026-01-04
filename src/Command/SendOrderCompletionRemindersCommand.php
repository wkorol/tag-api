<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Order\Notification\OrderEmailSender;
use App\Domain\Order\Order;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:orders:send-completion-reminders',
    description: 'Send admin reminders for confirmed orders whose pickup time has passed.'
)]
final class SendOrderCompletionRemindersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderEmailSender $emailSender
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repository = $this->entityManager->getRepository(Order::class);
        $orders = $repository->findBy([
            'status' => Order::STATUS_CONFIRMED,
            'completionReminderSentAt' => null,
        ]);

        $timezone = new \DateTimeZone(getenv('TZ') ?: 'Europe/Warsaw');
        $now = new DateTimeImmutable('now', $timezone);
        $sentCount = 0;

        foreach ($orders as $order) {
            if (!$order instanceof Order) {
                continue;
            }

            $pickupDateTime = $this->pickupDateTime($order, $timezone);
            if ($pickupDateTime === null) {
                continue;
            }

            if ($pickupDateTime <= $now) {
                $this->emailSender->sendOrderCompletionReminderToAdmin($order);
                $order->markCompletionReminderSent($now);
                $sentCount++;
            }
        }

        if ($sentCount > 0) {
            $this->entityManager->flush();
        }

        $output->writeln(sprintf('Sent %d completion reminder(s).', $sentCount));

        return Command::SUCCESS;
    }

    private function pickupDateTime(Order $order, \DateTimeZone $timezone): ?DateTimeImmutable
    {
        $time = $order->pickupTime();
        if (!preg_match('/^(\\d{2}):(\\d{2})$/', $time, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        return $order->date()
            ->setTimezone($timezone)
            ->setTime($hour, $minute);
    }
}
