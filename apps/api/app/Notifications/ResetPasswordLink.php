<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The single-use, 60-minute link for choosing a new password (SECURITY.md S3). */
final class ResetPasswordLink extends Notification
{
    public function __construct(public readonly string $token, public readonly int $minutes)
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
            ->subject('Reset your Ledgerly password')
            ->greeting("Hello {$notifiable->name},")
            ->line('Someone asked to reset the password of your Ledgerly account.')
            ->action('Choose a new password', self::url($this->token))
            ->line('The link works once and for ' . VerifyEmailAddress::duration($this->minutes) . '. Choosing a new password signs you out everywhere.')
            ->line('If this was not you, ignore this email: your password stays as it is.');
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('security.frontend_url'), '/') . '/reset-password?token=' . $token;
    }
}
