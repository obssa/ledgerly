<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Module 2: a payment reminder to a customer, from the company (048). It names the invoice, what is still owed and
 * when it was due; replies go to the company's own email address when it has one.
 */
final class InvoiceReminder extends Notification
{
    public function __construct(
        public readonly string $company,
        public readonly ?string $replyTo,
        public readonly string $customer,
        public readonly string $number,
        public readonly string $currency,
        public readonly string $amountDue,
        public readonly ?string $dueDate,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Payment reminder: invoice {$this->number} from {$this->company}")
            ->greeting("Dear {$this->customer},")
            ->line("This is a reminder that {$this->currency} {$this->amountDue} is still owed on invoice {$this->number} from {$this->company}"
                . ($this->dueDate ? ", which was due on {$this->dueDate}." : '.'))
            ->line('If you have already paid, thank you, and please ignore this message.')
            ->salutation("Kind regards,\n{$this->company}");

        return $this->replyTo ? $mail->replyTo($this->replyTo, $this->company) : $mail;
    }
}
