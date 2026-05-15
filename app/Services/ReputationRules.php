<?php

namespace App\Services;

class ReputationRules
{
    public const MIN_HELP_LEVEL = 2;
    public const MIN_HELP_XP = 100;
    public const ADMIN_FEE_BLOCK_LIMIT_CENTS = 500;

    public static function xpRequiredForLevel(int $level): int
    {
        return (int) round(100.0 * (1.105 ** ($level - 1)));
    }

    public static function totalXpRequiredToEnterLevel(int $level): int
    {
        $total = 0;
        for ($current = 1; $current < $level; $current++) {
            $total += self::xpRequiredForLevel($current);
        }
        return $total;
    }

    public static function levelForXp(int $totalXp): int
    {
        $level = 1;
        $remaining = $totalXp;
        while ($remaining >= self::xpRequiredForLevel($level) && $level < 1000) {
            $remaining -= self::xpRequiredForLevel($level);
            $level++;
        }
        return $level;
    }

    public static function xpIntoLevel(int $totalXp): int
    {
        return $totalXp - self::totalXpRequiredToEnterLevel(self::levelForXp($totalXp));
    }

    public static function supportLimitCents(int $level): int
    {
        if ($level < self::MIN_HELP_LEVEL) {
            return 0;
        }
        if ($level === self::MIN_HELP_LEVEL) {
            return 10000;
        }
        $amount = 100.0;
        for ($targetLevel = 3; $targetLevel <= $level; $targetLevel++) {
            $amount *= match (true) {
                $targetLevel === 3 => 1.5,
                in_array($targetLevel, [4, 5], true) => 1.4,
                in_array($targetLevel, [6, 7], true) => 1.3,
                in_array($targetLevel, [8, 9], true) => 1.2,
                default => 1.1,
            };
        }
        return max((int) round($amount) * 100, 10000);
    }

    public static function adminFeeFor(int $amountCents): int
    {
        return max(intdiv($amountCents, 100), 0);
    }

    public static function adminFeeLimitCents(int $level): int
    {
        return self::ADMIN_FEE_BLOCK_LIMIT_CENTS;
    }

    public static function canRequestHelp(object|array $user): bool
    {
        $level = (int) (is_array($user) ? $user['level'] : $user->level);
        $xp = (int) (is_array($user) ? $user['xp'] : $user->xp);
        return $level >= self::MIN_HELP_LEVEL && $xp >= self::MIN_HELP_XP;
    }

    public static function xpForCompletedReturn(int $amountCents, int $buffBps): int
    {
        $baseXp = max(intdiv($amountCents, 100), 1);
        return max(intdiv($baseXp * (10000 + $buffBps), 10000), 1);
    }

    public static function recalculateBuffBps(int $onTimeReturnedCents, int $earlyReturnedCents, int $guestsAtLevelFive): int
    {
        $onTimeBps = intdiv($onTimeReturnedCents, 100000) * 10;
        $earlyBps = intdiv($earlyReturnedCents, 100000) * 30;
        $guestBps = $guestsAtLevelFive * 10;
        return min($onTimeBps + $earlyBps + $guestBps, 10000);
    }
}
