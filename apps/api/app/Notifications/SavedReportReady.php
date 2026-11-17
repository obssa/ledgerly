<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Module 10: a scheduled report is ready. The email names the report and its period and links to it; no figures are
 * in the email, and the link needs the person signed in, with their role deciding what they see.
 */
final class SavedReportReady extends Notification
{
    public function __construct(public readonly string $savedReportId, public readonly string $name, public readonly string $company, public readonly string $covers)
    {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->name} for {$this->company} is ready")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your scheduled report {$this->name} for {$this->company} ({$this->covers}) is ready in Ledgerly.")
            ->action('Open the report', self::url($this->savedReportId))
            ->line('You get this email because you scheduled the report. Change or stop the schedule under Reports, Saved reports.');
    }

    public static function url(string $id): string
    {
        return rtrim((string) config('security.frontend_url'), '/') . '/reports/saved/' . $id;
    }
}
