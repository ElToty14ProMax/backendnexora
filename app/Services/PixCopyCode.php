<?php

namespace App\Services;

class PixCopyCode
{
    public static function build(string $receiverPixKey, int $amountCents, string $txid, string $merchantName = 'NEXORA', string $merchantCity = 'SAO PAULO'): string
    {
        $pixKey = self::normalizePixKey($receiverPixKey);
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Valor Pix invalido.');
        }

        $merchantAccount = self::tag('00', 'br.gov.bcb.pix')
            .self::tag('01', $pixKey);

        $payload = self::tag('00', '01')
            .self::tag('01', '12')
            .self::tag('26', $merchantAccount)
            .self::tag('52', '0000')
            .self::tag('53', '986')
            .self::tag('54', number_format($amountCents / 100, 2, '.', ''))
            .self::tag('58', 'BR')
            .self::tag('59', self::cleanText($merchantName, 25))
            .self::tag('60', self::cleanText($merchantCity, 15))
            .self::tag('62', self::tag('05', self::cleanTxid($txid)))
            .'6304';

        return $payload.self::crc16($payload);
    }

    public static function normalizePixKey(string $value): string
    {
        $clean = trim($value);
        $digits = preg_replace('/\D+/', '', $clean) ?? '';

        $isRandom = preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $clean) === 1;
        if ($isRandom) {
            return strtolower($clean);
        }

        if (filter_var($clean, FILTER_VALIDATE_EMAIL) !== false) {
            return $clean;
        }

        if (preg_match('/^\+55\d{10,11}$/', $clean) === 1) {
            return $clean;
        }

        if (strlen($digits) === 11 && self::isValidCpf($digits)) {
            return $digits;
        }

        if (strlen($digits) === 11) {
            return '+55'.$digits;
        }

        if (strlen($digits) === 13 && str_starts_with($digits, '55')) {
            return '+'.$digits;
        }

        throw new \InvalidArgumentException('Chave Pix do destinatario invalida.');
    }

    private static function tag(string $id, string $value): string
    {
        $length = strlen($value);
        if ($length > 99) {
            throw new \InvalidArgumentException("Campo Pix {$id} excede 99 bytes.");
        }
        return $id.str_pad((string) $length, 2, '0', STR_PAD_LEFT).$value;
    }

    private static function cleanTxid(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($value)) ?: 'NEXORA';
        return substr($clean, 0, 25);
    }

    private static function cleanText(string $value, int $max): string
    {
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', strtoupper($value)) ?: $value;
        $clean = trim(preg_replace('/[^A-Z0-9 .-]/', '', $normalized) ?: 'NEXORA');
        return substr($clean !== '' ? $clean : 'NEXORA', 0, $max);
    }

    private static function crc16(string $payload): string
    {
        $crc = 0xFFFF;
        foreach (str_split($payload) as $char) {
            $crc ^= ord($char) << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) !== 0
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }
        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    private static function isValidCpf(string $cpf): bool
    {
        if (strlen($cpf) !== 11 || count(array_unique(str_split($cpf))) === 1) {
            return false;
        }
        $digit = function (int $length) use ($cpf): int {
            $sum = 0;
            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $cpf[$i] * ($length + 1 - $i);
            }
            $result = ($sum * 10) % 11;
            return $result === 10 ? 0 : $result;
        };
        return (int) $cpf[9] === $digit(9) && (int) $cpf[10] === $digit(10);
    }
}
