<?php

namespace Tests\Support;

use App\Modules\Notifications\Contracts\SmsSender;

/**
 * Test double that records SMS instead of sending, so tests can read back the
 * OTP that the (sync) SendOtpJob delivered.
 */
class RecordingSmsSender implements SmsSender
{
    /** @var array<int, array{to: string, message: string}> */
    public array $messages = [];

    public function send(string $to, string $message): void
    {
        $this->messages[] = ['to' => $to, 'message' => $message];
    }

    /** The numeric code from the most recent message, if any. */
    public function lastCode(): ?string
    {
        $last = end($this->messages);

        if ($last === false) {
            return null;
        }

        preg_match('/(\d{4,8})/', $last['message'], $m);

        return $m[1] ?? null;
    }
}
