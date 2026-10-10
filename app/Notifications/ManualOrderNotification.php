<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Progress updates for a manually fulfilled identity request: received,
 * result ready, or rejected and refunded. The url is what makes the
 * notification clickable, so it always points at the customer's receipt.
 */
class ManualOrderNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly ?string $url = null,
        private readonly array $payload = [],
    ) {
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        $mailer = (string) config('mail.default', 'log');
        if (!in_array($mailer, ['log', 'array'], true) && (($this->payload['send_mail'] ?? true) === true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting('Hello '.($notifiable->name ?? 'there'))
            ->line($this->message)
            ->line('Time: '.now()->format('d M Y, h:ia'));

        if ($this->url) {
            $mail->action('View my request', $this->url);
        }

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return array_merge([
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url ?: url('/vtu/orders'),
            'created_at_iso' => now()->toISOString(),
        ], $this->payload);
    }
}
