<?php

namespace App\Modules\Notifications\Support;

/**
 * Outcome of a single channel send (doc 10 §7 contract: { success, error, data }).
 */
final class ChannelResult
{
    /**
     * @param  array<string,mixed>  $data
     */
    private function __construct(
        public readonly bool $success,
        public readonly ?string $error = null,
        public readonly array $data = [],
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public static function ok(array $data = []): self
    {
        return new self(true, null, $data);
    }

    public static function fail(string $error): self
    {
        return new self(false, $error);
    }
}
