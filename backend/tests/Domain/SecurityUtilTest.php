<?php

declare(strict_types=1);

use App\Domain\Amapi\ServiceAccountJwt;
use App\Domain\Security\LogRedactor;
use App\Domain\Security\SpreadsheetSanitiser;
use App\Domain\Security\Totp;
use App\Domain\Security\TurnCredentials;
use PHPUnit\Framework\TestCase;

final class SecurityUtilTest extends TestCase
{
    public function test_totp_matches_rfc6238_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        // RFC 6238 Appendix B (SHA1), truncated to 6 digits.
        self::assertSame('287082', Totp::code($secret, 59));
        self::assertSame('081804', Totp::code($secret, 1111111109));
        self::assertSame('050471', Totp::code($secret, 1111111111));
        self::assertSame('005924', Totp::code($secret, 1234567890));
        self::assertSame('279037', Totp::code($secret, 2000000000));
    }

    public function test_totp_verify_drift_and_replay(): void
    {
        $s = Totp::generateSecret();
        $t = 1_800_000_000;
        $step = Totp::verify($s, Totp::code($s, $t - 30), $t);
        self::assertSame(intdiv($t, 30) - 1, $step);
        self::assertNull(Totp::verify($s, Totp::code($s, $t), $t, intdiv($t, 30)), 'same step must not be reusable');
        self::assertNull(Totp::verify($s, Totp::code($s, $t - 120), $t));
        self::assertNull(Totp::verify($s, 'abcdef', $t));
    }

    public function test_formula_injection_neutralised(): void
    {
        self::assertSame("'=HYPERLINK(\"x\")", SpreadsheetSanitiser::cell('=HYPERLINK("x")'));
        self::assertSame("'@SUM(A1)", SpreadsheetSanitiser::cell('@SUM(A1)'));
        self::assertSame("'-2+3", SpreadsheetSanitiser::cell('-2+3'));
        self::assertSame('+919876543210', SpreadsheetSanitiser::cell('+919876543210'));
        self::assertSame('-12.5', SpreadsheetSanitiser::cell('-12.5'));
        self::assertSame('Ravi', SpreadsheetSanitiser::cell('Ravi'));
        self::assertSame(5, SpreadsheetSanitiser::cell(5));
    }

    public function test_log_redaction(): void
    {
        $r = LogRedactor::redact(['password' => 'x', 'nested' => ['refresh_token' => 'y'], 'msg' => 'Auth Bearer abc.def calling +91 98765 43210']);
        self::assertSame('[REDACTED]', $r['password']);
        self::assertSame('[REDACTED]', $r['nested']['refresh_token']);
        self::assertStringNotContainsString('abc.def', $r['msg']);
        self::assertStringNotContainsString('98765', $r['msg']);
        self::assertStringContainsString('3210', $r['msg']);
    }

    public function test_turn_credentials_follow_coturn_rest_scheme(): void
    {
        $c = TurnCredentials::issue('s3cret', 7, 'sess1', 1000, 600);
        self::assertSame('1600:c7:sess1', $c['username']);
        self::assertSame(base64_encode(hash_hmac('sha1', '1600:c7:sess1', 's3cret', true)), $c['credential']);
    }

    public function test_service_account_jwt_verifies_with_public_key(): void
    {
        $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($k, $privPem);
        $jwt = ServiceAccountJwt::assertion('sa@p.iam.gserviceaccount.com', $privPem, 1000);
        [$h, $c, $s] = explode('.', $jwt);
        $sig = base64_decode(strtr($s, '-_', '+/'));
        self::assertSame(1, openssl_verify("{$h}.{$c}", $sig, openssl_pkey_get_details($k)['key'], OPENSSL_ALGO_SHA256));
        $claims = json_decode(base64_decode(strtr($c, '-_', '+/')), true);
        self::assertSame(4600, $claims['exp']);
        self::assertSame(ServiceAccountJwt::SCOPE, $claims['scope']);
    }
}
