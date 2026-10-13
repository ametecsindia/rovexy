<?php

declare(strict_types=1);

use App\Telephony\EslConnection;
use App\Telephony\TelephonyUnavailable;
use PHPUnit\Framework\TestCase;

/** Exercises the real socket client against a scripted fake FreeSWITCH event socket (forked process). */
final class EslConnectionTest extends TestCase
{
    private function fakeServer(callable $script): array
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available on Windows; this test runs on Linux/CI.');
        }
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        $pid = pcntl_fork();
        if ($pid === 0) {
            $conn = stream_socket_accept($server, 5);
            $script($conn);
            fclose($conn);
            exit(0);
        }
        fclose($server);
        return [$port, $pid];
    }

    private static function readCommand($conn): string
    {
        $buf = '';
        while (!str_contains($buf, "\n\n")) {
            $buf .= fread($conn, 1);
        }
        return trim($buf);
    }

    public function test_auth_originate_and_event_stream(): void
    {
        [$port, $pid] = $this->fakeServer(function ($c) {
            fwrite($c, "Content-Type: auth/request\n\n");
            $auth = self::readCommand($c);
            fwrite($c, $auth === 'auth ClueCon-test' ? "Content-Type: command/reply\nReply-Text: +OK accepted\n\n" : "Content-Type: command/reply\nReply-Text: -ERR invalid\n\n");
            $cmd = self::readCommand($c);
            $ev = "Event-Name: CHANNEL_ANSWER\nvariable_ametecs_call_id: abc\n\n";
            // Interleave an event before the reply to prove frames are routed by type.
            fwrite($c, "Content-Length: " . strlen($ev) . "\nContent-Type: text/event-plain\n\n" . $ev);
            fwrite($c, "Content-Type: command/reply\nReply-Text: +OK Job-UUID: 1\nX-Echo: " . rawurlencode($cmd) . "\n\n");
            self::readCommand($c); // exit
        });
        $esl = new EslConnection('127.0.0.1', $port, 'ClueCon-test');
        $esl->connect();
        $reply = $esl->send('bgapi originate {x=1}user/1000 &park()');
        self::assertSame('+OK Job-UUID: 1', $reply['headers']['Reply-Text']);
        self::assertSame('bgapi originate {x=1}user/1000 &park()', rawurldecode($reply['headers']['X-Echo']));
        self::assertSame('CHANNEL_ANSWER', $esl->nextEvent()['Event-Name']);
        $esl->close();
        pcntl_waitpid($pid, $status);
    }

    public function test_bad_password_and_multiline_injection_rejected(): void
    {
        [$port, $pid] = $this->fakeServer(function ($c) {
            fwrite($c, "Content-Type: auth/request\n\n");
            self::readCommand($c);
            fwrite($c, "Content-Type: command/reply\nReply-Text: -ERR invalid\n\n");
        });
        $esl = new EslConnection('127.0.0.1', $port, 'wrong');
        try {
            $esl->connect();
            self::fail('accepted bad auth');
        } catch (TelephonyUnavailable $e) {
            self::assertStringContainsString('rejected', $e->getMessage());
        }
        pcntl_waitpid($pid, $status);
        $this->expectException(InvalidArgumentException::class);
        (new EslConnection('127.0.0.1', 1, 'x'))->send("api status\n\napi shutdown");
    }

    public function test_unreachable_server_is_reported(): void
    {
        $this->expectException(TelephonyUnavailable::class);
        (new EslConnection('127.0.0.1', 1, 'x', 0.5))->connect();
    }
}
