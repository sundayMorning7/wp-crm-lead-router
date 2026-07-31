<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for leadrouter_normalize_phone() and leadrouter_normalize_date().
 */
class NormalizeFunctionsTest extends TestCase
{
    // =========================================================================
    // leadrouter_normalize_phone
    // =========================================================================

    public function test_phone_standard_10_digits(): void
    {
        $this->assertSame('3463502904', leadrouter_normalize_phone('3463502904'));
    }

    public function test_phone_formatted_us(): void
    {
        $this->assertSame('3463502904', leadrouter_normalize_phone('+1 (346) 350-2904'));
    }

    public function test_phone_dashes(): void
    {
        $this->assertSame('3463502904', leadrouter_normalize_phone('346-350-2904'));
    }

    public function test_phone_11_digits_strips_last_10(): void
    {
        // 11 digits: last 10 are taken
        $this->assertSame('2345678901', leadrouter_normalize_phone('12345678901'));
    }

    public function test_phone_too_short_non_strict_returns_warning_array(): void
    {
        $result = leadrouter_normalize_phone('12345');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('warning', $result);
        $this->assertSame('phone_unparsed', $result['warning']);
    }

    public function test_phone_empty_non_strict_returns_warning_array(): void
    {
        $result = leadrouter_normalize_phone('');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('warning', $result);
    }

    public function test_phone_empty_strict_returns_wp_error(): void
    {
        $result = leadrouter_normalize_phone('', ['strict' => true]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_phone', $result->get_error_code());
    }

    public function test_phone_too_short_strict_returns_wp_error(): void
    {
        $result = leadrouter_normalize_phone('12345', ['strict' => true]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_phone', $result->get_error_code());
    }

    public function test_phone_null_treated_as_empty_non_strict(): void
    {
        $result = leadrouter_normalize_phone(null);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('warning', $result);
    }

    public function test_phone_parentheses_dots(): void
    {
        $this->assertSame('5552223333', leadrouter_normalize_phone('555.222.3333'));
    }

    // =========================================================================
    // leadrouter_normalize_date
    // =========================================================================

    public function test_date_mdy_slash(): void
    {
        $this->assertSame('2025-08-22', leadrouter_normalize_date('08/22/2025'));
    }

    public function test_date_mdy_dash(): void
    {
        $this->assertSame('2025-08-22', leadrouter_normalize_date('08-22-2025'));
    }

    public function test_date_ymd_standard(): void
    {
        $this->assertSame('2025-01-15', leadrouter_normalize_date('2025-01-15'));
    }

    public function test_date_spaces_between_parts(): void
    {
        $result = leadrouter_normalize_date('01 5 2025');
        $this->assertSame('2025-01-05', $result);
    }

    public function test_date_two_digit_year(): void
    {
        $result = leadrouter_normalize_date('08/22/25');
        $this->assertSame('2025-08-22', $result);
    }

    public function test_date_swaps_month_day_when_month_gt_12(): void
    {
        // 22/08/2025 → month=22 is invalid, so swap → 2025-08-22
        $result = leadrouter_normalize_date('22/08/2025');
        $this->assertSame('2025-08-22', $result);
    }

    public function test_date_invalid_calendar_non_strict(): void
    {
        $result = leadrouter_normalize_date('13/32/2025');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('warning', $result);
    }

    public function test_date_empty_non_strict(): void
    {
        $result = leadrouter_normalize_date('');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('warning', $result);
    }

    public function test_date_empty_strict(): void
    {
        $result = leadrouter_normalize_date('', ['strict' => true]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_date', $result->get_error_code());
    }

    public function test_date_invalid_strict(): void
    {
        $result = leadrouter_normalize_date('not-a-date-at-all', ['strict' => true]);
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function test_date_null_non_strict(): void
    {
        $result = leadrouter_normalize_date(null);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('warning', $result);
    }

    public function test_date_returns_padded_format(): void
    {
        $result = leadrouter_normalize_date('1/5/2025');
        $this->assertSame('2025-01-05', $result);
    }
}
