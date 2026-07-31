<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for LeadRouter_Sender (base sender utilities).
 * Exposes protected static helpers via a test subclass.
 */
class TestableSender extends LeadRouter_Sender
{
    public static function pub_is_success(int $http_code, string $body, $headers, int $partner_id, array $opts): bool
    {
        return parent::is_success($http_code, $body, $headers, $partner_id, $opts);
    }

    public static function pub_map_error_code(?int $http_code, ?string $body, $transport_err): string
    {
        return parent::map_error_code($http_code, $body, $transport_err);
    }

    public static function pub_truncate(?string $s, int $max = 2000): string
    {
        return parent::truncate($s, $max);
    }

    public static function pub_now_mysql_est(): string
    {
        return parent::now_mysql_est();
    }
}

// ---------------------------------------------------------------------------

class SenderTest extends TestCase
{
    // =========================================================================
    // is_success
    // =========================================================================

    public function test_is_success_200(): void
    {
        $this->assertTrue(TestableSender::pub_is_success(200, '', [], 0, []));
    }

    public function test_is_success_201(): void
    {
        $this->assertTrue(TestableSender::pub_is_success(201, '', [], 0, []));
    }

    public function test_is_success_204(): void
    {
        $this->assertTrue(TestableSender::pub_is_success(204, '', [], 0, []));
    }

    public function test_is_success_400_is_false(): void
    {
        $this->assertFalse(TestableSender::pub_is_success(400, '', [], 0, []));
    }

    public function test_is_success_500_is_false(): void
    {
        $this->assertFalse(TestableSender::pub_is_success(500, '', [], 0, []));
    }

    public function test_is_success_non_2xx_with_ok_true_body(): void
    {
        // Body {"ok":true} on a non-2xx → should still become true per class logic
        $this->assertTrue(TestableSender::pub_is_success(400, '{"ok":true}', [], 0, []));
    }

    public function test_is_success_non_2xx_with_ok_false_body(): void
    {
        $this->assertFalse(TestableSender::pub_is_success(400, '{"ok":false}', [], 0, []));
    }

    public function test_is_success_non_2xx_empty_body_is_false(): void
    {
        $this->assertFalse(TestableSender::pub_is_success(404, '', [], 0, []));
    }

    // =========================================================================
    // map_error_code
    // =========================================================================

    public function test_map_error_code_timeout(): void
    {
        $err = new WP_Error('http_timeout', 'Connection timed out after 10 seconds');
        $this->assertSame('http_timeout', TestableSender::pub_map_error_code(null, null, $err));
    }

    public function test_map_error_code_transport_error(): void
    {
        $err = new WP_Error('http_request_failed', 'Could not connect to server');
        $this->assertSame('transport_error', TestableSender::pub_map_error_code(null, null, $err));
    }

    public function test_map_error_code_5xx(): void
    {
        $this->assertSame('http_5xx', TestableSender::pub_map_error_code(503, '', null));
    }

    public function test_map_error_code_500(): void
    {
        $this->assertSame('http_5xx', TestableSender::pub_map_error_code(500, '', null));
    }

    public function test_map_error_code_429(): void
    {
        $this->assertSame('http_429', TestableSender::pub_map_error_code(429, '', null));
    }

    public function test_map_error_code_4xx(): void
    {
        $this->assertSame('http_4xx', TestableSender::pub_map_error_code(400, '', null));
    }

    public function test_map_error_code_404_is_4xx(): void
    {
        $this->assertSame('http_4xx', TestableSender::pub_map_error_code(404, '', null));
    }

    public function test_map_error_code_unknown_http(): void
    {
        $this->assertSame('unknown_http', TestableSender::pub_map_error_code(0, '', null));
    }

    // =========================================================================
    // truncate
    // =========================================================================

    public function test_truncate_short_string_unchanged(): void
    {
        $this->assertSame('hello', TestableSender::pub_truncate('hello', 100));
    }

    public function test_truncate_long_string_is_cut(): void
    {
        $s      = str_repeat('a', 2100);
        $result = TestableSender::pub_truncate($s, 2000);
        // substr($s, 0, 2000) gives 2000 chars + '…' (1 mb char) = 2001 mb chars
        $this->assertSame(2001, mb_strlen($result));
        $this->assertStringEndsWith('…', $result);
    }

    public function test_truncate_null_returns_empty_string(): void
    {
        $this->assertSame('', TestableSender::pub_truncate(null));
    }

    public function test_truncate_empty_string(): void
    {
        $this->assertSame('', TestableSender::pub_truncate(''));
    }

    // =========================================================================
    // now_mysql_est
    // =========================================================================

    public function test_now_mysql_est_returns_datetime_format(): void
    {
        $ts = TestableSender::pub_now_mysql_est();
        // should match YYYY-MM-DD HH:MM:SS
        $this->assertRegExp('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ts);
    }
}
