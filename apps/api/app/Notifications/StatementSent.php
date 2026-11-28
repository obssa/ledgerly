<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A customer's statement by email (QuickBooks: Create statements, Send): the period, each line, what they owe. */
final class StatementSent extends Notification
{
    /** @param list<array{date: string, reference: string, amount: string, balance: ?string}> $lines */
    public function __construct(
        public readonly string $company,
        public readonly ?string $companyTin,
        public readonly ?string $replyTo,
        public readonly string $customer,
        public readonly string $type,
        public readonly string $from,
        public readonly string $to,
        public readonly string $currency,
        public readonly ?string $opening,
        public readonly string $closing,
        public readonly array $lines,
        public readonly ?string $note = null,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $kind = match ($this->type) { 'open_item' => 'Open items', 'transaction' => 'Transactions', default => 'Statement' };
        $mail = (new MailMessage)
            ->subject("Statement from {$this->company}")
            ->greeting("Dear {$this->customer},");
        if ($this->note) {
            $mail->line($this->note);
        }
        $mail->line($this->type === 'open_item' ? "{$kind} at {$this->to}." : "{$kind} from {$this->from} to {$this->to}.");
        if ($this->opening !== null) {
            $mail->line("Balance brought forward: {$this->currency} {$this->opening}.");
        }
        foreach ($this->lines as $l) {
            $mail->line("{$l['date']} · {$l['reference']} · {$this->currency} {$l['amount']}" . ($l['balance'] !== null ? " · balance {$l['balance']}" : ''));
        }
        $mail->line("Amount due: {$this->currency} {$this->closing}.");
        $mail->salutation("Kind regards,\n{$this->company}" . ($this->companyTin ? "\nTIN {$this->companyTin}" : ''));

        return $this->replyTo ? $mail->replyTo($this->replyTo, $this->company) : $mail;
    }
}
