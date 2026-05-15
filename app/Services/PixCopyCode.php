<?php

namespace App\Services;

class PixCopyCode
{
    public static function build(string $platformPixKey, int $amountCents, string $txid): string
    {
        $merchantAccount = self::tag('00', 'br.gov.bcb.pix')
            .self::tag('01', trim($platformPixKey))
            .self::tag('02', 'NEXORA '.$txid);

        $payload = self::tag('00', '01')
            .self::tag('01', '12')
            .self::tag('26', $merchantAccount)
            .self::tag('52', '0000')
            .self::tag('53', '986')
            .self::tag('54', number_format($amountCents / 100, 2, '.', ''))
            .self::tag('58', 'BR')
            .self::tag('59', self::cleanText('NEXORA', 25))
            .self::tag('60', self::cleanText('SAO PAULO', 15))
            .self::tag('62', self::tag('05', self::cleanTxid($txid)))
            .'6304';

        return $payload.self::crc16($payload);
    }

    private static function tag(string $id, string $value): string
    {
        $length = strlen($value);
        if ($length > 99) {
            $value = substr($value, 0, 99);
            $length = strlen($value);
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
}
