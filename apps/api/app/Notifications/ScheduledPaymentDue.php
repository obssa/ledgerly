<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Module 3: a payment scheduled for today (103). It names the company, the supplier, the day and the amount, and links
 * to the payment, where it is confirmed once the money went out. Nothing is recorded until then.
 */
final class ScheduledPaymentDue extends Notification
{
    public function __construct(
        public readonly string $company,
        public readonly string $supplier,
        public readonly string $payOn,
        public readonly string $currency,
        public readonly string $amount,
        public readonly string $scheduledPaymentId,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $day = (new \DateTimeImmutable($this->payOn))->format('j F Y');
        $url = rtrim((string) config('security.frontend_url'), '/') . '/payments-made/scheduled/' . rawurlencode($this->scheduledPaymentId);

        return (new MailMessage)
            ->subject("To pay: {$this->supplier}, {$this->currency} {$this->amount} ({$this->company})")
            ->greeting("Hello {$notifiable->name},")
            ->line("A payment of {$this->currency} {$this->amount} to {$this->supplier} is scheduled for {$day}.")
            ->line('Once the money has gone out, confirm it in Evenbooks: the payment is recorded and the bills are marked as paid. Until then nothing is in the books.')
            ->action('Open the payment', $url)
            ->line('You get this because you scheduled the payment, or can pay bills for the company.');
    }
}
