<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Time-based one-time codes as authenticator apps compute them: RFC 6238 over RFC 4226, HMAC-SHA1, six
 * digits, 30-second steps. Checked against the RFC's own test vectors in tests/Unit/TotpTest.php.
 *
 * A code is accepted for the current step and one step either side (clock drift), and only for a step later
 * than the last one accepted for that user, so a code that was seen once cannot be used again.
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    private const WINDOW = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new 160-bit secret, in the base32 form authenticator apps take. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function codeAt(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $number = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($number % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function stepAt(int $timestamp): int
    {
        return intdiv($timestamp, self::PERIOD);
    }

    /**
     * @return int|null the step the code belongs to, or null when it is wrong, expired or already used
     */
    public static function verify(string $secret, string $code, ?int $lastStep, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }

        $current = self::stepAt($timestamp ?? time());
        $matched = null;
        // Every candidate is compared, in constant time, so the answer's timing says nothing about which matched.
        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if (hash_equals(self::codeAt($secret, $step), $code) && ($lastStep === null || $step > $lastStep)) {
                $matched ??= $step;
            }
        }

        return $matched;
    }

    /** What the QR code holds: scanned by the authenticator app to add the account. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($text, '='))) as $char) {
            $value = strpos(self::BASE32, $char);
            if ($value === false) {
                throw new \InvalidArgumentException('Not a base32 secret.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
