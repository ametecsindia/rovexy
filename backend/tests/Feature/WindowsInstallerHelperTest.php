<?php

declare(strict_types=1);

namespace Tests\Feature;

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

/**
 * deploy/windows/configure.php — the part of the Windows installer that can be checked without Windows:
 * .env values survive any password, and the certificate check refuses what the phone app would refuse.
 */
final class WindowsInstallerHelperTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/deploy/windows/configure.php';
    }

    public function test_env_values_written_by_the_installer_read_back_exactly(): void
    {
        $values = ['DB_PASSWORD' => 'p@ss"w$rd\x#1 \'q\' ${HOME}', 'ROVEXY_PHP_BINARY' => 'C:\ROVEXY\php\php.exe', 'DB_USERNAME' => 'rovexy'];
        $env = "APP_NAME=ROVEXY\nDB_PASSWORD=                                # CHANGE\n";
        foreach ($values as $k => $v) {
            $env = \envSet($env, $k, $v);
        }
        $this->assertSame($values, array_intersect_key(Dotenv::parse($env), $values));
        $this->assertSame($values['DB_PASSWORD'], \envRead($this->tmp($env))['DB_PASSWORD']);
    }

    public function test_certificate_check_refuses_self_signed_and_wrong_name_and_accepts_a_ca_signed_pfx(): void
    {
        $caKey = openssl_pkey_new(['private_key_bits' => 2048]);
        $ca = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test CA'], $caKey), null, $caKey, 30);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $conf = $this->tmp("[req]\ndistinguished_name=dn\n[dn]\n[ext]\nsubjectAltName=DNS:rovexy.client.test\n");
        $leaf = openssl_csr_sign(openssl_csr_new(['commonName' => 'rovexy.client.test'], $key), $ca, $caKey, 365,
            ['config' => $conf, 'x509_extensions' => 'ext']);
        openssl_pkcs12_export($leaf, $pfx, $key, 'pfx-pass', ['extracerts' => [$ca]]);
        $self = openssl_csr_sign(openssl_csr_new(['commonName' => 'rovexy.client.test'], $key), null, $key, 365);
        openssl_x509_export($self, $selfPem);
        openssl_pkey_export($key, $keyPem);

        $check = function (array $job): array {
            $e = $w = [];
            \loadCert($job, $e, $w);
            return $e;
        };
        $pfxFile = $this->tmp($pfx, '.pfx');
        $this->assertSame([], $check(['domain' => 'rovexy.client.test', 'cert' => $pfxFile, 'certpass' => 'pfx-pass']));
        $this->assertStringContainsString('password', implode(' ', $check(['domain' => 'rovexy.client.test', 'cert' => $pfxFile, 'certpass' => 'no'])));
        $this->assertStringContainsString('not for other.client.test', implode(' ', $check(['domain' => 'other.client.test', 'cert' => $pfxFile, 'certpass' => 'pfx-pass'])));
        $this->assertStringContainsString('self-signed', implode(' ', $check(['domain' => 'rovexy.client.test',
            'cert' => $this->tmp($selfPem, '.crt'), 'key' => $this->tmp($keyPem, '.key')])));
    }

    public function test_cloudflare_tunnel_mode_listens_on_this_computer_only_and_trusts_only_local_proxies(): void
    {
        $app = sys_get_temp_dir() . '/rx-tunnel-' . uniqid();
        @mkdir($app, 0755, true);
        $e = $w = [];
        \checkServer(['tls' => 'tunnel', 'domain' => 'rovexy.client.test'], $e, $w);
        $this->assertSame([[], []], [$e, $w]);   // no DNS or port 80/443 checks in tunnel mode
        \checkServer(['tls' => 'tunnel', 'domain' => 'https://bad'], $e, $w);
        $this->assertNotEmpty($e);

        \writeCaddyfile($app, 'rovexy.client.test', 'tunnel', '');
        $c = (string) file_get_contents("{$app}/caddy/Caddyfile");
        $this->assertTrue(\isTunnel($app));
        $this->assertStringContainsString("http://:" . \TUNNEL_PORT . " {\n\tbind 127.0.0.1", $c);
        $this->assertStringContainsString('auto_https off', $c);
        $this->assertStringContainsString('trusted_proxies static 127.0.0.1/32 ::1/128', $c);
        $this->assertStringContainsString('client_ip_headers Cf-Connecting-Ip X-Forwarded-For', $c);
        $this->assertStringNotContainsString('tls ', $c);   // no certificate on this server

        \writeCaddyfile($app, 'rovexy.client.test', 'le', 'it@client.test');
        $this->assertFalse(\isTunnel($app));
    }

    private function tmp(string $contents, string $ext = ''): string
    {
        $f = tempnam(sys_get_temp_dir(), 'rx') . $ext;
        file_put_contents($f, $contents);
        return $f;
    }
}
