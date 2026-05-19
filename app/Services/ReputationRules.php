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
        $limits = [
            2 => 2000,
            3 => 3000,
            4 => 4000,
            5 => 6300,
            6 => 10000,
            7 => 13000,
            8 => 16000,
            9 => 16000,
            10 => 16000,
            11 => 20000,
            12 => 25000,
            13 => 31000,
            14 => 39000,
            15 => 48000,
            16 => 58000,
            17 => 70000,
            18 => 84000,
            19 => 100000,
            20 => 41000,
            21 => 50000,
            22 => 60000,
            23 => 72000,
            24 => 86000,
            25 => 102000,
            26 => 122000,
            27 => 146000,
            28 => 175000,
            29 => 210000,
            30 => 250000,
            31 => 298000,
            32 => 355000,
            33 => 422000,
            34 => 502000,
            35 => 597000,
            36 => 710000,
            37 => 845000,
            38 => 1005000,
            39 => 1195000,
            40 => 1420000,
            41 => 1685000,
            42 => 2000000,
            43 => 2375000,
            44 => 2820000,
            45 => 3350000,
            46 => 3980000,
            47 => 4730000,
            48 => 5620000,
            49 => 6680000,
            50 => 7000000,
            51 => 8300000,
            52 => 9850000,
            53 => 11700000,
            54 => 13900000,
            55 => 16500000,
            56 => 19600000,
            57 => 23300000,
            58 => 27700000,
            59 => 32900000,
            60 => 39100000,
            61 => 46500000,
            62 => 55200000,
            63 => 65600000,
            64 => 78000000,
            65 => 92800000,
            66 => 110400000,
            67 => 131300000,
            68 => 156200000,
            69 => 185700000,
            70 => 220800000,
            71 => 262600000,
            72 => 312500000,
            73 => 371800000,
            74 => 442400000,
            75 => 526400000,
            76 => 626500000,
            77 => 745400000,
            78 => 886800000,
            79 => 1055000000,
            80 => 1255000000,
            81 => 1493000000,
            82 => 1776000000,
            83 => 2112000000,
            84 => 2512000000,
            85 => 2987000000,
            86 => 3552000000,
            87 => 4225000000,
            88 => 5025000000,
            89 => 5977000000,
            90 => 7108000000,
            91 => 8452000000,
            92 => 10047000000,
            93 => 11943000000,
            94 => 14196000000,
            95 => 16883000000,
            96 => 20078000000,
            97 => 23883000000,
            98 => 28400000000,
            99 => 33770000000,
            100 => 40150000000,
        ];
        return $limits[$level] ?? 0;
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
