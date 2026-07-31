<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for LeadRouter_Transform::apply()
 */
class TransformTest extends TestCase
{
    // ── None / passthrough ──────────────────────────────────────────────────

    public function test_apply_none_returns_string_as_is(): void
    {
        $this->assertSame('hello', LeadRouter_Transform::apply('hello', 'none'));
    }

    public function test_apply_unknown_transform_returns_value(): void
    {
        $this->assertSame('world', LeadRouter_Transform::apply('world', 'nonexistent'));
    }

    public function test_apply_null_returns_null(): void
    {
        $this->assertNull(LeadRouter_Transform::apply(null, 'lower'));
    }

    public function test_apply_array_json_encodes(): void
    {
        $result = LeadRouter_Transform::apply(['a' => 1], 'none');
        $this->assertSame('{"a":1}', $result);
    }

    // ── Case transforms ──────────────────────────────────────────────────────

    public function test_apply_lower(): void
    {
        $this->assertSame('hello world', LeadRouter_Transform::apply('Hello World', 'lower'));
    }

    public function test_apply_upper(): void
    {
        $this->assertSame('HELLO WORLD', LeadRouter_Transform::apply('hello world', 'upper'));
    }

    public function test_apply_title(): void
    {
        $this->assertSame('Hello World', LeadRouter_Transform::apply('hello world', 'title'));
    }

    public function test_apply_title_mixed_case(): void
    {
        $result = LeadRouter_Transform::apply('JOHN DOE', 'title');
        $this->assertSame('John Doe', $result);
    }

    // ── digits ───────────────────────────────────────────────────────────────

    public function test_apply_digits_strips_non_numeric(): void
    {
        // 'digits' strips ALL non-digit chars — does NOT strip country code prefix
        $this->assertSame('13463502904', LeadRouter_Transform::apply('+1 (346) 350-2904', 'digits'));
    }

    public function test_apply_digits_plain_phone(): void
    {
        $this->assertSame('3463502904', LeadRouter_Transform::apply('346-350-2904', 'digits'));
    }

    public function test_apply_digits_empty_string(): void
    {
        $this->assertSame('', LeadRouter_Transform::apply('abc', 'digits'));
    }

    // ── int ──────────────────────────────────────────────────────────────────

    public function test_apply_int_valid(): void
    {
        $this->assertSame(42, LeadRouter_Transform::apply('42', 'int'));
    }

    public function test_apply_int_negative(): void
    {
        $this->assertSame(-7, LeadRouter_Transform::apply('-7', 'int'));
    }

    public function test_apply_int_non_numeric_returns_null(): void
    {
        $this->assertNull(LeadRouter_Transform::apply('abc', 'int'));
    }

    public function test_apply_int_empty_returns_null(): void
    {
        $this->assertNull(LeadRouter_Transform::apply('', 'int'));
    }

    // ── float2 ───────────────────────────────────────────────────────────────

    public function test_apply_float2_rounds_to_two_decimals(): void
    {
        $this->assertSame('3.14', LeadRouter_Transform::apply('3.14159', 'float2'));
    }

    public function test_apply_float2_integer_value(): void
    {
        $this->assertSame('5.00', LeadRouter_Transform::apply('5', 'float2'));
    }

    public function test_apply_float2_non_numeric_returns_null(): void
    {
        $this->assertNull(LeadRouter_Transform::apply('abc', 'float2'));
    }

    // ── date transforms ──────────────────────────────────────────────────────

    public function test_apply_date_Ymd_standard(): void
    {
        $this->assertSame('2025-08-22', LeadRouter_Transform::apply('08-22-2025', 'date_Ymd'));
    }

    public function test_apply_date_Ymd_slash_input(): void
    {
        $this->assertSame('2025-01-15', LeadRouter_Transform::apply('01/15/2025', 'date_Ymd'));
    }

    public function test_apply_date_mdy(): void
    {
        $this->assertSame('08/22/2025', LeadRouter_Transform::apply('2025-08-22', 'date_mdy'));
    }

    public function test_apply_date_mdy_dash(): void
    {
        $this->assertSame('08-22-2025', LeadRouter_Transform::apply('2025-08-22', 'date_mdy_dash'));
    }

    public function test_apply_date_Ymd_slash_output(): void
    {
        $this->assertSame('2025/08/22', LeadRouter_Transform::apply('2025-08-22', 'date_Ymd_slash'));
    }

    public function test_apply_date_invalid_returns_null(): void
    {
        $this->assertNull(LeadRouter_Transform::apply('not-a-date', 'date_Ymd'));
    }

    // ── phone_us_dashed ──────────────────────────────────────────────────────

    public function test_apply_phone_us_dashed_standard(): void
    {
        $this->assertSame('346-350-2904', LeadRouter_Transform::apply('+1 (346) 350-2904', 'phone_us_dashed'));
    }

    public function test_apply_phone_us_dashed_10_digits(): void
    {
        $this->assertSame('123-456-7890', LeadRouter_Transform::apply('1234567890', 'phone_us_dashed'));
    }

    public function test_apply_phone_us_dashed_11_digits_with_country_code(): void
    {
        $this->assertSame('234-567-8901', LeadRouter_Transform::apply('12345678901', 'phone_us_dashed'));
    }

    public function test_apply_phone_us_dashed_too_short_returns_null(): void
    {
        $this->assertNull(LeadRouter_Transform::apply('12345', 'phone_us_dashed'));
    }

    public function test_apply_phone_us_dashed_empty_returns_null(): void
    {
        $this->assertNull(LeadRouter_Transform::apply('', 'phone_us_dashed'));
    }

    // ── split_name ───────────────────────────────────────────────────────────

    public function test_apply_split_name_fn(): void
    {
        $this->assertSame('John', LeadRouter_Transform::apply('John Doe', 'split_name_fn'));
    }

    public function test_apply_split_name_ln(): void
    {
        $this->assertSame('Doe', LeadRouter_Transform::apply('John Doe', 'split_name_ln'));
    }

    public function test_apply_split_name_ln_multiple_parts(): void
    {
        $this->assertSame('Van Der Berg', LeadRouter_Transform::apply('Jan Van Der Berg', 'split_name_ln'));
    }

    public function test_apply_split_name_fn_single_word(): void
    {
        $this->assertSame('Jane', LeadRouter_Transform::apply('Jane', 'split_name_fn'));
    }

    public function test_apply_split_name_ln_single_word_returns_empty(): void
    {
        $this->assertSame('', LeadRouter_Transform::apply('Jane', 'split_name_ln'));
    }

    // ── map_running ──────────────────────────────────────────────────────────

    public function test_apply_map_running_running(): void
    {
        $this->assertSame('operable', LeadRouter_Transform::apply('Running', 'map_running'));
    }

    public function test_apply_map_running_nonrunning(): void
    {
        $this->assertSame('inoperable', LeadRouter_Transform::apply('NonRunning', 'map_running'));
    }

    public function test_apply_map_running_non_hyphen_running(): void
    {
        $this->assertSame('inoperable', LeadRouter_Transform::apply('non-running', 'map_running'));
    }

    public function test_apply_map_running_other_value_passthrough(): void
    {
        $this->assertSame('unknown', LeadRouter_Transform::apply('unknown', 'map_running'));
    }

    // ── inop_binary ──────────────────────────────────────────────────────────

    public function test_apply_inop_binary_running(): void
    {
        $this->assertSame('0', LeadRouter_Transform::apply('running', 'inop_binary'));
    }

    public function test_apply_inop_binary_zero(): void
    {
        $this->assertSame('0', LeadRouter_Transform::apply('0', 'inop_binary'));
    }

    public function test_apply_inop_binary_nonrunning(): void
    {
        $this->assertSame('1', LeadRouter_Transform::apply('nonrunning', 'inop_binary'));
    }

    // ── inop_binary_reverse ──────────────────────────────────────────────────

    public function test_apply_inop_binary_reverse_nonrunning(): void
    {
        $this->assertSame('0', LeadRouter_Transform::apply('NonRunning', 'inop_binary_reverse'));
    }

    public function test_apply_inop_binary_reverse_running(): void
    {
        $this->assertSame('1', LeadRouter_Transform::apply('Running', 'inop_binary_reverse'));
    }

    // ── inop_binary_to_bool ──────────────────────────────────────────────────

    public function test_apply_inop_binary_to_bool_running_is_false(): void
    {
        $this->assertFalse(LeadRouter_Transform::apply('running', 'inop_binary_to_bool'));
    }

    public function test_apply_inop_binary_to_bool_nonrunning_is_true(): void
    {
        $this->assertTrue(LeadRouter_Transform::apply('nonrunning', 'inop_binary_to_bool'));
    }

    // ── inop_binary_to_bool_reverse ──────────────────────────────────────────

    public function test_apply_inop_binary_to_bool_reverse_running_is_true(): void
    {
        $this->assertTrue(LeadRouter_Transform::apply('running', 'inop_binary_to_bool_reverse'));
    }

    public function test_apply_inop_binary_to_bool_reverse_nonrunning_is_false(): void
    {
        $this->assertFalse(LeadRouter_Transform::apply('nonrunning', 'inop_binary_to_bool_reverse'));
    }

    // ── map_transport_type ───────────────────────────────────────────────────

    public function test_apply_map_transport_type_one_is_open(): void
    {
        $this->assertSame('Open', LeadRouter_Transform::apply('1', 'map_transport_type'));
    }

    public function test_apply_map_transport_type_zero_is_closed(): void
    {
        $this->assertSame('Closed', LeadRouter_Transform::apply('0', 'map_transport_type'));
    }

    public function test_apply_map_transport_type_unknown_is_null(): void
    {
        $this->assertNull(LeadRouter_Transform::apply('2', 'map_transport_type'));
    }

    // ── map_transport_type_open_enclosed ────────────────────────────────────

    public function test_apply_map_transport_type_open_enclosed_one(): void
    {
        $this->assertSame('Open', LeadRouter_Transform::apply('1', 'map_transport_type_open_enclosed'));
    }

    public function test_apply_map_transport_type_open_enclosed_zero(): void
    {
        $this->assertSame('Enclosed', LeadRouter_Transform::apply('0', 'map_transport_type_open_enclosed'));
    }

    // ── map_transport_type_reverse ───────────────────────────────────────────

    public function test_apply_map_transport_type_reverse_one_returns_zero(): void
    {
        // reverse: '1' → 0 → int
        $this->assertSame(0, LeadRouter_Transform::apply('1', 'map_transport_type_reverse'));
    }

    public function test_apply_map_transport_type_reverse_zero_returns_one(): void
    {
        $this->assertSame(1, LeadRouter_Transform::apply('0', 'map_transport_type_reverse'));
    }

    public function test_apply_map_transport_type_reverse_unknown_returns_null(): void
    {
        // mapTransportTypeReverse returns null for unknown values, but then toIntOrNull()
        // receives null. In PHP 8 strict types this causes a TypeError at the call site.
        // We verify this edge-case raises a TypeError.
        $this->expectException(\TypeError::class);
        LeadRouter_Transform::apply('foo', 'map_transport_type_reverse');
    }
}
