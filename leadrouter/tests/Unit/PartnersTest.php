<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for LeadRouter_Partners internal helpers.
 * The class is designed to work with get_post_meta() which is stubbed to return
 * configurable values via a thread-local state object.
 */

/**
 * Exposes protected static methods for testing.
 */
class TestablePartners extends LeadRouter_Partners
{
    /** is_open_now_per_day is private — expose via reflection. */
    public static function pub_is_open_now_per_day(int $partner_id, DateTimeInterface $now, int $dow): bool
    {
        $ref = new ReflectionMethod(LeadRouter_Partners::class, 'is_open_now_per_day');
        $ref->setAccessible(true);
        return $ref->invoke(null, $partner_id, $now, $dow);
    }

    public static function pub_state_allowed(int $partner_id, string $from, string $to): bool
    {
        $ref = new ReflectionMethod(LeadRouter_Partners::class, 'state_allowed');
        $ref->setAccessible(true);
        return $ref->invoke(null, $partner_id, $from, $to);
    }

    public static function pub_today_window_mysql_est(DateTimeInterface $now): array
    {
        $ref = new ReflectionMethod(LeadRouter_Partners::class, 'today_window_mysql_est');
        $ref->setAccessible(true);
        return $ref->invoke(null, $now);
    }

    public static function pub_limit_for_day(int $partner_id, int $dow): int
    {
        $ref = new ReflectionMethod(LeadRouter_Partners::class, 'limit_for_day');
        $ref->setAccessible(true);
        return $ref->invoke(null, $partner_id, $dow);
    }

    public static function pub_day_slug(int $dow): string
    {
        $ref = new ReflectionMethod(LeadRouter_Partners::class, 'day_slug');
        $ref->setAccessible(true);
        return $ref->invoke(null, $dow);
    }
}

// ---------------------------------------------------------------------------
// Helpers to intercept get_post_meta() calls
// ---------------------------------------------------------------------------

/** Initialize the global meta store for tests. */
$GLOBALS['__lr_test_post_meta'] = [];

function lr_test_set_meta(int $post_id, string $key, $value): void
{
    $GLOBALS['__lr_test_post_meta'][$post_id . '|' . $key] = $value;
}

function lr_test_clear_meta(): void
{
    $GLOBALS['__lr_test_post_meta'] = [];
}

class PartnersTest extends TestCase
{
    protected function setUp(): void
    {
        lr_test_clear_meta();
    }

    // =========================================================================
    // day_slug
    // =========================================================================

    /** @dataProvider dowSlugProvider */
    public function test_day_slug(int $dow, string $expected): void
    {
        $this->assertSame($expected, TestablePartners::pub_day_slug($dow));
    }

    public static function dowSlugProvider(): array
    {
        return [
            [1, 'mon'],
            [2, 'tue'],
            [3, 'wed'],
            [4, 'thu'],
            [5, 'fri'],
            [6, 'sat'],
            [7, 'sun'],
        ];
    }

    // =========================================================================
    // today_window_mysql_est
    // =========================================================================

    public function test_today_window_returns_two_elements(): void
    {
        $tz  = new DateTimeZone('America/New_York');
        $now = new DateTimeImmutable('2025-08-22 14:30:00', $tz);
        $win = TestablePartners::pub_today_window_mysql_est($now);
        $this->assertCount(2, $win);
    }

    public function test_today_window_start_is_midnight(): void
    {
        $tz  = new DateTimeZone('America/New_York');
        $now = new DateTimeImmutable('2025-08-22 14:30:00', $tz);
        [$start] = TestablePartners::pub_today_window_mysql_est($now);
        $this->assertSame('2025-08-22 00:00:00', $start);
    }

    public function test_today_window_end_is_end_of_day(): void
    {
        $tz  = new DateTimeZone('America/New_York');
        $now = new DateTimeImmutable('2025-08-22 14:30:00', $tz);
        [, $end] = TestablePartners::pub_today_window_mysql_est($now);
        $this->assertSame('2025-08-22 23:59:59', $end);
    }

    // =========================================================================
    // is_open_now_per_day (via get_post_meta stub)
    // =========================================================================

    /**
     * We need get_post_meta to return our controlled values.
     * The bootstrap stub reads from $GLOBALS['__lr_test_post_meta'].
     */
    private function setMeta(int $post_id, string $key, $value): void
    {
        $GLOBALS['__lr_test_post_meta'][$post_id . '|' . $key] = $value;
    }

    public function test_is_open_no_hours_configured_is_always_open(): void
    {
        // No meta set → get_post_meta returns '' → treated as "always open"
        $tz  = new DateTimeZone('America/New_York');
        $now = new DateTimeImmutable('2025-08-22 10:00:00', $tz);
        $dow = (int)$now->format('N'); // Friday = 5

        $result = TestablePartners::pub_is_open_now_per_day(999, $now, $dow);
        $this->assertTrue($result);
    }

    public function test_is_open_within_hours(): void
    {
        $partnerId = 101;
        $this->setMeta($partnerId, '_leadrouter_partner_fri_start', '08:00');
        $this->setMeta($partnerId, '_leadrouter_partner_fri_end',   '18:00');

        $tz  = new DateTimeZone('America/New_York');
        $now = new DateTimeImmutable('2025-08-22 12:00:00', $tz); // Fri 12:00 EST

        $result = TestablePartners::pub_is_open_now_per_day($partnerId, $now, 5);
        $this->assertTrue($result);
    }

    public function test_is_open_before_hours_is_closed(): void
    {
        $partnerId = 102;
        $this->setMeta($partnerId, '_leadrouter_partner_fri_start', '09:00');
        $this->setMeta($partnerId, '_leadrouter_partner_fri_end',   '17:00');

        $tz  = new DateTimeZone('America/New_York');
        $now = new DateTimeImmutable('2025-08-22 07:30:00', $tz);

        $result = TestablePartners::pub_is_open_now_per_day($partnerId, $now, 5);
        $this->assertFalse($result);
    }

    public function test_is_open_after_hours_is_closed(): void
    {
        $partnerId = 103;
        $this->setMeta($partnerId, '_leadrouter_partner_fri_start', '09:00');
        $this->setMeta($partnerId, '_leadrouter_partner_fri_end',   '17:00');

        $tz  = new DateTimeZone('America/New_York');
        $now = new DateTimeImmutable('2025-08-22 17:01:00', $tz);

        $result = TestablePartners::pub_is_open_now_per_day($partnerId, $now, 5);
        $this->assertFalse($result);
    }

    public function test_is_open_overnight_window_before_midnight(): void
    {
        // 22:00 – 06:00 overnight; now = 23:00 → open
        $partnerId = 104;
        $this->setMeta($partnerId, '_leadrouter_partner_fri_start', '22:00');
        $this->setMeta($partnerId, '_leadrouter_partner_fri_end',   '06:00');

        $tz  = new DateTimeZone('America/New_York');
        $now = new DateTimeImmutable('2025-08-22 23:00:00', $tz);

        $result = TestablePartners::pub_is_open_now_per_day($partnerId, $now, 5);
        $this->assertTrue($result);
    }

    public function test_is_open_overnight_window_after_midnight(): void
    {
        // 22:00 – 06:00 overnight; now = 02:00 the next day → open
        $partnerId = 105;
        $this->setMeta($partnerId, '_leadrouter_partner_sat_start', '22:00');
        $this->setMeta($partnerId, '_leadrouter_partner_sat_end',   '06:00');

        $tz  = new DateTimeZone('America/New_York');
        $now = new DateTimeImmutable('2025-08-23 02:00:00', $tz); // Saturday

        $result = TestablePartners::pub_is_open_now_per_day($partnerId, $now, 6);
        $this->assertTrue($result);
    }

    public function test_is_open_overnight_window_outside_range(): void
    {
        // 22:00 – 06:00 overnight; now = 10:00 → closed
        $partnerId = 106;
        $this->setMeta($partnerId, '_leadrouter_partner_fri_start', '22:00');
        $this->setMeta($partnerId, '_leadrouter_partner_fri_end',   '06:00');

        $tz  = new DateTimeZone('America/New_York');
        $now = new DateTimeImmutable('2025-08-22 10:00:00', $tz);

        $result = TestablePartners::pub_is_open_now_per_day($partnerId, $now, 5);
        $this->assertFalse($result);
    }

    // =========================================================================
    // state_allowed
    // =========================================================================

    public function test_state_allowed_no_ak_hi_always_true(): void
    {
        $result = TestablePartners::pub_state_allowed(200, 'CA', 'TX');
        $this->assertTrue($result);
    }

    public function test_state_allowed_empty_states_always_true(): void
    {
        $result = TestablePartners::pub_state_allowed(200, '', '');
        $this->assertTrue($result);
    }

    public function test_state_allowed_ak_lead_partner_without_ak_flag_false(): void
    {
        // get_post_meta returns '' for leadrouter_partner_allow_alaska → not '1' → denied
        $result = TestablePartners::pub_state_allowed(200, 'AK', 'CA');
        $this->assertFalse($result);
    }

    public function test_state_allowed_ak_lead_partner_with_ak_flag_true(): void
    {
        $partnerId = 201;
        $this->setMeta($partnerId, 'leadrouter_partner_allow_alaska', '1');

        $result = TestablePartners::pub_state_allowed($partnerId, 'AK', 'CA');
        $this->assertTrue($result);
    }

    public function test_state_allowed_hi_lead_partner_without_hi_flag_false(): void
    {
        $result = TestablePartners::pub_state_allowed(202, 'CA', 'HI');
        $this->assertFalse($result);
    }

    public function test_state_allowed_hi_lead_partner_with_hi_flag_true(): void
    {
        $partnerId = 203;
        $this->setMeta($partnerId, 'leadrouter_partner_allow_hawaii', '1');

        $result = TestablePartners::pub_state_allowed($partnerId, 'TX', 'HI');
        $this->assertTrue($result);
    }

    public function test_state_allowed_both_ak_and_hi_need_both_flags(): void
    {
        $partnerId = 204;
        $this->setMeta($partnerId, 'leadrouter_partner_allow_alaska', '1');
        // HI flag not set

        // Both from/to are excluded states → need both flags
        $result = TestablePartners::pub_state_allowed($partnerId, 'AK', 'HI');
        $this->assertFalse($result);
    }

    public function test_state_allowed_both_ak_and_hi_with_both_flags(): void
    {
        $partnerId = 205;
        $this->setMeta($partnerId, 'leadrouter_partner_allow_alaska', '1');
        $this->setMeta($partnerId, 'leadrouter_partner_allow_hawaii', '1');

        $result = TestablePartners::pub_state_allowed($partnerId, 'AK', 'HI');
        $this->assertTrue($result);
    }

    // =========================================================================
    // limit_for_day
    // =========================================================================

    public function test_limit_for_day_no_meta_returns_zero(): void
    {
        // get_post_meta returns '' → limit_for_day returns 0
        $result = TestablePartners::pub_limit_for_day(300, 5); // Friday
        $this->assertSame(0, $result);
    }

    public function test_limit_for_day_returns_configured_limit(): void
    {
        $partnerId = 301;
        $this->setMeta($partnerId, '_leadrouter_partner_mon_limit', '10');

        $result = TestablePartners::pub_limit_for_day($partnerId, 1); // Monday
        $this->assertSame(10, $result);
    }

    public function test_limit_for_day_negative_value_clamped_to_zero(): void
    {
        $partnerId = 302;
        $this->setMeta($partnerId, '_leadrouter_partner_tue_limit', '-5');

        $result = TestablePartners::pub_limit_for_day($partnerId, 2);
        $this->assertSame(0, $result);
    }
}
