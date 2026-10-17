<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserWalletActivityNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly array $payload = [],
    ) {
    }

    public function via(object $notifiable): array
    {
        // The dashboard copy is written first, so a mail server that is down -
        // or a customer with no address on file - never costs them the record of
        // what happened to their money.
        $channels = ['database'];

        if (trim((string) ($notifiable->email ?? '')) !== '') {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $creditedKobo = (int) ($this->payload['amount_kobo'] ?? 0);
        $isCredit = ($this->payload['type'] ?? '') === 'credit';
        $balanceAfterKobo = $this->payload['balance_after_kobo'] ?? null;
        $reference = trim((string) ($this->payload['reference'] ?? ''));
        $firstName = trim((string) ($notifiable->first_name ?? ''));

        $mail = (new MailMessage)
            ->subject($isCredit && $creditedKobo > 0
                ? 'Payment received - N'.number_format($creditedKobo / 100, 2).' added to your wallet'
                : $this->title)
            ->greeting($firstName !== '' ? 'Hello '.$firstName : 'Hello')
            ->line($this->message);

        if ($isCredit && $balanceAfterKobo !== null) {
            $mail->line('Your wallet balance is now N'.number_format(((int) $balanceAfterKobo) / 100, 2).'.');
        }

        if ($reference !== '') {
            $mail->line('Reference: '.$reference);
        }

        $mail->line('Time: '.now()->format('d M Y, h:ia'));

        if (!$isCredit) {
            $mail->line('If you did not expect this change, contact support before making another payment.');
        }

        return $mail->action('View my wallet transactions', url('/wallet/transactions'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $data = array_merge([
            'title' => $this->title,
            'message' => $this->message,
            'created_at_iso' => now()->toISOString(),
        ], $this->payload);

        // Every one of these is about money, so every one of them leads back to
        // the ledger unless the caller pointed it somewhere more specific.
        $data['url'] = trim((string) ($data['url'] ?? '')) ?: url('/wallet/transactions');
        $data['action_label'] = trim((string) ($data['action_label'] ?? '')) ?: 'View my wallet transactions';

        return $data;
    }
}
