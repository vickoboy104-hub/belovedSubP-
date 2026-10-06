<?php

namespace App\Contracts;

/**
 * Anything that can push a text message to a list of numbers. The broadcast
 * panel only asks two questions of it: is it usable, and did this batch go.
 */
interface SmsGateway
{
    public function name(): string;

    /** False until the owner supplies credentials, so the UI can say so plainly. */
    public function isConfigured(): bool;

    /**
     * @param  list<string>  $numbers  E.164, e.g. +2348012345678
     * @return array{sent: int, failed: int, message: string}
     */
    public function send(array $numbers, string $message): array;
}
