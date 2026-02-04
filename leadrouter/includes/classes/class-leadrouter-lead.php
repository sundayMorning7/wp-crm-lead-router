<?php
/**
 * LeadRouter_Leads — CRUD + масові дії з ТРАНЗАКЦІЯМИ (InnoDB).
 * Транзакція на КОЖЕН лід: UPDATE у leads + INSERT у logs/partner_logs виконуються атомарно.
 */
class LeadRouter_Leads {
    /** @var wpdb */
    private static $db;

    private static function boot() {
        global $wpdb;
        self::$db = $wpdb;
    }

    private static function t_leads()  { return self::$db->prefix . 'leadrouter_leads'; }
    private static function t_logs()   { return self::$db->prefix . 'leadrouter_logs'; }
    private static function t_plogs()  { return self::$db->prefix . 'leadrouter_partner_logs'; }

    /** ===== Helpers (EST) ===== */
    private static function tz(): DateTimeZone { return new DateTimeZone('America/New_York'); }
    private static function now_est(): DateTimeImmutable { return new DateTimeImmutable('now', self::tz()); }
    private static function now_str(): string { return self::now_est()->format('Y-m-d H:i:s'); }

    /** ===== Транзакції ===== */
    private static function tx_begin(): void { self::$db->query('START TRANSACTION'); }
    private static function tx_commit(): void { self::$db->query('COMMIT'); }
    private static function tx_rollback(): void { self::$db->query('ROLLBACK'); }


    public static function create(array $data) {
        self::boot();
        $table = self::t_leads();

        $row = wp_parse_args($data, [
            'name'              => 'Lead',
            'email'             => null,
            'phone'             => null,
            'est_ship_date'     => null,
            'vehicle_year'      => null,
            'vehicle_brand'     => null,
            'vehicle_model'     => null,
            'vehicle_condition' => null,
            'from_city'         => null,
            'from_state'        => null,
            'from_zip'          => null,
            'to_city'           => null,
            'to_state'          => null,
            'to_zip'            => null,
            'created_at'        => self::now_str(),  // EST
            'sent_at'           => null,
            'dispatch_method'   => 'manual',
            'crm_response_json' => null,
            'response_status'   => 'new',
            'partner_id'        => null,
        ]);

        $ok = self::$db->insert($table, $row, [
            '%s','%s','%s','%s','%d','%s','%s','%s',
            '%s','%s','%s','%s','%s','%s','%s',
            '%s','%s','%s','%d'
        ]);

        if (!$ok) return new WP_Error('db_insert_error', 'Could not insert lead');
        return (int) self::$db->insert_id;
    }

    public static function get(int $lead_id): ?array {
        self::boot();
        $table = self::t_leads();
        $row = self::$db->get_row(
            self::$db->prepare("SELECT * FROM {$table} WHERE id = %d", $lead_id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function update(int $lead_id, array $data) {
        self::boot();
        $table = self::t_leads();
        if (!$lead_id) return new WP_Error('bad_id', 'Invalid lead ID');

        $allowed = [
            'name','email','phone','est_ship_date',
            'vehicle_year','vehicle_brand','vehicle_model','vehicle_condition',
            'from_city','from_state','from_zip','to_city','to_state','to_zip',
            'sent_at','dispatch_method','crm_response_json','response_status','partner_id'
        ];
        $payload = array_intersect_key($data, array_flip($allowed));
        if (empty($payload)) return new WP_Error('no_fields', 'No updatable fields provided');

        $formats = [];
        foreach ($payload as $k => $v) {
            if (in_array($k, ['vehicle_year','partner_id'], true)) $formats[] = '%d';
            else $formats[] = '%s';
        }

        $ok = self::$db->update($table, $payload, ['id'=>$lead_id], $formats, ['%d']);
        return $ok !== false;
    }

    public static function delete(int $lead_id): bool {
        self::boot();
        $table = self::t_leads();
        $ok = self::$db->delete($table, ['id'=>$lead_id], ['%d']);
        return (bool)$ok;
    }

    /** ===== Масові дії з ТРАНЗАКЦІЄЮ НА КОЖЕН ЛІД ===== */

    /**
     * Масово призначити групу (FORCE) для набору лідiв.
     * - Транзакція НА КОЖЕН лід: UPDATE у leads + INSERT у leadrouter_logs.
     *
     * @param int[] $lead_ids
     * @param int   $group_id
     * @param array $opts ['dispatch_method'=>'manual']
     * @return array {done:int, errors:int}
     */
    public static function bulk_assign_group(array $lead_ids, int $group_id, array $opts = []): array {
        self::boot();
        $lead_ids = array_values(array_filter(array_map('intval', $lead_ids)));
        if (empty($lead_ids) || $group_id <= 0) return ['done'=>0,'errors'=>count($lead_ids)];

        $dispatch_method = isset($opts['dispatch_method']) ? (string)$opts['dispatch_method'] : 'manual';
        $ts = self::now_str();

        $done=0; $err=0;

        foreach ($lead_ids as $lid) {
            try {
                self::tx_begin();

                // 1) UPDATE lead
                $ok1 = self::$db->update(
                    self::t_leads(),
                    ['sent_at'=>$ts, 'response_status'=>'group_assigned', 'dispatch_method'=>$dispatch_method],
                    ['id'=>$lid],
                    ['%s','%s','%s'],
                    ['%d']
                );
                if ($ok1 === false) {
                    throw new Exception('Lead update failed');
                }

                // 2) INSERT log
                $ok2 = self::$db->insert(
                    self::t_logs(),
                    [
                        'lead_id'     => $lid,
                        'partner_id'  => 0,
                        'group_id'    => $group_id,
                        'assigned_at' => $ts,
                        'status'      => 'group_assigned',
                    ],
                    ['%d','%d','%d','%s','%s']
                );
                if (!$ok2) {
                    throw new Exception('Log insert failed');
                }

                self::tx_commit();
                $done++;

            } catch (Throwable $e) {
                self::tx_rollback();
                $err++;
                // Можеш залогувати $e->getMessage()
            }
        }

        return ['done'=>$done,'errors'=>$err];
    }

    /**
     * Масово поставити в чергу конкретному партнеру (QUEUE).
     * - Транзакція НА КОЖЕН лід: UPDATE у leads + INSERT у leadrouter_partner_logs.
     *
     * @param int[] $lead_ids
     * @param int   $partner_id
     * @param array $opts ['group_id'=>int|null, 'dispatch_method'=>'manual', 'request'=>array]
     * @return array {done:int, errors:int}
     */
    public static function bulk_queue_partner(array $lead_ids, int $partner_id, array $opts = []): array {
        self::boot();
        $lead_ids = array_values(array_filter(array_map('intval', $lead_ids)));
        if (empty($lead_ids) || $partner_id <= 0) return ['done'=>0,'errors'=>count($lead_ids)];

        $group_id        = isset($opts['group_id']) ? (int)$opts['group_id'] : 0;
        $dispatch_method = isset($opts['dispatch_method']) ? (string)$opts['dispatch_method'] : 'manual';
        $request         = isset($opts['request']) ? $opts['request'] : null;

        $ts = self::now_str();
        $req_json = $request ? wp_json_encode($request, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;

        $done=0; $err=0;

        foreach ($lead_ids as $lid) {
            try {
                self::tx_begin();

                // 1) UPDATE lead
                $ok1 = self::$db->update(
                    self::t_leads(),
                    ['partner_id'=>$partner_id, 'response_status'=>'partner_queued', 'dispatch_method'=>$dispatch_method],
                    ['id'=>$lid],
                    ['%d','%s','%s'],
                    ['%d']
                );
                if ($ok1 === false) {
                    throw new Exception('Lead update failed');
                }

                // 2) INSERT partner log
                $ok2 = self::$db->insert(
                    self::t_plogs(),
                    [
                        'lead_id'        => $lid,
                        'group_id'       => $group_id,          // якщо знаємо
                        'partner_id'     => $partner_id,
                        'attempt_no'     => 1,
                        'attempted_at'   => $ts,                // EST
                        'dispatch_method'=> $dispatch_method,
                        'request_json'   => $req_json,
                        'response_json'  => null,
                        'http_code'      => null,
                        'status'         => 'queued',
                        'error_message'  => null,
                    ],
                    ['%d','%d','%d','%d','%s','%s','%s','%s','%d','%s','%s']
                );
                if (!$ok2) {
                    throw new Exception('Partner log insert failed');
                }

                self::tx_commit();
                $done++;

            } catch (Throwable $e) {
                self::tx_rollback();
                $err++;
                // Можеш залогувати $e->getMessage()
            }
        }

        return ['done'=>$done,'errors'=>$err];
    }


    public static function update_sent_summary(int $lead_id, array $partners): void {

        error_log('update_sent_summary fn1: lead_id=' . $lead_id);

        if ($lead_id <= 0 || empty($partners)) {
            return;
        }


        error_log('update_sent_summary fn2: lead_id=' . $lead_id);

        self::boot();
        $table = self::t_leads();

        // поточний JSON
        $existing_json = self::$db->get_var(
            self::$db->prepare("SELECT sent_summary_json FROM {$table} WHERE id = %d", $lead_id)
        );

        $summary = ['partners' => []];

        if ($existing_json) {
            $decoded = json_decode($existing_json, true);
            if (is_array($decoded) && !empty($decoded['partners']) && is_array($decoded['partners'])) {
                $summary['partners'] = $decoded['partners'];
            }
        }

        // мапа по ключу partner_id|group_id, щоб не плодити дублі
        $map = [];
        foreach ($summary['partners'] as $row) {
            $pid = isset($row['partner_id']) ? (int)$row['partner_id'] : 0;
            $gid = isset($row['group_id']) ? (int)$row['group_id'] : 0;
            if (!$pid) continue;
            $key = $pid . '|' . $gid;
            $map[$key] = $row;
        }

        // нові / оновлені елементи
        foreach ($partners as $p) {
            $pid = isset($p['partner_id']) ? (int)$p['partner_id'] : 0;
            if (!$pid) continue;

            $gid = isset($p['group_id']) ? (int)$p['group_id'] : 0;

            $key = $pid . '|' . $gid;

            $map[$key] = [
                'partner_id' => $pid,
                'group_id'   => $gid ?: null,
                'status'     => isset($p['status']) ? (string)$p['status'] : 'sent',
                'method'     => isset($p['method']) ? (string)$p['method'] : '',
                'sent_at'    => isset($p['sent_at']) ? (string)$p['sent_at'] : self::now_str(),
            ];
        }

        $summary['partners'] = array_values($map);

        $json = wp_json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }

        self::$db->update(
            $table,
            ['sent_summary_json' => $json],
            ['id' => $lead_id],
            ['%s'],
            ['%d']
        );
    }
}
