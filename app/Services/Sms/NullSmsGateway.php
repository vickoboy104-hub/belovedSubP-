<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;

/**
 * What stands in until the owner connects a real gateway. It never reports a
 * delivery, so a bulk send can't claim to have texted anyone.
 */
class NullSmsGateway implements SmsGateway
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function send(array $numbers, string $message): array
    {
        return [
            'sent' => 0,
            'failed' => count($numbers),
            'message' => 'No SMS gateway is connected yet. Add an API key in Admin Settings to send texts from this page.',
        ];
    }
}
