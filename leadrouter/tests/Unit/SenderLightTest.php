<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for LeadRouter_Sender_Light internal methods.
 *
 * Because the methods are protected static, we use a test subclass to expose them
 * and PHP's ReflectionMethod for those that are truly protected with no subclass workaround.
 */

/**
 * Exposes protected static methods of LeadRouter_Sender_Light for testing.
 */
class TestableSenderLight extends LeadRouter_Sender_Light
{
    public static function pub_parse_and_classify(array $req, array $resp): array
    {
        return parent::parse_and_classify($req, $resp);
    }

    public static function pub_array_remove_empty($value)
    {
        return parent::array_remove_empty($value);
    }

    public static function pub_soft_validate(array $payload): array
    {
        return parent::soft_validate($payload);
    }

    public static function pub_make_idempotency_key(array $our_payload): string
    {
        return parent::make_idempotency_key($our_payload);
    }

    public static function pub_mask_headers(array $headers): array
    {
        return parent::mask_headers($headers);
    }

    public static function pub_mask_payload($payload)
    {
        return parent::mask_payload($payload);
    }

    public static function pub_retry_after_seconds($headers): ?int
    {
        return parent::retry_after_seconds($headers);
    }

    public static function pub_array_to_xml_string(array $arr, string $root = 'payload'): string
    {
        return parent::array_to_xml_string($arr, $root);
    }

    public static function pub_backoff_us(int $attempt): int
    {
        return parent::backoff_us($attempt);
    }

    public static function pub_gen_uuid_v4(): string
    {
        return parent::gen_uuid_v4();
    }

    public static function pub_dot_flatten_local(array $arr, string $prefix = ''): array
    {
        return parent::dot_flatten_local($arr, $prefix);
    }
}

// ---------------------------------------------------------------------------

class SenderLightTest extends TestCase
{
    // =========================================================================
    // parse_and_classify
    // =========================================================================

    private function make_req(bool $require_ok_json = false): array
    {
        return [
            'endpoint' => 'https://example.com/api',
            'method'   => 'POST',
            'headers'  => [],
            'body'     => '{}',
            'payload'  => [],
            'meta'     => ['body_type' => 'json', 'require_ok_json' => $require_ok_json],
        ];
    }

    public function test_classify_2xx_success(): void
    {
        $resp = ['is_wp_error' => false, 'status_code' => 200, 'body_raw' => '{}'];
        $r = TestableSenderLight::pub_parse_and_classify($this->make_req(), $resp);
        $this->assertTrue($r['success']);
        $this->assertFalse($r['retryable']);
        $this->assertNull($r['error_code']);
    }

    public function test_classify_201_success(): void
    {
        $resp = ['is_wp_error' => false, 'status_code' => 201, 'body_raw' => ''];
        $r = TestableSenderLight::pub_parse_and_classify($this->make_req(), $resp);
        $this->assertTrue($r['success']);
    }

    public function test_classify_2xx_require_ok_json_missing_is_failure(): void
    {
        $resp = ['is_wp_error' => false, 'status_code' => 200, 'body_raw' => '{"status":"ok"}'];
        $r = TestableSenderLight::pub_parse_and_classify($this->make_req(true), $resp);
        $this->assertFalse($r['success']);
        $this->assertSame('ok_json_missing', $r['error_code']);
    }

    public function test_classify_2xx_require_ok_json_present_is_success(): void
    {
        $resp = ['is_wp_error' => false, 'status_code' => 200, 'body_raw' => '{"ok":true}'];
        $r = TestableSenderLight::pub_parse_and_classify($this->make_req(true), $resp);
        $this->assertTrue($r['success']);
    }

    public function test_classify_500_retryable(): void
    {
        $resp = ['is_wp_error' => false, 'status_code' => 500, 'body_raw' => ''];
        $r = TestableSenderLight::pub_parse_and_classify($this->make_req(), $resp);
        $this->assertFalse($r['success']);
        $this->assertTrue($r['retryable']);
        $this->assertSame('retryable_http', $r['error_code']);
    }

    public function test_classify_429_retryable(): void
    {
        $resp = ['is_wp_error' => false, 'status_code' => 429, 'body_raw' => ''];
        $r = TestableSenderLight::pub_parse_and_classify($this->make_req(), $resp);
        $this->assertTrue($r['retryable']);
    }

    public function test_classify_408_retryable(): void
    {
        $resp = ['is_wp_error' => false, 'status_code' => 408, 'body_raw' => ''];
        $r = TestableSenderLight::pub_parse_and_classify($this->make_req(), $resp);
        $this->assertTrue($r['retryable']);
    }

    public function test_classify_404_hard_fail(): void
    {
        $resp = ['is_wp_error' => false, 'status_code' => 404, 'body_raw' => ''];
        $r = TestableSenderLight::pub_parse_and_classify($this->make_req(), $resp);
        $this->assertFalse($r['success']);
        $this->assertFalse($r['retryable']);
        $this->assertSame('http_404', $r['error_code']);
    }

    public function test_classify_wp_error_is_retryable(): void
    {
        $resp = [
            'is_wp_error' => true,
            'wp_error'    => ['code' => 'http_request_failed', 'message' => 'Connection timed out'],
        ];
        $r = TestableSenderLight::pub_parse_and_classify($this->make_req(), $resp);
        $this->assertFalse($r['success']);
        $this->assertTrue($r['retryable']);
        $this->assertSame('http_request_failed', $r['error_code']);
    }

    public function test_classify_extracts_external_id_from_json(): void
    {
        $resp = ['is_wp_error' => false, 'status_code' => 200, 'body_raw' => '{"id":"lead-abc-123"}'];
        $r = TestableSenderLight::pub_parse_and_classify($this->make_req(), $resp);
        $this->assertTrue($r['success']);
        $this->assertSame('lead-abc-123', $r['external_id']);
    }

    public function test_classify_extracts_uuid_from_json(): void
    {
        $resp = ['is_wp_error' => false, 'status_code' => 200, 'body_raw' => '{"uuid":"abc-xyz"}'];
        $r = TestableSenderLight::pub_parse_and_classify($this->make_req(), $resp);
        $this->assertSame('abc-xyz', $r['external_id']);
    }

    // =========================================================================
    // array_remove_empty
    // =========================================================================

    public function test_array_remove_empty_removes_null_and_empty_string(): void
    {
        $input  = ['a' => 'hello', 'b' => null, 'c' => '', 'd' => 0];
        $result = TestableSenderLight::pub_array_remove_empty($input);
        $this->assertArrayHasKey('a', $result);
        $this->assertArrayHasKey('d', $result);  // 0 is NOT empty by the logic (only null/'')
        $this->assertArrayNotHasKey('b', $result);
        $this->assertArrayNotHasKey('c', $result);
    }

    public function test_array_remove_empty_recursive(): void
    {
        $input  = ['outer' => ['inner' => null, 'keep' => 'yes']];
        $result = TestableSenderLight::pub_array_remove_empty($input);
        $this->assertArrayHasKey('outer', $result);
        $this->assertArrayHasKey('keep', $result['outer']);
        $this->assertArrayNotHasKey('inner', $result['outer']);
    }

    public function test_array_remove_empty_scalar_passthrough(): void
    {
        $this->assertSame('hello', TestableSenderLight::pub_array_remove_empty('hello'));
        $this->assertSame(0, TestableSenderLight::pub_array_remove_empty(0));
    }

    // =========================================================================
    // soft_validate
    // =========================================================================

    public function test_soft_validate_valid_email(): void
    {
        $warnings = TestableSenderLight::pub_soft_validate(['email' => 'user@example.com']);
        $this->assertEmpty($warnings);
    }

    public function test_soft_validate_invalid_email(): void
    {
        $warnings = TestableSenderLight::pub_soft_validate(['email' => 'not-an-email']);
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('email', $warnings[0]);
    }

    public function test_soft_validate_valid_phone(): void
    {
        $warnings = TestableSenderLight::pub_soft_validate(['phone' => '123-456-7890']);
        $phone_warn = array_filter($warnings, fn($w) => str_starts_with($w, 'phone:'));
        $this->assertEmpty($phone_warn);
    }

    public function test_soft_validate_invalid_phone(): void
    {
        $warnings = TestableSenderLight::pub_soft_validate(['phone' => '1234567890']);
        $phone_warn = array_filter($warnings, fn($w) => str_starts_with($w, 'phone:'));
        $this->assertNotEmpty($phone_warn);
    }

    public function test_soft_validate_valid_state(): void
    {
        $warnings = TestableSenderLight::pub_soft_validate(['os' => 'CA', 'ds' => 'TX']);
        $state_warn = array_filter($warnings, fn($w) => str_starts_with($w, 'os:') || str_starts_with($w, 'ds:'));
        $this->assertEmpty($state_warn);
    }

    public function test_soft_validate_invalid_state(): void
    {
        $warnings = TestableSenderLight::pub_soft_validate(['os' => 'California']);
        $this->assertNotEmpty($warnings);
    }

    public function test_soft_validate_valid_zip(): void
    {
        $warnings = TestableSenderLight::pub_soft_validate(['oz' => '90210', 'dz' => '100011234']);
        $zip_warn = array_filter($warnings, fn($w) => str_starts_with($w, 'oz:') || str_starts_with($w, 'dz:'));
        $this->assertEmpty($zip_warn);
    }

    public function test_soft_validate_invalid_zip(): void
    {
        $warnings = TestableSenderLight::pub_soft_validate(['oz' => '9021']);
        $this->assertNotEmpty($warnings);
    }

    public function test_soft_validate_valid_ps_date(): void
    {
        $warnings = TestableSenderLight::pub_soft_validate(['ps' => '08-22-2025']);
        $ps_warn = array_filter($warnings, fn($w) => str_starts_with($w, 'ps:'));
        $this->assertEmpty($ps_warn);
    }

    public function test_soft_validate_invalid_ps_date(): void
    {
        $warnings = TestableSenderLight::pub_soft_validate(['ps' => '2025-08-22']);
        $ps_warn = array_filter($warnings, fn($w) => str_starts_with($w, 'ps:'));
        $this->assertNotEmpty($ps_warn);
    }

    public function test_soft_validate_empty_payload_no_warnings(): void
    {
        $warnings = TestableSenderLight::pub_soft_validate([]);
        $this->assertEmpty($warnings);
    }

    // =========================================================================
    // make_idempotency_key
    // =========================================================================

    public function test_make_idempotency_key_returns_sha1(): void
    {
        $payload = [
            'phone'                   => '3463502904',
            'origin_postal_code'      => '90210',
            'destination_postal_code' => '10001',
            'ship_date'               => '2025-08-22',
        ];
        $key = TestableSenderLight::pub_make_idempotency_key($payload);
        $expected = sha1('3463502904|90210|10001|2025-08-22');
        $this->assertSame($expected, $key);
    }

    public function test_make_idempotency_key_same_payload_same_key(): void
    {
        $payload = ['phone' => '1234567890', 'origin_postal_code' => '', 'destination_postal_code' => '', 'ship_date' => ''];
        $key1 = TestableSenderLight::pub_make_idempotency_key($payload);
        $key2 = TestableSenderLight::pub_make_idempotency_key($payload);
        $this->assertSame($key1, $key2);
    }

    public function test_make_idempotency_key_different_payload_different_key(): void
    {
        $p1 = ['phone' => '1111111111', 'origin_postal_code' => '10001', 'destination_postal_code' => '90210', 'ship_date' => '2025-01-01'];
        $p2 = ['phone' => '2222222222', 'origin_postal_code' => '10001', 'destination_postal_code' => '90210', 'ship_date' => '2025-01-01'];
        $this->assertNotSame(
            TestableSenderLight::pub_make_idempotency_key($p1),
            TestableSenderLight::pub_make_idempotency_key($p2)
        );
    }

    // =========================================================================
    // mask_headers
    // =========================================================================

    public function test_mask_headers_masks_api_key_header(): void
    {
        $masked = TestableSenderLight::pub_mask_headers(['X-API-Key' => 'secret-key-123']);
        $this->assertSame('***', $masked['X-API-Key']);
    }

    public function test_mask_headers_masks_auth_header(): void
    {
        $masked = TestableSenderLight::pub_mask_headers(['Authorization' => '******']);
        $this->assertSame('***', $masked['Authorization']);
    }

    public function test_mask_headers_keeps_content_type(): void
    {
        $masked = TestableSenderLight::pub_mask_headers(['Content-Type' => 'application/json']);
        $this->assertSame('application/json', $masked['Content-Type']);
    }

    public function test_mask_headers_empty_input(): void
    {
        $masked = TestableSenderLight::pub_mask_headers([]);
        $this->assertSame([], $masked);
    }

    // =========================================================================
    // mask_payload
    // =========================================================================

    public function test_mask_payload_masks_apikey(): void
    {
        $result = TestableSenderLight::pub_mask_payload(['apikey' => 'my-secret', 'name' => 'John']);
        $this->assertSame('***', $result['apikey']);
        $this->assertSame('John', $result['name']);
    }

    public function test_mask_payload_masks_token(): void
    {
        $result = TestableSenderLight::pub_mask_payload(['token' => 'tok123', 'email' => 'a@b.com']);
        $this->assertSame('***', $result['token']);
    }

    public function test_mask_payload_non_array_passthrough(): void
    {
        $this->assertSame('hello', TestableSenderLight::pub_mask_payload('hello'));
    }

    // =========================================================================
    // retry_after_seconds
    // =========================================================================

    public function test_retry_after_numeric(): void
    {
        $result = TestableSenderLight::pub_retry_after_seconds(['Retry-After' => '120']);
        $this->assertSame(120, $result);
    }

    public function test_retry_after_case_insensitive(): void
    {
        $result = TestableSenderLight::pub_retry_after_seconds(['retry-after' => '30']);
        $this->assertSame(30, $result);
    }

    public function test_retry_after_missing_returns_null(): void
    {
        $result = TestableSenderLight::pub_retry_after_seconds(['Content-Type' => 'application/json']);
        $this->assertNull($result);
    }

    public function test_retry_after_empty_array_returns_null(): void
    {
        $result = TestableSenderLight::pub_retry_after_seconds([]);
        $this->assertNull($result);
    }

    public function test_retry_after_empty_value_returns_null(): void
    {
        $result = TestableSenderLight::pub_retry_after_seconds(['Retry-After' => '']);
        $this->assertNull($result);
    }

    // =========================================================================
    // array_to_xml_string
    // =========================================================================

    public function test_xml_contains_root_element(): void
    {
        $xml = TestableSenderLight::pub_array_to_xml_string(['name' => 'John'], 'lead');
        $this->assertStringContainsString('<lead>', $xml);
    }

    public function test_xml_contains_child_element(): void
    {
        $xml = TestableSenderLight::pub_array_to_xml_string(['name' => 'John'], 'lead');
        $this->assertStringContainsString('<name>John</name>', $xml);
    }

    public function test_xml_nested_array(): void
    {
        $xml = TestableSenderLight::pub_array_to_xml_string(['vehicle' => ['year' => 2020]], 'payload');
        $this->assertStringContainsString('<vehicle>', $xml);
        $this->assertStringContainsString('<year>2020</year>', $xml);
    }

    public function test_xml_numeric_keys_become_item(): void
    {
        $xml = TestableSenderLight::pub_array_to_xml_string([[0 => 'a']], 'root');
        $this->assertStringContainsString('<item>', $xml);
    }

    public function test_xml_empty_array(): void
    {
        $xml = TestableSenderLight::pub_array_to_xml_string([], 'root');
        $this->assertStringContainsString('<root', $xml);
    }

    // =========================================================================
    // backoff_us
    // =========================================================================

    public function test_backoff_attempt_1(): void
    {
        $result = TestableSenderLight::pub_backoff_us(1);
        $this->assertSame(300000, $result); // 300ms in µs
    }

    public function test_backoff_attempt_2_is_greater(): void
    {
        $r1 = TestableSenderLight::pub_backoff_us(1);
        $r2 = TestableSenderLight::pub_backoff_us(2);
        $this->assertGreaterThan($r1, $r2);
    }

    // =========================================================================
    // gen_uuid_v4
    // =========================================================================

    public function test_gen_uuid_v4_format(): void
    {
        $uuid = TestableSenderLight::pub_gen_uuid_v4();
        $this->assertRegExp(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }

    public function test_gen_uuid_v4_unique(): void
    {
        $u1 = TestableSenderLight::pub_gen_uuid_v4();
        $u2 = TestableSenderLight::pub_gen_uuid_v4();
        $this->assertNotSame($u1, $u2);
    }

    // =========================================================================
    // dot_flatten_local
    // =========================================================================

    public function test_dot_flatten_flat_array(): void
    {
        $result = TestableSenderLight::pub_dot_flatten_local(['a' => 1, 'b' => 2]);
        $this->assertSame(['a' => 1, 'b' => 2], $result);
    }

    public function test_dot_flatten_nested_array(): void
    {
        $result = TestableSenderLight::pub_dot_flatten_local(['outer' => ['inner' => 'value']]);
        $this->assertArrayHasKey('outer.inner', $result);
        $this->assertSame('value', $result['outer.inner']);
    }

    public function test_dot_flatten_deep_nesting(): void
    {
        $result = TestableSenderLight::pub_dot_flatten_local(['a' => ['b' => ['c' => 'deep']]]);
        $this->assertSame('deep', $result['a.b.c']);
    }
}
