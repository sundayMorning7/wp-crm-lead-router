<?php
/**
 * LR_SimWeek — стенд «тиждень роботи» для локальної перевірки розподілу.
 *
 * Ганяє реальний пайплайн (LeadRouter_Flow::dispatch_broadcast з тими самими
 * опціями, що передають крони) на згенерованому потоці лідів, добу за добою,
 * і рахує, чи розподіл коректний.
 *
 * ЧОМУ НЕ КРОНАМИ. Три воркери беруть по одному ліду за хвилину під локом —
 * 700 лідів це 700+ хвилин. Тому ліди подаються в диспетч напряму, а ретраї
 * моделюються окремими проходами (те, що за добу зробили б await/error крони).
 *
 * ЧОМУ БЕЗ ПІДМІНИ ЧАСУ. Доба «закривається» зсувом created_at/attempted_at
 * свого батчу в минуле + обнуленням eff. Наступний батч бачить чисті денні
 * лічильники, а Flow весь час працює в справжньому «зараз». Обмеження: прогін
 * має відбуватись у робочому вікні партнерів (усі 13 працюють 08:00–22:00 EST).
 *
 * БЕЗПЕКА. У партнерів прописані БОЙОВІ ендпоінти (api.batscrm.com і т.д.).
 * Без встановленого мока на pre_http_request стенд не запускається взагалі —
 * жоден запит не має піти в реальний CRM партнера. Білінг на час прогону
 * знімається з хука, листи глушаться.
 *
 * Тільки для дев-середовища: підключається лише під WP_CLI і перевіряє
 * LEADROUTER_PRODUCTION.
 */

defined('ABSPATH') || exit;

class LR_SimWeek
{
    /** Мітка в dispatch_method згенерованих лідів */
    const TAG = 'simweek';

    /** Опція з маніфестом прогону: які ліди в який день */
    const OPTION_MANIFEST = 'lr_simweek_manifest';

    /** Статус, у який паркуємо сторонні error-ліди, щоб не лізли в тест */
    const PARKED = 'simweek_parked';

    /** Лічильники мока HTTP */
    private static $http = ['total' => 0, 'ok' => 0, 'hard' => 0, 'temp' => 0, 'dup' => 0, 'by_host' => []];

    /** Зняті колбеки leadrouter_after_send (щоб повернути після прогону) */
    private static $muted_billing = null;

    /** Чи встановлено мок HTTP */
    private static $mock_on = false;

    /** Скільки листів перехоплено */
    private static $mails = 0;

    /** Слаг дня тижня, ліміти якого підставляємо (модель «доба тижня») */
    private static $sim_slug = null;

    /** Чи розширено робочі вікна партнерів на добу (лише в пам'яті) */
    private static $widen_hours = false;

    /* ============================================================
     * Безпека
     * ============================================================ */

    /** @throws RuntimeException */
    public static function assert_dev(): void
    {
        if (defined('LEADROUTER_PRODUCTION') && LEADROUTER_PRODUCTION) {
            throw new RuntimeException('LEADROUTER_PRODUCTION увімкнено — стенд заборонено');
        }
    }

    /** @throws RuntimeException */
    public static function assert_ready(): void
    {
        self::assert_dev();

        if (!self::$mock_on) {
            throw new RuntimeException('Мок HTTP не встановлено — відмовляюсь відправляти в реальні ендпоінти партнерів');
        }

        if (self::$widen_hours) {
            return; // вікна підмінені в пам'яті — реальна година вже не заважає
        }

        $h = (int)(new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('G');
        if ($h < 8 || $h >= 22) {
            throw new RuntimeException(sprintf(
                'Зараз %02d:00 EST — партнери працюють 08:00–22:00, прогін дав би нуль відправок',
                $h
            ));
        }
    }

    /**
     * Підміна мети партнерів на час прогону — БЕЗ запису в БД, лише фільтр
     * читання. Дає дві речі:
     *
     *   1) ліміти дня, який моделюємо (у конфігурації вони різні: Σ по General
     *      падає з 340 у пн до 230 у нд, тож «тиждень» без цього був би фікцією);
     *   2) за потреби — розширені робочі вікна, щоб прогін був можливий поза
     *      08:00–22:00 EST. Це єдине спрощення, і воно нічого не спотворює лише
     *      тому, що вікна у всіх партнерів однакові.
     */
    private static function install_meta_filter(): void
    {
        add_filter('get_post_metadata', static function ($value, $object_id, $meta_key, $single) {
            if (!is_string($meta_key) || strpos($meta_key, '_leadrouter_partner_') !== 0) {
                return $value;
            }

            if (self::$sim_slug !== null
                && preg_match('/^_leadrouter_partner_(mon|tue|wed|thu|fri|sat|sun)_limit$/', $meta_key, $m)
                && $m[1] !== self::$sim_slug
            ) {
                $real = get_post_meta((int)$object_id, "_leadrouter_partner_" . self::$sim_slug . "_limit", true);

                return $single ? $real : [$real];
            }

            if (self::$widen_hours && preg_match('/^_leadrouter_partner_(mon|tue|wed|thu|fri|sat|sun)_(start|end)$/', $meta_key, $m)) {
                $v = $m[2] === 'start' ? '00:00' : '23:59';

                return $single ? $v : [$v];
            }

            return $value;
        }, 10, 4);
    }

    /* ============================================================
     * Глушники
     * ============================================================ */

    /**
     * Мок HTTP + глушник пошти + зняття білінгу.
     *
     * @param array $rates ['hard' => 2.0, 'temp' => 3.0, 'dup' => 1.0] у відсотках
     * @return array що саме заглушено
     */
    public static function mute(array $rates, bool $widen_hours = false): array
    {
        self::assert_dev();

        $hard = (float)($rates['hard'] ?? 2.0);
        $temp = (float)($rates['temp'] ?? 3.0);
        $dup  = (float)($rates['dup'] ?? 1.0);

        self::$widen_hours = $widen_hours;
        self::install_meta_filter();

        // ── HTTP: жодного реального запиту, і тут же генеруємо відмови ──
        add_filter('pre_http_request', static function ($pre, $args, $url) use ($hard, $temp, $dup) {
            self::$http['total']++;

            $host = (string)wp_parse_url($url, PHP_URL_HOST);
            self::$http['by_host'][$host] = (self::$http['by_host'][$host] ?? 0) + 1;

            $roll = mt_rand(0, 999999) / 10000; // 0..100 з чотирма знаками

            if ($roll < $hard) {
                self::$http['hard']++;
                return self::http_response(422, '{"error":"validation_failed"}');
            }
            if ($roll < $hard + $temp) {
                self::$http['temp']++;
                return self::http_response(503, '{"error":"service_unavailable"}');
            }
            if ($roll < $hard + $temp + $dup) {
                self::$http['dup']++;
                return self::http_response(409, '{"error":"duplicate_lead"}');
            }

            self::$http['ok']++;

            return self::http_response(200, wp_json_encode([
                'ok'          => true,
                'external_id' => 'sim-' . self::$http['total'],
            ]));
        }, 1, 3);

        self::$mock_on = true;

        // ── Пошта: рахуємо, але не шлемо ──
        add_filter('pre_wp_mail', static function () {
            self::$mails++;
            return true;
        }, 1);

        // ── Білінг: знімаємо всі колбеки з leadrouter_after_send ──
        global $wp_filter;
        $muted = 0;
        if (isset($wp_filter['leadrouter_after_send'])) {
            self::$muted_billing = $wp_filter['leadrouter_after_send'];
            foreach (self::$muted_billing->callbacks as $cbs) {
                $muted += count($cbs);
            }
            unset($wp_filter['leadrouter_after_send']);
        }

        return [
            'http_mock'     => true,
            'mail_mock'     => true,
            'billing_muted' => $muted,
            'widen_hours'   => $widen_hours,
            'rates'         => ['hard' => $hard, 'temp' => $temp, 'dup' => $dup],
        ];
    }

    /** Повернути білінг на місце (гігієна в межах процесу) */
    public static function unmute(): void
    {
        global $wp_filter;

        if (self::$muted_billing !== null) {
            $wp_filter['leadrouter_after_send'] = self::$muted_billing;
            self::$muted_billing = null;
        }
    }

    /** Відповідь у форматі, який очікує wp_remote_request */
    private static function http_response(int $code, string $body): array
    {
        static $messages = [200 => 'OK', 409 => 'Conflict', 422 => 'Unprocessable Entity', 503 => 'Service Unavailable'];

        return [
            'headers'  => [],
            'body'     => $body,
            'response' => ['code' => $code, 'message' => $messages[$code] ?? ''],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /** Лічильники мока за прогін */
    public static function http_stats(): array
    {
        return array_merge(self::$http, ['mails' => self::$mails]);
    }

    public static function reset_stats(): void
    {
        self::$http  = ['total' => 0, 'ok' => 0, 'hard' => 0, 'temp' => 0, 'dup' => 0, 'by_host' => []];
        self::$mails = 0;
    }

    /* ============================================================
     * Передпольотна перевірка
     * ============================================================ */

    /** Усе, що впливає на результат доби, — до того, як щось запускати */
    public static function preflight(): array
    {
        global $wpdb;

        $tz  = new DateTimeZone('America/New_York');
        $now = new DateTimeImmutable('now', $tz);
        $dow = (int)$now->format('N');

        $groups = $wpdb->get_results(
            "SELECT id, post_id, name, mode, share_n, active, daily_volume, coef, eff, weight_{$dow} AS w
               FROM {$wpdb->prefix}leadrouter_groups ORDER BY name",
            ARRAY_A
        ) ?: [];

        foreach ($groups as &$g) {
            $ids = get_posts([
                'post_type'      => 'leadrouter_partner',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => [
                    ['key' => '_leadrouter_partner_group', 'value' => (int)$g['post_id']],
                    ['key' => '_leadrouter_partner_active', 'value' => '1'],
                ],
            ]);

            static $slugs = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
            $slug = $slugs[$dow];

            $sum = 0;
            foreach ($ids as $pid) {
                $sum += max(0, (int)get_post_meta($pid, "_leadrouter_partner_{$slug}_limit", true));
            }

            $g['partners']   = count($ids);
            $g['sum_limits'] = $sum;
            $g['eligible']   = ((int)$g['active'] === 1 && (int)$g['w'] > 0 && $sum > 0);
        }
        unset($g);

        $lead_statuses = $wpdb->get_results(
            "SELECT status, COUNT(*) c FROM {$wpdb->prefix}leadrouter_leads GROUP BY status",
            ARRAY_A
        ) ?: [];

        return [
            'now_est'        => $now->format('Y-m-d H:i'),
            'dow'            => $dow,
            'window_ok'      => ((int)$now->format('G') >= 8 && (int)$now->format('G') < 22),
            'production'     => defined('LEADROUTER_PRODUCTION') && LEADROUTER_PRODUCTION,
            'block_external' => defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL,
            'error_group_id' => function_exists('carbon_get_theme_option')
                ? (int)carbon_get_theme_option('leadrouter_error_group_id')
                : 0,
            'groups'         => $groups,
            'lead_statuses'  => $lead_statuses,
        ];
    }

    /* ============================================================
     * Генерація потоку
     * ============================================================ */

    /**
     * Ліди на добу. Імена НІКОЛИ не починаються на «test» — інакше крон
     * позначив би їх sent без відправки, і тест міряв би порожнечу.
     *
     * @return int[] ID створених лідів
     */
    public static function make_leads(int $count, string $date, float $ak_hi_pct = 2.0): array
    {
        global $wpdb;

        static $first = ['Oliver', 'Emma', 'Liam', 'Ava', 'Noah', 'Sophia', 'Mason', 'Isabella', 'Lucas', 'Mia',
                         'Ethan', 'Amelia', 'James', 'Harper', 'Benjamin', 'Evelyn', 'Henry', 'Abigail'];
        static $last  = ['Smith', 'Johnson', 'Brown', 'Davis', 'Miller', 'Wilson', 'Moore', 'Taylor', 'Anderson',
                         'Thomas', 'Jackson', 'White', 'Harris', 'Martin', 'Clark', 'Lewis'];
        static $cities = [
            ['Miami', 'FL', '33101'], ['Dallas', 'TX', '75201'], ['Chicago', 'IL', '60601'],
            ['Phoenix', 'AZ', '85001'], ['Denver', 'CO', '80202'], ['Seattle', 'WA', '98101'],
            ['Atlanta', 'GA', '30301'], ['Boston', 'MA', '02108'], ['Detroit', 'MI', '48201'],
            ['Portland', 'OR', '97201'], ['Nashville', 'TN', '37201'], ['Newark', 'NJ', '07102'],
        ];
        static $excluded = [['Anchorage', 'AK', '99501'], ['Honolulu', 'HI', '96813']];
        static $brands = [['Toyota', 'Camry'], ['Honda', 'Accord'], ['Ford', 'F-150'], ['BMW', 'X5'],
                          ['Tesla', 'Model 3'], ['Chevrolet', 'Malibu'], ['Nissan', 'Altima']];

        $table = $wpdb->prefix . 'leadrouter_leads';
        $now   = current_time('mysql');
        $ids   = [];

        for ($i = 1; $i <= $count; $i++) {
            $name = $first[mt_rand(0, count($first) - 1)] . ' ' . $last[mt_rand(0, count($last) - 1)];

            $use_excluded = (mt_rand(0, 9999) / 100) < $ak_hi_pct;
            $from = $cities[mt_rand(0, count($cities) - 1)];
            $to   = $use_excluded
                ? $excluded[mt_rand(0, count($excluded) - 1)]
                : $cities[mt_rand(0, count($cities) - 1)];

            $car = $brands[mt_rand(0, count($brands) - 1)];
            $uid = strtolower(str_replace(' ', '.', $name)) . '.' . $date . '.' . $i;

            $wpdb->insert($table, [
                'name'              => $name,
                'email'             => $uid . '@example.com',
                'phone'             => '305' . str_pad((string)mt_rand(1000000, 9999999), 7, '0', STR_PAD_LEFT),
                'est_ship_date'     => $date,
                'vehicle_year'      => mt_rand(2012, 2025),
                'vehicle_brand'     => $car[0],
                'vehicle_model'     => $car[1],
                'vehicle_condition' => mt_rand(0, 9) ? 'running' : 'not_running',
                'from_city'         => $from[0],
                'from_state'        => $from[1],
                'from_zip'          => $from[2],
                'to_city'           => $to[0],
                'to_state'          => $to[1],
                'to_zip'            => $to[2],
                'utm_source'        => self::TAG,
                'utm_content'       => $date,
                'utm_medium'        => '',
                'utm_term'          => '',
                'utm_campaign'      => '',
                'created_at'        => $now,
                'dispatch_method'   => self::TAG,
                'response_status'   => 'new',
                'status'            => 'new',
                'phone_norm'        => '',
                'email_norm'        => $uid . '@example.com',
            ]);

            $ids[] = (int)$wpdb->insert_id;
        }

        return $ids;
    }

    /* ============================================================
     * Прогін доби
     * ============================================================ */

    /**
     * Опції диспетчу — копія того, що передають крони (щоб стенд не міряв
     * власну вигадану конфігурацію).
     */
    private static function opts(string $kind): array
    {
        $base = [
            'group_meta_key'  => '_leadrouter_partner_group',
            'statuses'        => ['queued', 'sent', 'accepted'],
            'initial_status'  => 'sent',
            'queue_if_closed' => true,
        ];

        if ($kind === 'new') {
            return $base + ['dispatch_method' => 'auto_cron_new_lead'];
        }
        if ($kind === 'await') {
            return $base + ['dispatch_method' => 'auto_cron_await_lead'];
        }

        $force = function_exists('carbon_get_theme_option')
            ? (int)carbon_get_theme_option('leadrouter_error_group_id')
            : 0;

        return $base + ['dispatch_method' => 'auto_cron_error_lead', 'force_group_post_id' => $force];
    }

    /**
     * Одна доба: генерація → первинний прохід → проходи ретраїв.
     *
     * @return array підсумок доби
     */
    public static function run_day(string $date, int $count, int $passes = 3): array
    {
        global $wpdb;

        self::assert_ready();

        // ліміти беремо ті, що діють у день тижня МОДЕЛЬОВАНОЇ дати
        static $slugs = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
        $sim_dow        = (int)(new DateTimeImmutable($date, new DateTimeZone('America/New_York')))->format('N');
        self::$sim_slug = $slugs[$sim_dow];

        // error-крон у продакшені мовчить, якщо не задана група помилкових
        // лідів — стенд не має робити те, чого не робить бій
        $error_group = function_exists('carbon_get_theme_option')
            ? (int)carbon_get_theme_option('leadrouter_error_group_id')
            : 0;

        $table = $wpdb->prefix . 'leadrouter_leads';
        $ids   = self::make_leads($count, $date);

        $log = [
            'date'        => $date,
            'requested'   => $count,
            'lead_ids'    => $ids,
            'sim_slug'    => self::$sim_slug,
            'error_group' => $error_group,
            'passes'      => [],
        ];

        // ── прохід 1: усі new (як auto_cron_new_lead) ──
        $done = 0;
        foreach ($ids as $lead_id) {
            $wpdb->update($table, ['status' => 'processing_newcron'], ['id' => $lead_id], ['%s'], ['%d']);
            LeadRouter_Flow::dispatch_broadcast($lead_id, self::opts('new'));
            $done++;
        }
        $log['passes'][] = ['kind' => 'new', 'processed' => $done] + self::status_snapshot($ids);

        // ── проходи ретраїв: await і error (як два ретрай-крони за добу) ──
        for ($p = 1; $p <= $passes; $p++) {
            $retry = 0;

            // await-крон добирає і 'await', і 'sent_partial' (STATUSES_RETRY);
            // error-крон вмикається лише коли задана група помилкових лідів
            $kinds = ['await' => LeadRouter_Cron_Await_Leads::STATUSES_RETRY];
            if ($error_group > 0) {
                $kinds['error'] = LeadRouter_Cron_Error_Leads::STATUS_ERRORS;
            }

            foreach ($kinds as $kind => $statuses) {
                $in = implode(',', array_fill(0, count($statuses), '%s'));

                $pending = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE status IN ({$in}) AND dispatch_method = %s",
                    array_merge($statuses, [self::TAG])
                ));

                foreach ($pending as $lead_id) {
                    LeadRouter_Flow::dispatch_broadcast((int)$lead_id, self::opts($kind));
                    $retry++;
                }
            }

            $log['passes'][] = ['kind' => 'retry #' . $p, 'processed' => $retry] + self::status_snapshot($ids);

            if ($retry === 0) {
                break;
            }
        }

        $log['http'] = self::http_stats();

        return $log;
    }

    /** Розклад статусів по набору лідів */
    private static function status_snapshot(array $ids): array
    {
        global $wpdb;

        if (empty($ids)) {
            return ['statuses' => []];
        }

        $in  = implode(',', array_map('intval', $ids));
        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) c FROM {$wpdb->prefix}leadrouter_leads WHERE id IN ({$in}) GROUP BY status",
            ARRAY_A
        ) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[$r['status']] = (int)$r['c'];
        }
        ksort($out);

        return ['statuses' => $out];
    }

    /* ============================================================
     * Закриття доби
     * ============================================================ */

    /**
     * Перемотати добу назад: зсунути мітки часу батчу на потрібну дату й
     * обнулити eff — рівно те, що дає перехід доби в EST.
     */
    public static function close_day(array $ids, string $date): array
    {
        global $wpdb;

        if (empty($ids)) {
            return ['shifted' => []];
        }

        $tz    = new DateTimeZone('America/New_York');
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $diff  = (int)(new DateTimeImmutable($today, $tz))->diff(new DateTimeImmutable($date, $tz))->format('%r%a');

        $in  = implode(',', array_map('intval', $ids));
        $out = ['days_shift' => $diff, 'shifted' => []];

        if ($diff === 0) {
            $out['note'] = 'дата збігається з сьогоднішньою — зсув не потрібен';
        } else {
            $targets = [
                ['leadrouter_leads', 'id', ['created_at', 'sent_at', 'last_error_at']],
                ['leadrouter_lead_assignments', 'lead_id', ['created_at']],
                ['leadrouter_send_log', 'lead_id', ['attempted_at']],
                ['leadrouter_partner_logs', 'lead_id', ['attempted_at']],
            ];

            foreach ($targets as [$table, $key, $cols]) {
                $full = $wpdb->prefix . $table;
                $sets = [];
                foreach ($cols as $c) {
                    // 0000-00-00 не чіпаємо — це «порожньо», а не дата
                    $sets[] = "{$c} = IF({$c} IS NULL OR {$c} = '0000-00-00 00:00:00', {$c}, DATE_ADD({$c}, INTERVAL {$diff} DAY))";
                }

                $affected = $wpdb->query(
                    "UPDATE {$full} SET " . implode(', ', $sets) . " WHERE {$key} IN ({$in})"
                );

                $out['shifted'][$table] = (int)$affected;
            }
        }

        // нова доба → eff з нуля (у реальному житті це робить reset_eff_if_new_day)
        $wpdb->query("UPDATE {$wpdb->prefix}leadrouter_groups SET eff = 0");
        $out['eff_reset'] = true;

        return $out;
    }

    /* ============================================================
     * Маніфест прогону
     * ============================================================ */

    public static function manifest_add(string $date, array $ids, int $seed): void
    {
        $m = get_option(self::OPTION_MANIFEST, []);
        if (!is_array($m)) {
            $m = [];
        }
        $m[$date] = ['ids' => array_map('intval', $ids), 'seed' => $seed, 'count' => count($ids)];
        update_option(self::OPTION_MANIFEST, $m, false);
    }

    public static function manifest(): array
    {
        $m = get_option(self::OPTION_MANIFEST, []);

        return is_array($m) ? $m : [];
    }

    public static function manifest_reset(): void
    {
        delete_option(self::OPTION_MANIFEST);
    }

    /* ============================================================
     * Паркування сторонніх лідів
     * ============================================================ */

    /**
     * Ліди в new/await/error, що існували ДО тесту, з'їли б ліміти партнерів і
     * зіпсували вимірювання. На час прогону паркуємо їх в окремий статус.
     */
    public static function park_foreign(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'leadrouter_leads';

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE status IN ('new','await','error') AND dispatch_method <> %s",
            self::TAG
        ));

        if (!empty($ids)) {
            $in = implode(',', array_map('intval', $ids));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = %s WHERE id IN ({$in})",
                self::PARKED
            ));
        }

        return array_map('intval', $ids);
    }

    /** Повернути запарковані ліди в error (звідки їх узяли) */
    public static function unpark(array $ids): int
    {
        global $wpdb;

        if (empty($ids)) {
            return 0;
        }

        $in = implode(',', array_map('intval', $ids));

        return (int)$wpdb->query(
            "UPDATE {$wpdb->prefix}leadrouter_leads SET status = 'error' WHERE id IN ({$in})"
        );
    }
}
