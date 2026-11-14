<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Identity\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The codes must be exactly what an authenticator app shows: RFC 6238, Appendix B (SHA-1 rows). */
final class TotpTest extends TestCase
{
    /** The RFC's test secret, the ASCII string "12345678901234567890", in base32. */
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return list<array{int, string}> time, last six digits of the RFC's eight-digit value */
    public static function rfcVectors(): array
    {
        return [
            [59, '287082'],
            [1111111109, '081804'],
            [1111111111, '050471'],
            [1234567890, '005924'],
            [2000000000, '279037'],
            [20000000000, '353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_codes_match_the_rfc_test_vectors(int $time, string $code): void
    {
        $this->assertSame($code, Totp::codeAt(self::SECRET, Totp::stepAt($time)));
    }

    public function test_base32_round_trips_and_matches_the_known_encoding(): void
    {
        $this->assertSame(self::SECRET, Totp::base32Encode('12345678901234567890'));
        $this->assertSame('12345678901234567890', Totp::base32Decode(self::SECRET));
        $secret = Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertSame($secret, Totp::base32Encode(Totp::base32Decode($secret)));
    }

    public function test_a_code_is_accepted_one_step_either_side_and_no_further(): void
    {
        $now = 1_800_000_000;
        $step = Totp::stepAt($now);

        foreach ([-1, 0, 1] as $drift) {
            $this->assertSame($step + $drift, Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, $step + $drift), null, $now));
        }
        $this->assertNull(Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, $step - 2), null, $now));
        $this->assertNull(Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, $step + 2), null, $now));
    }

    public function test_a_code_is_never_accepted_twice(): void
    {
        $now = 1_800_000_000;
        $step = Totp::stepAt($now);
        $code = Totp::codeAt(self::SECRET, $step);

        $this->assertSame($step, Totp::verify(self::SECRET, $code, null, $now));
        $this->assertNull(Totp::verify(self::SECRET, $code, $step, $now), 'the same step again');
        $this->assertNull(Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, $step - 1), $step, $now), 'an older step');
        $this->assertSame($step + 1, Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, $step + 1), $step, $now));
    }

    public function test_anything_that_is_not_six_digits_is_refused(): void
    {
        $now = 1_800_000_000;
        foreach (['', '12345', '1234567', 'abcdef', '12 34', "123456\n7"] as $bad) {
            $this->assertNull(Totp::verify(self::SECRET, $bad, null, $now), var_export($bad, true));
        }
        // Authenticator apps show the code in two groups; a space is fine.
        $code = Totp::codeAt(self::SECRET, Totp::stepAt($now));
        $this->assertNotNull(Totp::verify(self::SECRET, substr($code, 0, 3) . ' ' . substr($code, 3), null, $now));
    }

    public function test_the_qr_address_names_the_account_and_issuer(): void
    {
        $this->assertSame(
            'otpauth://totp/Ledgerly:hana%40example.test?secret=' . self::SECRET . '&issuer=Ledgerly&algorithm=SHA1&digits=6&period=30',
            Totp::uri(self::SECRET, 'hana@example.test', 'Ledgerly'),
        );
    }
}
