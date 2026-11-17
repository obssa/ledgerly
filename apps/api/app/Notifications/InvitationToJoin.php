<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Module 11: an invitation to work in a company, with the one-time link to accept it (046). */
final class InvitationToJoin extends Notification
{
    public function __construct(
        public readonly string $token,
        public readonly string $company,
        public readonly string $invitedBy,
        public readonly string $role,
        public readonly bool $newAccount,
        public readonly int $days,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->invitedBy} invited you to {$this->company} on Ledgerly")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->invitedBy} invited you to work in {$this->company} on Ledgerly as {$this->roleWords()}.")
            ->line($this->newAccount ? 'Accept to choose your password and sign in.' : 'Accept, then sign in with your Ledgerly account as usual.')
            ->action('Accept the invitation', self::url($this->token))
            ->line("The link works once and for {$this->days} days. If you were not expecting this, you can ignore this email.");
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('security.frontend_url'), '/') . '/invitation?token=' . $token;
    }

    private function roleWords(): string
    {
        return in_array($this->role, ['owner', 'admin', 'accountant', 'approver'], true) ? "an {$this->role}" : "a {$this->role}";
    }
}
