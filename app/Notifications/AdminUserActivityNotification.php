<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminUserActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $activity,
        private readonly int $userId,
        private readonly string $userName,
        private readonly string $userEmail,
        private readonly ?string $ipAddress = null,
        private readonly ?string $userAgent = null,
    ) {
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        $mailer = (string) config('mail.default', 'log');
        if (!in_array($mailer, ['log', 'array'], true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->activity === 'registration' ? 'New User Registration' : 'User Login Activity';
        $message = $this->activity === 'registration'
            ? 'A new user has registered on the platform.'
            : 'A user has logged in to the platform.';

        return (new MailMessage)
            ->subject($title)
            ->greeting('Admin Alert')
            ->line($message)
            ->line('User: '.$this->userName)
            ->line('Email: '.$this->userEmail)
            ->line('IP: '.($this->ipAddress ?: 'Unknown'))
            ->line('Time: '.now()->toDateTimeString())
            ->action('Open Admin Users', url('/admin/users'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $isRegistration = $this->activity === 'registration';

        return [
            'activity' => $this->activity,
            'title' => $isRegistration ? 'New User Registered' : 'User Login',
            'message' => $isRegistration
                ? "{$this->userName} ({$this->userEmail}) created an account."
                : "{$this->userName} ({$this->userEmail}) logged in.",
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'user_email' => $this->userEmail,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'occurred_at' => now()->toISOString(),
            'url' => url('/admin/users'),
        ];
    }
}
