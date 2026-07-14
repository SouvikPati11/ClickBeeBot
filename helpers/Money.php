<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * USD money helpers.
 *
 * Internal currency is USD stored as DECIMAL(18,6). All arithmetic that
 * derives worker reward / platform fee from advertiser CPC lives here so the
 * rounding rules are applied consistently everywhere.
 */
final class Money
{
    public const SCALE = 6;

    public static function round(float $amount): float
    {
        return round($amount, self::SCALE);
    }

    public static function format(float $amount): string
    {
        return '$' . number_format($amount, 4, '.', '');
    }

    /**
     * Split an advertiser CPC into worker reward and platform fee.
     *
     * @return array{worker: float, fee: float} Worker reward and platform fee.
     */
    public static function splitCpc(float $cpc, float $platformFeePercent): array
    {
        $fee = self::round($cpc * ($platformFeePercent / 100));
        $worker = self::round($cpc - $fee);
        return ['worker' => $worker, 'fee' => $fee];
    }

    public static function applyWithdrawFee(float $amount, bool $enabled, string $type, float $value): float
    {
        if (!$enabled || $value <= 0) {
            return 0.0;
        }
        return $type === 'percentage'
            ? self::round($amount * ($value / 100))
            : self::round($value);
    }
}
