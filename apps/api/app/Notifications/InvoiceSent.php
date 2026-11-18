<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Module 2: an invoice sent to the customer by email, from the company: its lines, totals, due date and, once the tax
 * authority registered it, its registration number. Replies go to the company's own email address when it has one.
 */
final class InvoiceSent extends Notification
{
    /** @param list<array{description: string, quantity: string, unit_price: string, amount: string}> $lines */
    public function __construct(
        public readonly string $company,
        public readonly ?string $companyTin,
        public readonly ?string $replyTo,
        public readonly string $customer,
        public readonly string $number,
        public readonly string $kind,
        public readonly string $issueDate,
        public readonly ?string $dueDate,
        public readonly string $currency,
        public readonly array $lines,
        public readonly string $subtotal,
        public readonly string $tax,
        public readonly string $total,
        public readonly string $amountDue,
        public readonly ?string $clearance,
        public readonly ?string $note,
        public readonly ?string $link = null,   // a quote's link to see it and answer it online (065)
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = match ($this->kind) { 'credit_note' => 'Credit note', 'quote' => 'Quote', 'sales_order' => 'Sales order', default => 'Invoice' };
        $until = match ($this->kind) { 'quote' => 'valid until', 'sales_order' => 'for delivery by', default => 'due on' };   // 056
        $mail = (new MailMessage)
            ->subject("{$label} {$this->number} from {$this->company}")
            ->greeting("Dear {$this->customer},");
        if ($this->note) {
            $mail->line($this->note);
        }
        $mail->line("{$label} {$this->number} of {$this->issueDate}" . ($this->dueDate ? ", {$until} {$this->dueDate}" : '') . '.');
        foreach ($this->lines as $l) {
            $mail->line("{$l['description']}: {$l['quantity']} × {$l['unit_price']} = {$this->currency} {$l['amount']}");
        }
        $mail->line("Subtotal {$this->currency} {$this->subtotal}; tax {$this->currency} {$this->tax}; total {$this->currency} {$this->total}.");
        if (in_array($this->kind, ['invoice', 'sales_receipt'], true) && $this->amountDue !== $this->total) {
            $mail->line("Still due: {$this->currency} {$this->amountDue}.");
        }
        if ($this->clearance) {
            $mail->line("Registered with the tax authority: {$this->clearance}.");
        }
        if ($this->link !== null) {
            $mail->action('See the quote and accept or decline it', $this->link);
        }
        $mail->salutation("Kind regards,\n{$this->company}" . ($this->companyTin ? "\nTIN {$this->companyTin}" : ''));

        return $this->replyTo ? $mail->replyTo($this->replyTo, $this->company) : $mail;
    }
}
