<?php

namespace App\Services;

use App\Exceptions\ApiException;

class SecurityService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const RANDOM_PIX_KEY_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/';

    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function isValidEmail(string $email): bool
    {
        return strlen($email) >= 6 && strlen($email) <= 254
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function isValidPixKey(string $value): bool
    {
        return preg_match(self::RANDOM_PIX_KEY_PATTERN, trim($value)) === 1;
    }

    public function isValidSha256(string $value): bool
    {
        return preg_match('/^[0-9a-fA-F]{64}$/', trim($value)) === 1;
    }

    public function hashCpf(string $cpf): string
    {
        return $this->hmac('cpf:'.CpfValidator::digits($cpf));
    }

    public function hashToken(string $token): string
    {
        return $this->base64Url(hash('sha256', $token, true));
    }

    public function hashVerificationCode(string $email, string $code): string
    {
        return $this->hmac('verify:'.$this->normalizeEmail($email).':'.$code);
    }

    public function hashRecoveryCode(string $email, string $code): string
    {
        return $this->hmac('recover:'.$this->normalizeEmail($email).':'.$code);
    }

    public function newVerificationCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function newToken(): string
    {
        return $this->base64Url(random_bytes(32));
    }

    public function publicId(): string
    {
        return 'NX-'.$this->randomCode(8);
    }

    public function inviteCode(): string
    {
        return $this->randomCode(8);
    }

    public function supportCode(): string
    {
        return 'AP-'.$this->randomCode(7);
    }

    public function paymentReference(string $contributionId): string
    {
        return strtoupper('NX'.substr(preg_replace('/[^A-Za-z0-9]/', '', $this->hmac('payment:'.$contributionId)), 0, 23));
    }

    public function hashPassword(string $password): string
    {
        $salt = random_bytes(16);
        $iterations = 210000;
        $hash = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
        return implode('$', ['pbkdf2_sha256', (string) $iterations, $this->base64Url($salt), $this->base64Url($hash)]);
    }

    public function verifyPassword(string $password, string $stored): bool
    {
        $parts = explode('$', $stored);
        if (count($parts) !== 4 || $parts[0] !== 'pbkdf2_sha256') {
            return false;
        }
        $iterations = (int) $parts[1];
        $salt = $this->base64UrlDecode($parts[2]);
        $expected = $this->base64UrlDecode($parts[3]);
        $actual = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
        return hash_equals($expected, $actual);
    }

    public function encrypt(string $value): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $this->dataKey(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false) {
            throw new ApiException(500, 'Erro interno.');
        }
        return $this->base64Url($iv).'.'.$this->base64Url($ciphertext.$tag);
    }

    public function decrypt(string $value): string
    {
        $parts = explode('.', $value);
        if (count($parts) !== 2) {
            throw new ApiException(500, 'Payload criptografado invalido.');
        }
        $iv = $this->base64UrlDecode($parts[0]);
        $payload = $this->base64UrlDecode($parts[1]);
        $tag = substr($payload, -16);
        $ciphertext = substr($payload, 0, -16);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->dataKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new ApiException(500, 'Nao foi possivel descriptografar dados.');
        }
        return $plain;
    }

    private function randomCode(int $size): string
    {
        $result = '';
        for ($i = 0; $i < $size; $i++) {
            $result .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $result;
    }

    private function hmac(string $message): string
    {
        return $this->base64Url(hash_hmac('sha256', $message, $this->cpfPepper(), true));
    }

    private function dataKey(): string
    {
        $raw = config('nexora.data_key_b64');
        $key = $raw ? base64_decode($raw, true) : hash('sha256', 'nexora-local-dev-data-key-change-before-production', true);
        if ($key === false || ! in_array(strlen($key), [16, 24, 32], true)) {
            throw new ApiException(500, 'NEXORA_DATA_KEY_B64 invalida.');
        }
        if (strlen($key) !== 32) {
            return hash('sha256', $key, true);
        }
        return $key;
    }

    private function cpfPepper(): string
    {
        return (string) config('nexora.cpf_pepper');
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padded = str_pad(strtr($value, '-_', '+/'), strlen($value) % 4 === 0 ? strlen($value) : strlen($value) + 4 - strlen($value) % 4, '=', STR_PAD_RIGHT);
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new ApiException(400, 'Base64 invalido.');
        }
        return $decoded;
    }
}
