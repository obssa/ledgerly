<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The link a new staff member sets up their account with: a password and an authenticator (SECURITY.md S8). */
final class StaffSetupLink extends Notification
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $token, public readonly int $hours)
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
            ->subject('Your Ledgerly staff account')
            ->greeting("Hello {$this->name},")
            ->line("A Ledgerly staff account was made for you. Set its password and authenticator within {$this->hours} hours, from an allowed address.")
            ->action('Set up the account', self::url($this->token))
            ->line('If you did not expect this, tell the operator and do not open the link.');
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('security.frontend_url'), '/') . '/staff/setup?token=' . $token;
    }
}
