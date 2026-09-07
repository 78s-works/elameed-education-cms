<?php

namespace App\Modules\Notifications\Services\Broadcasts;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Models\NotificationChannelSetting;

/**
 * Works out what an SMS blast will cost BEFORE it is sent, so the confirmation
 * screen can show "1,240 recipients · 2 segments each · 620.00 EGP" and nobody
 * fires an expensive send by accident.
 *
 * Segmentation follows the GSM/3GPP rules the aggregators bill on:
 *   - plain GSM-7 text: 160 characters alone, 153 per part once concatenated
 *   - anything outside GSM-7 (Arabic, emoji): UCS-2 — 70 alone, 67 per part
 *
 * The per-segment price comes from the academy's own SMS settings when it stored
 * one (its WE contract price), else `config('sms.price_per_segment_minor')`.
 */
class SmsCostEstimator
{
    /**
     * Characters representable in the GSM 03.38 default alphabet. Built in a
     * method rather than declared as a const: the alphabet contains `$`, and a
     * const expression cannot carry an interpolating double-quoted string.
     */
    private static function gsmBasic(): string
    {
        return '@£$¥èéùìòÇ'."\n".'Øø'."\r".'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?'
            .'¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
    }

    /** Each of these costs two GSM-7 characters (escape + char). */
    private static function gsmExtended(): string
    {
        return '^{}\\[~]|€';
    }

    /**
     * @return array{
     *     encoding: string,
     *     characters: int,
     *     segments_per_message: int,
     *     recipients: int,
     *     total_segments: int,
     *     price_per_segment_minor: int,
     *     total_cost_minor: int,
     *     currency: string
     * }
     */
    public function estimate(string $text, int $recipients, ?int $tenantId = null): array
    {
        $encoding = $this->isGsm7($text) ? 'GSM-7' : 'UCS-2';
        $length = $encoding === 'GSM-7' ? $this->gsm7Length($text) : mb_strlen($text, 'UTF-8');

        [$single, $concatenated] = $encoding === 'GSM-7' ? [160, 153] : [70, 67];

        if ($length === 0) {
            $segments = 0;
        } elseif ($length <= $single) {
            $segments = 1;
        } else {
            $segments = (int) ceil($length / $concatenated);
        }

        $price = $this->pricePerSegmentMinor($tenantId);
        $totalSegments = $segments * max(0, $recipients);

        return [
            'encoding' => $encoding,
            'characters' => $length,
            'segments_per_message' => $segments,
            'recipients' => max(0, $recipients),
            'total_segments' => $totalSegments,
            'price_per_segment_minor' => $price,
            'total_cost_minor' => $totalSegments * $price,
            'currency' => (string) config('sms.currency', 'EGP'),
        ];
    }

    /** Per-segment price in minor units (piastres), academy override first. */
    public function pricePerSegmentMinor(?int $tenantId): int
    {
        if ($tenantId !== null) {
            $setting = NotificationChannelSetting::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('channel', NotificationChannel::Sms->value)
                ->first();

            $override = $setting?->config['price_per_segment_minor'] ?? null;

            if (is_numeric($override) && (int) $override >= 0) {
                return (int) $override;
            }
        }

        return (int) config('sms.price_per_segment_minor', 0);
    }

    private function isGsm7(string $text): bool
    {
        $length = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');

            if (! str_contains(self::gsmBasic(), $char) && ! str_contains(self::gsmExtended(), $char)) {
                return false;
            }
        }

        return true;
    }

    /** GSM-7 billing length: extended characters count twice. */
    private function gsm7Length(string $text): int
    {
        $length = mb_strlen($text, 'UTF-8');
        $count = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');
            $count += str_contains(self::gsmExtended(), $char) ? 2 : 1;
        }

        return $count;
    }
}
