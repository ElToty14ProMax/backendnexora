<?php

namespace App\Services;

class CpfValidator
{
    public static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    public static function isValid(string $value): bool
    {
        $cpf = self::digits($value);
        if (strlen($cpf) !== 11 || count(array_unique(str_split($cpf))) === 1) {
            return false;
        }

        return (int) $cpf[9] === self::checkDigit($cpf, 9)
            && (int) $cpf[10] === self::checkDigit($cpf, 10);
    }

    private static function checkDigit(string $cpf, int $length): int
    {
        $sum = 0;
        for ($index = 0; $index < $length; $index++) {
            $sum += (int) $cpf[$index] * ($length + 1 - $index);
        }
        $result = 11 - ($sum % 11);
        return $result >= 10 ? 0 : $result;
    }
}
