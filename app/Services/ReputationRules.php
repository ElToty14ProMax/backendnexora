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
            50 => 700000,
            51 => 830000,
            52 => 985000,
            53 => 1170000,
            54 => 1390000,
            55 => 1650000,
            56 => 1960000,
            57 => 2330000,
            58 => 2770000,
            59 => 3290000,
            60 => 3910000,
            61 => 4650000,
            62 => 5520000,
            63 => 6560000,
            64 => 7800000,
            65 => 9280000,
            66 => 11040000,
            67 => 13130000,
            68 => 15620000,
            69 => 18570000,
            70 => 22080000,
            71 => 26260000,
            72 => 31250000,
            73 => 37180000,
            74 => 44240000,
            75 => 52640000,
            76 => 62650000,
            77 => 74540000,
            78 => 88680000,
            79 => 105500000,
            80 => 125500000,
            81 => 149300000,
            82 => 177600000,
            83 => 211200000,
            84 => 251200000,
            85 => 298700000,
            86 => 355200000,
            87 => 422500000,
            88 => 502500000,
            89 => 597700000,
            90 => 710800000,
            91 => 845200000,
            92 => 1004700000,
            93 => 1194300000,
            94 => 1419600000,
            95 => 1688300000,
            96 => 2007800000,
            97 => 2388300000,
            98 => 2840000000,
            99 => 3377000000,
            100 => 82000000,
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
