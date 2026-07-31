<?php

use PHPUnit\Framework\TestCase;

class TestableFlowIntegration extends LeadRouter_Flow
{
    public static function pub_send_with_retries(int $lead_id, array $partner_row, string $dispatch_method)
    {
        return parent::send_with_retries($lead_id, $partner_row, $dispatch_method);
    }
}

class SenderFlowIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__lr_test_post_meta'],
            $GLOBALS['__lr_test_carbon_meta'],
            $GLOBALS['__lr_test_wp_remote_request']
        );
    }

    private function setupPartnerConfig(int $partnerId): void
    {
        $GLOBALS['__lr_test_post_meta'][$partnerId . '|_leadrouter_partner_type'] = 'standard';
        $GLOBALS['__lr_test_carbon_meta'][$partnerId . '|leadrouter_partner_endpoint'] = 'https://example.test/lead';
        $GLOBALS['__lr_test_carbon_meta'][$partnerId . '|leadrouter_partner_auth_variant'] = 'none';
        $GLOBALS['__lr_test_carbon_meta'][$partnerId . '|leadrouter_partner_api_key'] = '';
        $GLOBALS['__lr_test_carbon_meta'][$partnerId . '|leadrouter_partner_api_key_header'] = 'X-API-Key';
        $GLOBALS['__lr_test_carbon_meta'][$partnerId . '|leadrouter_partner_map'] = [];
        $GLOBALS['__lr_test_carbon_meta'][$partnerId . '|leadrouter_partner_http_method'] = 'POST';
        $GLOBALS['__lr_test_carbon_meta'][$partnerId . '|leadrouter_partner_body_type'] = 'json';
        $GLOBALS['__lr_test_carbon_meta'][$partnerId . '|leadrouter_partner_require_ok_json'] = '';
    }

    private function setupWpdbLeadRow(int $leadId): void
    {
        $GLOBALS['wpdb'] = new class($leadId) {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public int $insert_id = 0;
            private int $leadId;

            public function __construct(int $leadId)
            {
                $this->leadId = $leadId;
            }

            public function prepare(string $sql, ...$args): string
            {
                $i = 0;
                return preg_replace_callback('/%[dsf]/', function () use ($args, &$i) {
                    return $args[$i++] ?? '?';
                }, $sql);
            }

            public function get_row($sql, $out = OBJECT)
            {
                if (strpos((string)$sql, 'FROM wp_leadrouter_leads') !== false) {
                    return [
                        'id' => $this->leadId,
                        'name' => 'Test User',
                        'email' => 'test@example.com',
                        'phone' => '123-456-7890',
                        'est_ship_date' => '2026-08-01',
                        'vehicle_bodytype' => 'SUV',
                        'vehicle_year' => 2020,
                        'vehicle_brand' => 'Toyota',
                        'vehicle_model' => 'RAV4',
                        'vehicle_condition' => 'Running',
                        'from_city' => 'Austin',
                        'from_state' => 'TX',
                        'from_zip' => '73301',
                        'to_city' => 'Miami',
                        'to_state' => 'FL',
                        'to_zip' => '33101',
                        'utm_source' => 'tests',
                    ];
                }
                return null;
            }

            public function insert(string $table, array $data, $format = null): ?int
            {
                $this->insert_id++;
                return 1;
            }

            public function update(string $table, array $data, array $where, $format = null, $where_format = null)
            {
                return 1;
            }

            public function get_var($sql) { return null; }
            public function get_results($sql, $out = OBJECT) { return []; }
            public function delete(string $table, array $where, $where_format = null) { return 1; }
            public function query(string $sql) { return 1; }
        };
    }

    public function test_flow_retries_retryable_http_and_returns_success(): void
    {
        $leadId = 9101;
        $partnerId = 301;
        $this->setupPartnerConfig($partnerId);
        $this->setupWpdbLeadRow($leadId);

        $calls = 0;
        $GLOBALS['__lr_test_wp_remote_request'] = static function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return [
                    'response' => ['code' => 500],
                    'body' => '{"error":"temporary"}',
                    'headers' => [],
                ];
            }

            return [
                'response' => ['code' => 200],
                'body' => '{"id":"ext-777"}',
                'headers' => [],
            ];
        };

        $result = TestableFlowIntegration::pub_send_with_retries($leadId, [
            'partner_id' => $partnerId,
            'group_post_id' => 22,
        ], 'auto_cron_new_lead');

        $this->assertIsArray($result);
        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['attempts']);
        $this->assertSame(200, $result['status_code']);
        $this->assertSame(2, $calls);
    }

    public function test_flow_does_not_retry_hard_http_failure(): void
    {
        $leadId = 9102;
        $partnerId = 302;
        $this->setupPartnerConfig($partnerId);
        $this->setupWpdbLeadRow($leadId);

        $calls = 0;
        $GLOBALS['__lr_test_wp_remote_request'] = static function () use (&$calls) {
            $calls++;
            return [
                'response' => ['code' => 404],
                'body' => '{"error":"not found"}',
                'headers' => [],
            ];
        };

        $result = TestableFlowIntegration::pub_send_with_retries($leadId, [
            'partner_id' => $partnerId,
            'group_post_id' => 22,
        ], 'auto_cron_new_lead');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('http_404', $result->get_error_code());
        $this->assertSame(1, $calls);
    }
}

