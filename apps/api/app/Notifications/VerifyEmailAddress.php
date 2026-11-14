<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The link that proves an email address belongs to the person who signed up (SECURITY.md S3). */
final class VerifyEmailAddress extends Notification
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
            ->subject('Confirm your email address for Ledgerly')
            ->greeting("Hello {$notifiable->name},")
            ->line('Confirm this email address to finish setting up your Ledgerly account.')
            ->action('Confirm email address', self::url($this->token))
            ->line('The link works once and for ' . self::duration($this->minutes) . '.')
            ->line('If you did not create a Ledgerly account, you can ignore this email.');
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('security.frontend_url'), '/') . '/verify-email?token=' . $token;
    }

    public static function duration(int $minutes): string
    {
        return $minutes % 60 === 0 ? ($minutes / 60) . ' ' . ($minutes === 60 ? 'hour' : 'hours') : "{$minutes} minutes";
    }
}
