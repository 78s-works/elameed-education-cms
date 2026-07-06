<?php

namespace App\Modules\Notifications\Contracts;

/**
 * Sends a single SMS. Implementations are swapped by the `sms.driver` config so
 * business logic never depends on a specific aggregator (02_Architecture §6).
 */
interface SmsSender
{
    public function send(string $to, string $message): void;
}
