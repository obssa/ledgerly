<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Module 7: a tax return is due within ten days, or is overdue (054). It names the company, the return and its period,
 * and links to the tax centre; no figures are in the email.
 */
final class TaxReturnDue extends Notification
{
    public function __construct(
        public readonly string $company,
        public readonly string $kind,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly string $dueOn,
        public readonly string $stage,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = match ($this->kind) { 'vat' => 'VAT', 'tot' => 'turnover tax', default => 'withholding tax' };
        $period = (new \DateTimeImmutable($this->periodStart))->format('F Y');
        $due = (new \DateTimeImmutable($this->dueOn))->format('j F Y');
        $overdue = $this->stage === 'overdue';
        $url = rtrim((string) config('security.frontend_url'), '/') . '/tax?' . http_build_query(['kind' => $this->kind, 'from' => $this->periodStart, 'to' => $this->periodEnd]);

        return (new MailMessage)
            ->subject(($overdue ? 'Overdue: ' : 'Due soon: ') . "{$this->company} {$name} return for {$period}")
            ->greeting("Hello {$notifiable->name},")
            ->line($overdue
                ? "The {$name} return of {$this->company} for {$period} was due on {$due} and is not recorded as filed."
                : "The {$name} return of {$this->company} for {$period} is due on {$due}.")
            ->action('Open the tax centre', $url)
            ->line('You get this because you can file tax returns for the company. An owner or admin can turn these reminders off in Settings.');
    }
}
