<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminBroadcastNotification extends Notification
{
    use Queueable;

    /**
     * The audience is decided by the admin form, so the caller supplies the
     * channels instead of this class guessing per recipient.
     *
     * @param  array<int, string>  $channels
     */
    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly array $channels = ['database'],
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting('Hello '.($notifiable->first_name ?: trim(explode(' ', $notifiable->name)[0])).',')
            ->line($this->message);

        return $mail
            ->line('This is an announcement from '.config('mail.from.name', config('app.name')).'.')
            ->action('Open '.config('app.name'), url('/dashboard'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'severity' => 'info',
            'url' => url('/notifications'),
            'created_at_iso' => now()->toISOString(),
        ];
    }
}
