<?php

namespace App\Services;

class PixCopyCode
{
    private const RANDOM_PIX_KEY_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/';

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
        if (preg_match(self::RANDOM_PIX_KEY_PATTERN, $clean) === 1) {
            return strtolower($clean);
        }

        throw new \InvalidArgumentException('Chave Pix do destinatario deve ser a chave aleatoria gerada pelo banco.');
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

}
