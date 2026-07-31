<?php

use PHPUnit\Framework\TestCase;

class TestableFlowContract extends LeadRouter_Flow
{
    public static function pub_build_summary_from_result(array $result, bool $is_ak_hi = false): array
    {
        $ref = new ReflectionMethod(LeadRouter_Flow::class, 'build_summary_from_result');
        $ref->setAccessible(true);
        return $ref->invoke(null, $result, $is_ak_hi);
    }

    public static function pub_filter_partner(array $p): ?string
    {
        return parent::filter_partner($p);
    }
}

class SenderFlowContractTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['__lr_test_post_meta']);
    }

    public function test_summary_marks_sent_when_any_delivery_succeeds(): void
    {
        $summary = TestableFlowContract::pub_build_summary_from_result([
            'lead_id' => 100,
            'group_post_id' => 7,
            'sent' => [['partner_id' => 10, 'status' => 'sent']],
            'failed' => [['partner_id' => 11, 'status' => 'failed']],
        ]);

        $this->assertSame('sent', $summary['lead_status']);
    }

    public function test_summary_marks_await_for_queue_quota_skips_only(): void
    {
        $summary = TestableFlowContract::pub_build_summary_from_result([
            'lead_id' => 100,
            'group_post_id' => 7,
            'sent' => [],
            'failed' => [
                ['partner_id' => 11, 'status' => 'skipped', 'error' => 'partner_closed'],
                ['partner_id' => 12, 'status' => 'skipped', 'error' => 'limit_exceeded'],
            ],
        ]);

        $this->assertSame('await', $summary['lead_status']);
    }

    public function test_summary_marks_error_when_only_real_failures_present(): void
    {
        $summary = TestableFlowContract::pub_build_summary_from_result([
            'lead_id' => 100,
            'group_post_id' => 7,
            'sent' => [],
            'failed' => [['partner_id' => 11, 'status' => 'failed']],
        ]);

        $this->assertSame('error', $summary['lead_status']);
    }

    public function test_filter_partner_blocks_excluded_state_without_allowance(): void
    {
        $GLOBALS['__lr_test_post_meta']['501|_leadrouter_partner_active'] = '1';
        $GLOBALS['__lr_test_post_meta']['501|_leadrouter_partner_allow_alaska'] = '0';
        $GLOBALS['__lr_test_post_meta']['501|_leadrouter_partner_allow_hawaii'] = '0';

        $reason = TestableFlowContract::pub_filter_partner([
            'partner_id' => 501,
            'lead_from_state' => 'AK',
            'lead_to_state' => 'CA',
        ]);

        $this->assertSame('state_filter_fail', $reason);
    }

    public function test_filter_partner_allows_excluded_state_when_flag_enabled(): void
    {
        $GLOBALS['__lr_test_post_meta']['502|_leadrouter_partner_active'] = '1';
        $GLOBALS['__lr_test_post_meta']['502|_leadrouter_partner_allow_alaska'] = '1';
        $GLOBALS['__lr_test_post_meta']['502|_leadrouter_partner_allow_hawaii'] = '1';

        $reason = TestableFlowContract::pub_filter_partner([
            'partner_id' => 502,
            'lead_from_state' => 'AK',
            'lead_to_state' => 'HI',
        ]);

        $this->assertNull($reason);
    }
}

