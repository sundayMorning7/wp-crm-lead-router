<?php
// WP-CLI: leadrouter simulate-proportion / simulate-capacity
    if (defined('WP_CLI') && WP_CLI) {

    class LeadRouter_CLI {

        /**
         * Симуляція без обмежень (чиста пропорція ваг сьогоднішнього дня, EST).
         *
         * ## OPTIONS
         * [--n=<n>]
         * : Кількість ітерацій (default 100)
         *
         * ## EXAMPLES
         *   wp leadrouter simulate-proportion --n=500
         */
        public function simulate_proportion( $args, $assoc ) {
            global $wpdb;

            $n = isset($assoc['n']) ? max(1, (int)$assoc['n']) : 100;

            $now  = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
            $dowN = (int)$now->format('N'); // 1..7

            $table_groups = $wpdb->prefix . 'leadrouter_groups';
            $groups = $wpdb->get_results("
                SELECT id, name, weight,
                       weight_1, weight_2, weight_3, weight_4, weight_5, weight_6, weight_7,
                       active
                FROM {$table_groups}
                WHERE active = 1
            ", ARRAY_A);

            if (empty($groups)) {
                WP_CLI::error('No active groups.');
                return;
            }

            // Побудова items: weight = ефективна вага на сьогодні (EST)
            $items = [];
            $totalW = 0;
            foreach ($groups as $g) {
                $w = self::effective_weight_today($g, $dowN);
                if ($w > 0) {
                    $items[] = ['group_id' => (int)$g['id'], 'name' => (string)$g['name'], 'weight' => (int)$w];
                    $totalW += (int)$w;
                }
            }
            if ($totalW <= 0) {
                WP_CLI::error('All effective weights are zero for today (EST).');
                return;
            }

            // Симуляція: чистий зважений рандом без квот
            $result = [];
            for ($i=0; $i<$n; $i++) {
                $pick = self::weighted_pick($items, $totalW);
                if (!$pick) continue;
                $gid = $pick['group_id'];
                $result[$gid] = ($result[$gid] ?? 0) + 1;
            }

            if (empty($result)) {
                WP_CLI::line('No picks produced.');
                return;
            }

            ksort($result);
            foreach ($result as $gid => $cnt) {
                WP_CLI::line(sprintf('group_id=%d -> %d', $gid, $cnt));
            }
        }

        /**
         * Симуляція з урахуванням денних квот (ефективна вага як ліміт, EST).
         * Квота зменшується in-memory, старт бере вже використане за сьогодні з leadrouter_logs.
         *
         * ## OPTIONS
         * [--n=<n>]
         * : Кількість ітерацій (default 100)
         *
         * ## EXAMPLES
         *   wp leadrouter simulate-capacity --n=200
         */
        public function simulate_capacity( $args, $assoc ) {
            global $wpdb;

            $n = isset($assoc['n']) ? max(1, (int)$assoc['n']) : 100;

            $now   = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
            $dowN  = (int)$now->format('N'); // 1..7
            $start = $now->format('Y-m-d 00:00:00');
            $end   = $now->format('Y-m-d 23:59:59');

            $table_groups = $wpdb->prefix . 'leadrouter_groups';
            $groups = $wpdb->get_results("
                SELECT id, name, weight,
                       weight_1, weight_2, weight_3, weight_4, weight_5, weight_6, weight_7,
                       active
                FROM {$table_groups}
                WHERE active = 1
            ", ARRAY_A);

            if (empty($groups)) {
                WP_CLI::error('No active groups.');
                return;
            }

            // Побудувати items із квотами на сьогодні
            $items = [];
            foreach ($groups as $g) {
                $w = self::effective_weight_today($g, $dowN); // денний ліміт
                if ($w > 0) {
                    $items[(int)$g['id']] = [
                        'group_id' => (int)$g['id'],
                        'name'     => (string)$g['name'],
                        'quota'    => (int)$w,
                    ];
                }
            }
            if (empty($items)) {
                WP_CLI::error('All effective weights are zero for today (EST).');
                return;
            }

            // Відняти вже використані призначення за сьогодні
            $table_logs = $wpdb->prefix . 'leadrouter_logs';
            $rows = $wpdb->get_results(
                $wpdb->prepare("
                    SELECT group_id, COUNT(*) AS cnt
                    FROM {$table_logs}
                    WHERE status = 'group_assigned'
                      AND assigned_at BETWEEN %s AND %s
                    GROUP BY group_id
                ", $start, $end),
                ARRAY_A
            );
            $used = [];
            foreach ($rows as $r) {
                $gid = (int)$r['group_id'];
                $used[$gid] = (int)$r['cnt'];
            }

            // Симуляція: кожний пік зменшує quota_left у пам'яті
            $result = [];
            for ($i=0; $i<$n; $i++) {
                // Побудувати пул доступних із quota_left
                $pool = [];
                $totalW = 0;
                foreach ($items as $gid => $it) {
                    $left = $it['quota'] - (int)($used[$gid] ?? 0);
                    if ($left > 0) {
                        $pool[] = ['group_id'=>$gid, 'name'=>$it['name'], 'weight'=>$left];
                        $totalW += $left;
                    }
                }
                if ($totalW <= 0 || empty($pool)) {
                    break; // квоти вичерпано
                }

                $pick = self::weighted_pick($pool, $totalW);
                if (!$pick) { $pick = end($pool); }

                $gid = $pick['group_id'];
                $used[$gid] = (int)($used[$gid] ?? 0) + 1;
                $result[$gid] = ($result[$gid] ?? 0) + 1;
            }

            if (empty($result)) {
                WP_CLI::line('No simulated picks (maybe all quotas are already exhausted today).');
                return;
            }

            ksort($result);
            foreach ($result as $gid => $cnt) {
                WP_CLI::line(sprintf('group_id=%d -> %d', $gid, $cnt));
            }
        }

        // DEV ONLY — не виконувати на продакшні: створює фейкові дані (DEMO-групи/партнери/білінг-профілі).
        /**
         * Розгорнути тестове білінг-середовище для перевірки розподілу + білінгу.
         *
         * Створює (ідемпотентно):
         *   • 2 групи — "DEMO Group A" (вага 70 по всіх днях) і "DEMO Group B" (вага 30),
         *     щоб перевірити weighted-розподіл 70/30;
         *   • 3 партнери з доставкою через email (лід піде на demo*@demo.local → MailHog/Mailpit):
         *       - DEMO Partner 1 → Group A, demo1@demo.local
         *       - DEMO Partner 2 → Group A, demo2@demo.local
         *       - DEMO Partner 3 → Group B, demo3@demo.local
         *     кожен: active=1, type=email, денний ліміт 1000 по всіх днях, години 00:00–23:59;
         *   • білінг-профіль кожному в leadrouter_partner_billing.
         *
         * Повторний запуск НЕ дублює DEMO-сутності — знаходить їх за назвою й оновлює профілі
         * (а також скидає деактивацію/прапорці сповіщень, щоб тест-середовище було «свіжим»).
         *
         * ## EXAMPLES
         *   wp leadrouter billing-test-setup --allow-root
         */
        public function billing_test_setup( $args, $assoc ) {
            // 1) Дві групи з вагами для розподілу 70/30
            $group_a_id = self::upsert_group('DEMO Group A', 70);
            $group_b_id = self::upsert_group('DEMO Group B', 30);

            WP_CLI::log(sprintf('Група A: post_id=%d (вага 70 × 7 днів)', $group_a_id));
            WP_CLI::log(sprintf('Група B: post_id=%d (вага 30 × 7 днів)', $group_b_id));

            // 2) Конфіг трьох партнерів (delivery = email → MailHog)
            $partners = [
                [
                    'title' => 'DEMO Partner 1', 'group_id' => $group_a_id, 'group_label' => 'A',
                    'email' => 'demo1@demo.local',
                    'balance' => 100.0, 'lead_price' => 10.0, 'min_balance' => 50.0, 'allow_negative' => 0,
                ],
                [
                    'title' => 'DEMO Partner 2', 'group_id' => $group_a_id, 'group_label' => 'A',
                    'email' => 'demo2@demo.local',
                    // allow_negative=1 — тест списання в мінус
                    'balance' => 200.0, 'lead_price' => 5.0, 'min_balance' => 50.0, 'allow_negative' => 1,
                ],
                [
                    'title' => 'DEMO Partner 3', 'group_id' => $group_b_id, 'group_label' => 'B',
                    'email' => 'demo3@demo.local',
                    // balance < min_balance одразу — тест порогу низького балансу
                    'balance' => 30.0, 'lead_price' => 10.0, 'min_balance' => 50.0, 'allow_negative' => 0,
                ],
            ];

            // 3) Створити/оновити партнерів + білінг-профілі
            $table_rows = [];
            foreach ($partners as $cfg) {
                $pid = self::upsert_partner($cfg);
                self::upsert_billing($pid, $cfg);

                $table_rows[] = [
                    'ID'         => $pid,
                    'Назва'      => $cfg['title'],
                    'Група'      => sprintf('DEMO Group %s (#%d)', $cfg['group_label'], (int)$cfg['group_id']),
                    'balance'    => number_format($cfg['balance'], 2),
                    'lead_price' => number_format($cfg['lead_price'], 2),
                    'email'      => $cfg['email'],
                ];
            }

            // 4) ВАЖЛИВО: фіксуємо ваги груп ОСТАННІМИ.
            // Хук leadrouter_recalc_sum_weight (спрацьовує при publish партнера/групи)
            // перераховує вагу групи як MAX денних лімітів її партнерів і міг щойно
            // перезатерти наші 70/30 значенням ліміту (1000). Тому переписуємо їх тут.
            self::upsert_group('DEMO Group A', 70);
            self::upsert_group('DEMO Group B', 30);

            WP_CLI::log('');
            WP_CLI\Utils\format_items('table', $table_rows, ['ID', 'Назва', 'Група', 'balance', 'lead_price', 'email']);
            WP_CLI::success('Тестове білінг-середовище готове. Ліди підуть на demo*@demo.local → MailHog (http://localhost:8025).');
        }

        // ===== helpers (billing-test-setup) =====

        /** Знайти пост за точною назвою і типом; 0 якщо нема. */
        private static function find_post_by_title(string $title, string $post_type): int {
            $ids = get_posts([
                'post_type'        => $post_type,
                'title'            => $title,
                'post_status'      => 'any',
                'posts_per_page'   => 1,
                'fields'           => 'ids',
                'no_found_rows'    => true,
                'suppress_filters' => true,
            ]);
            return !empty($ids) ? (int)$ids[0] : 0;
        }

        /**
         * Створити/оновити групу-CPT і синкнути її ваги в таблицю leadrouter_groups.
         * Однакова вага $weight по всіх 7 днях тижня (Mon..Sun).
         */
        private static function upsert_group(string $title, int $weight): int {
            $gid = self::find_post_by_title($title, 'leadrouter_group');
            if ($gid <= 0) {
                $gid = (int) wp_insert_post([
                    'post_type'   => 'leadrouter_group',
                    'post_title'  => $title,
                    'post_status' => 'publish',
                ]);
            }
            if ($gid <= 0) {
                WP_CLI::error('Не вдалося створити групу: ' . $title);
            }

            // Вага по всіх днях 1..7
            $days = [];
            for ($d = 1; $d <= 7; $d++) {
                $days[$d] = $weight;
            }
            // Хелпер з helpers.php: upsert рядка leadrouter_groups за post_id (+active=1)
            leadrouter_save_group_day_weights_by_post($gid, $days, $title, 1);

            return $gid;
        }

        /**
         * Створити/оновити партнера-CPT з email-доставкою.
         * Ставить групу, активність, тип=email, денні ліміти 1000 і години 00:00–23:59 по всіх днях.
         */
        private static function upsert_partner(array $cfg): int {
            $pid = self::find_post_by_title($cfg['title'], 'leadrouter_partner');
            if ($pid <= 0) {
                $pid = (int) wp_insert_post([
                    'post_type'   => 'leadrouter_partner',
                    'post_title'  => $cfg['title'],
                    'post_status' => 'publish',
                ]);
            }
            if ($pid <= 0) {
                WP_CLI::error('Не вдалося створити партнера: ' . $cfg['title']);
            }

            // Прив'язка до групи + активність (ключі з underscore — як читає LeadRouter_Partners)
            update_post_meta($pid, '_leadrouter_partner_group', (int)$cfg['group_id']);
            update_post_meta($pid, '_leadrouter_partner_active', '1');

            // Доставка через email → send_via_email() читає _leadrouter_partner_email і шле wp_mail
            update_post_meta($pid, '_leadrouter_partner_type', 'email');
            update_post_meta($pid, '_leadrouter_partner_email', $cfg['email']);

            // AK/HI вимкнені (щоб не плутати маршрутизацію)
            update_post_meta($pid, '_leadrouter_partner_allow_alaska', '0');
            update_post_meta($pid, '_leadrouter_partner_allow_hawaii', '0');

            // Денний ліміт 1000 і цілодобові години по всіх днях
            foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $slug) {
                update_post_meta($pid, "_leadrouter_partner_{$slug}_limit", 1000);
                update_post_meta($pid, "_leadrouter_partner_{$slug}_start", '00:00');
                update_post_meta($pid, "_leadrouter_partner_{$slug}_end", '23:59');
            }

            return $pid;
        }

        /**
         * Створити/оновити білінг-профіль у leadrouter_partner_billing (UNIQUE по partner_id).
         * При оновленні скидає деактивацію білінгом і прапорці сповіщень — для чистого тесту.
         */
        private static function upsert_billing(int $partner_id, array $cfg): void {
            global $wpdb;
            $table = $wpdb->prefix . 'leadrouter_partner_billing';
            $now   = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m-d H:i:s');

            $data = [
                'balance'                   => (float)$cfg['balance'],
                'currency'                  => 'USD',
                'email'                     => (string)$cfg['email'],
                'lead_price'                => (float)$cfg['lead_price'],
                'min_balance'               => (float)$cfg['min_balance'],
                'allow_negative_balance'    => (int)$cfg['allow_negative'],
                'disable_low_balance_email' => 0,
                'deactivated_by_billing'    => 0,
                'notified_low_balance'      => 0,
                'notified_stopped'          => 0,
                'notified_admin_negative'   => 0,
                'status'                    => 'active',
                'updated_at'                => $now,
            ];
            $formats = ['%f', '%s', '%s', '%f', '%f', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s'];

            $existing_id = (int)$wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$table} WHERE partner_id = %d LIMIT 1", $partner_id)
            );

            if ($existing_id > 0) {
                $wpdb->update($table, $data, ['id' => $existing_id], $formats, ['%d']);
            } else {
                $data['partner_id'] = $partner_id;
                $data['created_at'] = $now;
                $wpdb->insert($table, $data, array_merge($formats, ['%d', '%s']));
            }
        }

        // DEV ONLY — не виконувати на продакшні: створює фейкові ліди і РЕАЛЬНО списує баланси партнерів.
        /**
         * Згенерувати N демо-лідів і прогнати кожен через повний dispatch-цикл.
         *
         * Для кожного ліда викликається LeadRouter_Flow::dispatch_broadcast() з тими ж
         * опціями, що й cron нових лідів — тож спрацьовує:
         *   • weighted-розподіл по групах (70/30 у тестовому середовищі);
         *   • доставка партнеру через email (→ MailHog/Mailpit);
         *   • хук leadrouter_after_send → LR_Billing::deduct_for_lead (realtime-списання).
         *
         * Імена лідів — DemoLead_001, DemoLead_002, ... (навмисно НЕ на "test",
         * бо ліди з префіксом "test" пропускаються без відправки).
         *
         * Увага: списання реальне й кумулятивне між запусками. Щоб скинути баланси —
         * перезапустіть `wp leadrouter billing-test-setup`.
         *
         * ## OPTIONS
         * [--count=<n>]
         * : Скільки демо-лідів згенерувати й відправити (default 10).
         *
         * ## EXAMPLES
         *   wp leadrouter billing-test-send --count=20 --allow-root
         */
        public function billing_test_send( $args, $assoc ) {
            global $wpdb;

            $count = isset($assoc['count']) ? max(1, (int)$assoc['count']) : 10;

            $t_leads = $wpdb->prefix . 'leadrouter_leads';
            $t_tx    = $wpdb->prefix . 'leadrouter_billing_transactions';
            $now_est = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m-d H:i:s');
            $ship    = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->modify('+7 days')->format('Y-m-d');

            // 1) Генеруємо N демо-лідів зі статусом new
            $lead_ids   = [];
            $lead_names = [];
            for ($i = 1; $i <= $count; $i++) {
                $name = sprintf('DemoLead_%03d', $i);

                $wpdb->insert($t_leads, [
                    'name'              => $name,
                    'email'             => sprintf('demolead%03d@demo.local', $i),
                    'phone'             => '305' . str_pad((string)wp_rand(1000000, 9999999), 7, '0', STR_PAD_LEFT),
                    'est_ship_date'     => $ship,
                    'vehicle_year'      => 2020,
                    'vehicle_brand'     => 'Toyota',
                    'vehicle_model'     => 'Camry',
                    'vehicle_condition' => 'Running',
                    'from_city'         => 'Miami',  'from_state' => 'FL', 'from_zip' => '33101',
                    'to_city'           => 'Dallas', 'to_state'   => 'TX', 'to_zip'   => '75201',
                    'created_at'        => $now_est,
                    'status'            => 'new',
                    'response_status'   => 'new',
                    'dispatch_method'   => 'cli_billing_test',
                ], [
                    '%s', '%s', '%s', '%s',
                    '%d', '%s', '%s', '%s',
                    '%s', '%s', '%s',
                    '%s', '%s', '%s',
                    '%s', '%s', '%s', '%s',
                ]);

                $lid = (int)$wpdb->insert_id;
                if ($lid > 0) {
                    $lead_ids[]        = $lid;
                    $lead_names[$lid]  = $name;
                }
            }

            if (empty($lead_ids)) {
                WP_CLI::error('Не вдалося створити жодного демо-ліда.');
            }
            WP_CLI::log(sprintf('Створено демо-лідів: %d', count($lead_ids)));
            WP_CLI::log('');

            // 2) Прогін кожного ліда через повний dispatch (як cron нових лідів)
            $detail_rows = [];     // рядки детальної таблиці
            $by_group    = [];     // group_name => к-сть лідів
            $by_partner  = [];     // partner_id => ['name','leads','charged']

            foreach ($lead_ids as $lid) {
                $res = LeadRouter_Flow::dispatch_broadcast($lid, [
                    'group_meta_key'  => '_leadrouter_partner_group',
                    'statuses'        => ['queued', 'sent', 'accepted'],
                    'initial_status'  => 'sent',
                    'dispatch_method' => 'auto_cron_new_lead',
                    'queue_if_closed' => true,
                ]);

                // Лід не вдалося розподілити (немає групи/партнерів тощо)
                if (is_wp_error($res)) {
                    $detail_rows[] = [
                        'Лід'          => $lead_names[$lid],
                        'Група'        => '—',
                        'Партнер'      => '—',
                        'Списано'      => '—',
                        'Баланс після' => '—',
                        'Примітка'     => $res->get_error_code(),
                    ];
                    continue;
                }

                $gname = (string)($res['group_name'] ?? '—');
                $by_group[$gname] = ($by_group[$gname] ?? 0) + 1;

                $sent = is_array($res['sent'] ?? null) ? $res['sent'] : [];
                if (empty($sent)) {
                    $detail_rows[] = [
                        'Лід'          => $lead_names[$lid],
                        'Група'        => $gname,
                        'Партнер'      => '—',
                        'Списано'      => '—',
                        'Баланс після' => '—',
                        'Примітка'     => 'no_sent',
                    ];
                    continue;
                }

                // Лід міг піти кільком партнерам групи (broadcast) — рядок на кожного
                foreach ($sent as $s) {
                    $pid   = (int)($s['partner_id'] ?? 0);
                    $pname = get_the_title($pid) ?: ('Partner #' . $pid);

                    // Дістаємо саме транзакцію списання за цей (лід, партнер)
                    $tx = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT amount, balance_after FROM {$t_tx}
                             WHERE partner_id = %d AND lead_id = %d AND type = 'spend' LIMIT 1",
                            $pid, $lid
                        ),
                        ARRAY_A
                    );

                    if ($tx) {
                        $charged = abs((float)$tx['amount']);
                        $balance = (float)$tx['balance_after'];
                        $note    = $balance < 0 ? 'НЕГАТИВНИЙ' : '';
                    } else {
                        // Списання не відбулось (немає білінг-профілю / дубль) — показуємо поточний баланс
                        $charged = 0.0;
                        $balance = LR_Billing::get_balance($pid);
                        $note    = 'no_charge';
                    }

                    $detail_rows[] = [
                        'Лід'          => $lead_names[$lid],
                        'Група'        => $gname,
                        'Партнер'      => $pname . ' (#' . $pid . ')',
                        'Списано'      => number_format($charged, 2),
                        'Баланс після' => number_format($balance, 2),
                        'Примітка'     => $note,
                    ];

                    if (!isset($by_partner[$pid])) {
                        $by_partner[$pid] = ['name' => $pname, 'leads' => 0, 'charged' => 0.0];
                    }
                    $by_partner[$pid]['leads']   += 1;
                    $by_partner[$pid]['charged'] += $charged;
                }
            }

            // 3) Детальна таблиця: лід → партнер → списано → баланс
            WP_CLI::log('=== Деталізація відправок ===');
            WP_CLI\Utils\format_items(
                'table',
                $detail_rows,
                ['Лід', 'Група', 'Партнер', 'Списано', 'Баланс після', 'Примітка']
            );

            // 4) Підсумок розподілу по групах (перевірка 70/30)
            $total_leads = count($lead_ids);
            $group_rows  = [];
            ksort($by_group);
            foreach ($by_group as $gname => $cnt) {
                $group_rows[] = [
                    'Група'    => $gname,
                    'Лідів'    => $cnt,
                    'Частка %' => $total_leads > 0 ? number_format($cnt * 100 / $total_leads, 1) : '0.0',
                ];
            }
            WP_CLI::log('');
            WP_CLI::log('=== Розподіл по групах (очікується ~70/30) ===');
            WP_CLI\Utils\format_items('table', $group_rows, ['Група', 'Лідів', 'Частка %']);

            // 5) Підсумок по партнерах: скільки лідів і скільки списано
            $partner_rows = [];
            ksort($by_partner);
            foreach ($by_partner as $pid => $info) {
                $partner_rows[] = [
                    'Партнер'         => $info['name'] . ' (#' . $pid . ')',
                    'Лідів'           => $info['leads'],
                    'Списано всього'  => number_format($info['charged'], 2),
                    'Поточний баланс' => number_format(LR_Billing::get_balance($pid), 2),
                ];
            }
            WP_CLI::log('');
            WP_CLI::log('=== Підсумок по партнерах ===');
            WP_CLI\Utils\format_items('table', $partner_rows, ['Партнер', 'Лідів', 'Списано всього', 'Поточний баланс']);

            WP_CLI::success(sprintf(
                'Готово: %d лідів прогнано через dispatch. Листи — у MailHog (http://localhost:8025).',
                $total_leads
            ));
        }

        // DEV ONLY — не виконувати на продакшні: запускає білінг-крон і РЕАЛЬНИЙ Stripe auto-charge.
        /**
         * Викликати білінг-крон (LR_Billing_Cron::run) напряму, синхронно,
         * без спавну WP-Cron. До і після — стан кожного DEMO-партнера,
         * щоб було видно, що саме крон змінив.
         *
         * Крон обходить усіх активних білінг-партнерів і за балансом:
         *   • реактивує (deactivated_by_billing → 0, active → 1), якщо баланс відновився;
         *   • деактивує (deactivated_by_billing → 1, active → 0), якщо не вистачає на лід
         *     і не дозволено від'ємний баланс;
         *   • виставляє прапорці notified_* і шле відповідні листи (→ MailHog).
         *
         * ## EXAMPLES
         *   wp leadrouter billing-test-cron --allow-root
         */
        public function billing_test_cron( $args, $assoc ) {
            global $wpdb;

            // DEMO-партнери (пости)
            $pids = array_map('intval', $wpdb->get_col(
                "SELECT ID FROM {$wpdb->posts}
                  WHERE post_type = 'leadrouter_partner'
                    AND post_title LIKE 'DEMO Partner%'
                    AND post_status = 'publish'
                  ORDER BY ID ASC"
            ));

            if (empty($pids)) {
                WP_CLI::error('DEMO-партнерів не знайдено. Спершу: wp leadrouter billing-test-setup');
            }

            // 1) Знімок стану ДО
            $before = self::demo_billing_state($pids);

            // 2) Синхронний виклик воркера.
            // Знімаємо глобальний лок, щоб тік гарантовано відпрацював (а не вийшов
            // одразу через активний транзієнт від попереднього/паралельного тіку).
            delete_transient(LR_Billing_Cron::LOCK_KEY);

            WP_CLI::log('Виклик LR_Billing_Cron::run() (синхронно) ...');
            LR_Billing_Cron::run();
            WP_CLI::log('');

            // 3) Знімок стану ПІСЛЯ
            $after = self::demo_billing_state($pids);

            // 4) Порівняльна таблиця «до → після» (стрілка лише там, де змінилось)
            $fmt = static function ($b, $a, bool $money = false): string {
                if ($b === null && $a === null) return '—';
                $bs = $money ? number_format((float)$b, 2) : (string)$b;
                $as = $money ? number_format((float)$a, 2) : (string)$a;
                return ($bs === $as) ? $bs : ($bs . ' → ' . $as);
            };

            $rows = [];
            foreach ($pids as $pid) {
                $b = $before[$pid];
                $a = $after[$pid];
                $rows[] = [
                    'Партнер'                  => $a['name'] . ' (#' . $pid . ')',
                    'active'                   => $fmt($b['active'], $a['active']),
                    'deactivated_by_billing'   => $fmt($b['deactivated'], $a['deactivated']),
                    'balance'                  => $fmt($b['balance'], $a['balance'], true),
                    'notified_low'             => $fmt($b['n_low'], $a['n_low']),
                    'notified_stopped'         => $fmt($b['n_stop'], $a['n_stop']),
                    'notified_admin_negative'  => $fmt($b['n_admin'], $a['n_admin']),
                ];
            }

            WP_CLI::log('=== Стан DEMO-партнерів: до → після LR_Billing_Cron::run() ===');
            WP_CLI\Utils\format_items('table', $rows, [
                'Партнер', 'active', 'deactivated_by_billing', 'balance',
                'notified_low', 'notified_stopped', 'notified_admin_negative',
            ]);

            WP_CLI::success('Крон виконано. Якщо змінились прапорці notified_* — листи пішли у MailHog (http://localhost:8025).');
        }

        // DEV ONLY — не виконувати на продакшні: робить РЕАЛЬНІ Stripe charge-запити (списує картку партнера).
        /**
         * ТИМЧАСОВА команда: перевірка idempotency-захисту LR_Stripe::charge().
         *
         * Викликає charge() двічі підряд для одного партнера в межах одного 120s-вікна
         * (CYCLE_WINDOW). Очікувано: idempotency_key збігається, тож перший виклик —
         * succeeded (реальний PaymentIntent), другий — duplicate (без нового PI/списання).
         * Якщо succeeded-рядок з тим ключем уже існував (напр. крон щойно списав) —
         * обидва виклики повернуть duplicate; це теж валідний доказ захисту.
         *
         * Важливо: charge() працює на рівні Stripe і НЕ чіпає balance у partner_billing
         * (поповнення робить крон). Тому «подвійне списання» тут = другий PaymentIntent
         * або другий succeeded-рядок у leadrouter_stripe_payments. Перевіряємо, що його немає.
         *
         * ## OPTIONS
         * [--partner=<id>]
         * : ID партнера (CPT leadrouter_partner) з валідними stripe_customer_id + pm.
         *
         * ## EXAMPLES
         *   wp leadrouter stripe-test-duplicate --partner=21509 --allow-root
         */
        public function stripe_test_duplicate( $args, $assoc ) {
            global $wpdb;

            $partner_id = isset($assoc['partner']) ? (int)$assoc['partner'] : 0;
            if ($partner_id <= 0) {
                WP_CLI::error('Вкажіть --partner=<id>. Напр.: wp leadrouter stripe-test-duplicate --partner=21509');
            }
            if (!class_exists('LR_Stripe') || !method_exists('LR_Stripe', 'charge')) {
                WP_CLI::error('LR_Stripe недоступний — перевір require у leadrouter.php.');
            }

            $t_billing = $wpdb->prefix . 'leadrouter_partner_billing';
            $t_pay     = $wpdb->prefix . 'leadrouter_stripe_payments';

            $profile = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT stripe_customer_id, stripe_payment_method_id, auto_charge_amount, currency
                       FROM {$t_billing} WHERE partner_id = %d LIMIT 1",
                    $partner_id
                ),
                ARRAY_A
            );
            if (!$profile) {
                WP_CLI::error(sprintf('У партнера #%d немає білінг-профілю.', $partner_id));
            }

            $name = get_the_title($partner_id) ?: ('Partner #' . $partner_id);
            $has_method = trim((string)$profile['stripe_customer_id']) !== ''
                       && trim((string)$profile['stripe_payment_method_id']) !== '';

            WP_CLI::log(sprintf('Партнер: %s (#%d)', $name, $partner_id));
            WP_CLI::log(sprintf('  customer: %s', $profile['stripe_customer_id'] ?: '—'));
            WP_CLI::log(sprintf('  pm:       %s', $profile['stripe_payment_method_id'] ?: '—'));
            WP_CLI::log(sprintf('  amount:   %s %s',
                number_format((float)$profile['auto_charge_amount'], 2),
                strtoupper((string)$profile['currency'])));
            WP_CLI::log(sprintf('  idempotency window: %ds (CYCLE_WINDOW)', LR_Stripe::CYCLE_WINDOW));
            if (!$has_method) {
                WP_CLI::warning('У партнера не налаштований customer/pm — обидва виклики дадуть error, не duplicate.');
            }
            WP_CLI::log('');

            // Скільки succeeded-рядків у партнера ДО тесту (контроль «без подвійного»)
            $succ_before = (int)$wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$t_pay} WHERE partner_id = %d AND status = 'succeeded'", $partner_id)
            );

            // === Два виклики підряд ===
            WP_CLI::log('Виклик #1: LR_Stripe::charge() ...');
            $r1 = LR_Stripe::charge($partner_id);
            WP_CLI::log(sprintf('  -> status=%s  charge_id=%s', $r1['status'] ?? '?', $r1['charge_id'] ?? '—'));

            WP_CLI::log('Виклик #2: LR_Stripe::charge() ...');
            $r2 = LR_Stripe::charge($partner_id);
            WP_CLI::log(sprintf('  -> status=%s  charge_id=%s', $r2['status'] ?? '?', $r2['charge_id'] ?? '—'));
            WP_CLI::log('');

            // === Стан leadrouter_stripe_payments по найсвіжішому ключу charge_<pid>_* ===
            $like = $wpdb->esc_like('charge_' . $partner_id . '_') . '%';
            $latest_key = (string)$wpdb->get_var(
                $wpdb->prepare(
                    "SELECT idempotency_key FROM {$t_pay}
                      WHERE partner_id = %d AND idempotency_key LIKE %s
                      ORDER BY id DESC LIMIT 1",
                    $partner_id, $like
                )
            );

            $key_rows = $latest_key !== '' ? $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, status, stripe_payment_intent_id, amount, created_at
                       FROM {$t_pay} WHERE idempotency_key = %s ORDER BY id ASC",
                    $latest_key
                ),
                ARRAY_A
            ) : [];

            WP_CLI::log(sprintf('idempotency_key (останній): %s', $latest_key !== '' ? $latest_key : '—'));
            WP_CLI::log(sprintf('Рядків stripe_payments з цим ключем: %d (UNIQUE → очікуємо 1)', count($key_rows)));
            if (!empty($key_rows)) {
                $disp = [];
                foreach ($key_rows as $kr) {
                    $disp[] = [
                        'id'         => $kr['id'],
                        'status'     => $kr['status'],
                        'pi'         => $kr['stripe_payment_intent_id'],
                        'amount'     => number_format((float)$kr['amount'], 2),
                        'created_at' => $kr['created_at'],
                    ];
                }
                WP_CLI\Utils\format_items('table', $disp, ['id', 'status', 'pi', 'amount', 'created_at']);
            }

            $succ_after = (int)$wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$t_pay} WHERE partner_id = %d AND status = 'succeeded'", $partner_id)
            );
            WP_CLI::log('');

            // === Вердикт ===
            $s1  = (string)($r1['status'] ?? '');
            $s2  = (string)($r2['status'] ?? '');
            $pi1 = (string)($r1['charge_id'] ?? '');
            $pi2 = (string)($r2['charge_id'] ?? '');

            $first_ok    = in_array($s1, ['succeeded', 'duplicate'], true);
            $second_dup  = ($s2 === 'duplicate');
            $one_row     = (count($key_rows) === 1);
            $no_dbl_succ = (($succ_after - $succ_before) <= 1);
            $same_pi     = ($pi1 !== '' && $pi1 === $pi2);

            WP_CLI::log('=== Перевірки ===');
            WP_CLI::log(sprintf('  [%s] перший виклик succeeded|duplicate (отримано: %s)', $first_ok ? 'PASS' : 'FAIL', $s1));
            WP_CLI::log(sprintf('  [%s] другий виклик = duplicate (отримано: %s)', $second_dup ? 'PASS' : 'FAIL', $s2));
            WP_CLI::log(sprintf('  [%s] один рядок на idempotency_key (UNIQUE upsert)', $one_row ? 'PASS' : 'FAIL'));
            WP_CLI::log(sprintf('  [%s] без зайвого succeeded (+%d за тест)', $no_dbl_succ ? 'PASS' : 'FAIL', $succ_after - $succ_before));
            if ($same_pi) {
                WP_CLI::log('  [INFO] обидва виклики посилаються на той самий PaymentIntent: ' . $pi1);
            }
            WP_CLI::log('');

            if ($first_ok && $second_dup && $one_row && $no_dbl_succ) {
                WP_CLI::success('Idempotency-захист працює: подвійного списання немає, другий charge → duplicate.');
            } else {
                WP_CLI::warning('Перевірка не пройшла повністю — дивись таблицю/статуси вище.');
            }
        }

        // DEV ONLY — не виконувати на продакшні: інжектить фейкові Stripe-події і РЕАЛЬНО змінює баланси/транзакції.
        /**
         * ТИМЧАСОВА команда: симуляція вхідного Stripe-вебхука через LR_Stripe_Webhook.
         *
         * Генерує реалістичний фейковий event, підписує його поточним
         * lr_stripe_webhook_secret (HMAC-SHA256, формат t=..,v1=..) і викликає
         * LR_Stripe_Webhook::handle() напряму з фейковим WP_REST_Request.
         * Друкує HTTP-код, що сталося, баланс до/після і дельти транзакцій/подій.
         *
         * Якщо webhook secret не заданий — на час виклику ставимо тимчасовий
         * (реальне значення не чіпаємо) і прибираємо його в кінці.
         *
         * ## OPTIONS
         * [--event=<kind>]
         * : succeeded | failed | refund | dispute (default succeeded).
         *
         * [--partner=<id>]
         * : ID партнера (для metadata.partner_id і показу балансу).
         *
         * [--pi=<pi_id>]
         * : Використати конкретний PaymentIntent (для сценарію «вже-succeeded PI»).
         *
         * [--event-id=<evt_id>]
         * : Зафіксувати event_id (двічі з тим самим → перевірка idempotency).
         *
         * [--amount=<usd>]
         * : Сума в доларах (succeeded/refund/dispute). Default — auto_charge_amount або 50.
         *
         * [--bad-sig]
         * : Надіслати невалідний підпис (перевірка 400).
         *
         * ## EXAMPLES
         *   wp leadrouter stripe-test-webhook --event=succeeded --partner=21509 --allow-root
         *   wp leadrouter stripe-test-webhook --event=succeeded --partner=21509 --pi=pi_xxx --allow-root
         *   wp leadrouter stripe-test-webhook --event=refund --partner=21509 --amount=25 --allow-root
         *   wp leadrouter stripe-test-webhook --event=dispute --partner=21509 --allow-root
         *   wp leadrouter stripe-test-webhook --event=succeeded --partner=21509 --bad-sig --allow-root
         */
        public function stripe_test_webhook( $args, $assoc ) {
            global $wpdb;

            $kind       = isset($assoc['event']) ? strtolower((string)$assoc['event']) : 'succeeded';
            $partner_id = isset($assoc['partner']) ? (int)$assoc['partner'] : 0;
            $pi_over    = isset($assoc['pi']) ? (string)$assoc['pi'] : '';
            $evt_over   = isset($assoc['event-id']) ? (string)$assoc['event-id'] : '';
            $amount_opt = isset($assoc['amount']) ? (float)$assoc['amount'] : null;
            $bad_sig    = isset($assoc['bad-sig']);

            $map = [
                'succeeded' => 'payment_intent.succeeded',
                'failed'    => 'payment_intent.payment_failed',
                'refund'    => 'charge.refunded',
                'dispute'   => 'charge.dispute.created',
            ];
            if (!isset($map[$kind])) {
                WP_CLI::error('Невідомий --event. Допустимі: succeeded | failed | refund | dispute');
            }
            if (!class_exists('LR_Stripe_Webhook')) {
                WP_CLI::error('LR_Stripe_Webhook недоступний — перевір require у leadrouter.php.');
            }
            if ($partner_id <= 0) {
                WP_CLI::error('Вкажіть --partner=<id>.');
            }

            $t_billing = $wpdb->prefix . 'leadrouter_partner_billing';
            $t_tx      = $wpdb->prefix . 'leadrouter_billing_transactions';
            $t_events  = $wpdb->prefix . 'leadrouter_stripe_events';
            $t_errors  = $wpdb->prefix . 'leadrouter_billing_errors';
            $t_pay     = $wpdb->prefix . 'leadrouter_stripe_payments';

            $profile = $wpdb->get_row(
                $wpdb->prepare("SELECT auto_charge_amount, currency FROM {$t_billing} WHERE partner_id = %d LIMIT 1", $partner_id),
                ARRAY_A
            );
            if (!$profile) {
                WP_CLI::error(sprintf('У партнера #%d немає білінг-профілю.', $partner_id));
            }

            $currency   = strtolower((string)($profile['currency'] ?? 'usd')) ?: 'usd';
            $amount_usd = $amount_opt !== null ? $amount_opt : ((float)$profile['auto_charge_amount'] ?: 50.0);
            $cents      = (int) round($amount_usd * 100);

            // Для refund/dispute об'єкт події — charge/dispute (БЕЗ metadata партнера),
            // тож беремо реальні PI/charge партнера, щоб спрацював DB-пошук у resolve_partner_id.
            $srow = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT stripe_payment_intent_id, stripe_charge_id FROM {$t_pay}
                      WHERE partner_id = %d AND status = 'succeeded' AND stripe_payment_intent_id <> ''
                      ORDER BY id DESC LIMIT 1",
                    $partner_id
                ),
                ARRAY_A
            );

            $rt = static function (string $pfx) { return $pfx . strtolower(wp_generate_password(20, false)); };

            // === Збірка фейкового event ===
            $type = $map[$kind];
            if ($kind === 'succeeded') {
                $pi = $pi_over !== '' ? $pi_over : $rt('pi_test_');
                $ch = $rt('ch_test_');
                $obj = [
                    'object'          => 'payment_intent',
                    'id'              => $pi,
                    'status'          => 'succeeded',
                    'amount'          => $cents,
                    'amount_received' => $cents,
                    'currency'        => $currency,
                    'latest_charge'   => $ch,
                    'metadata'        => ['partner_id' => (string)$partner_id],
                ];
            } elseif ($kind === 'failed') {
                $pi = $pi_over !== '' ? $pi_over : $rt('pi_test_');
                $obj = [
                    'object'   => 'payment_intent',
                    'id'       => $pi,
                    'status'   => 'requires_payment_method',
                    'amount'   => $cents,
                    'currency' => $currency,
                    'last_payment_error' => [
                        'type'    => 'card_error',
                        'code'    => 'card_declined',
                        'message' => 'Your card was declined.',
                    ],
                    'metadata' => ['partner_id' => (string)$partner_id],
                ];
            } elseif ($kind === 'refund') {
                $pi = $pi_over !== '' ? $pi_over : ($srow['stripe_payment_intent_id'] ?? $rt('pi_test_'));
                $ch = $srow['stripe_charge_id'] ?: $rt('ch_test_');
                $re = $rt('re_test_');
                $obj = [
                    'object'          => 'charge',
                    'id'              => $ch,
                    'payment_intent'  => $pi,
                    'amount_refunded' => $cents,
                    'refunds'         => [
                        'object' => 'list',
                        'data'   => [[
                            'id'     => $re,
                            'object' => 'refund',
                            'amount' => $cents,
                            'status' => 'succeeded',
                        ]],
                    ],
                ];
            } else { // dispute
                $pi = $pi_over !== '' ? $pi_over : ($srow['stripe_payment_intent_id'] ?? $rt('pi_test_'));
                $ch = $srow['stripe_charge_id'] ?: $rt('ch_test_');
                $obj = [
                    'object'         => 'dispute',
                    'id'             => $rt('dp_test_'),
                    'charge'         => $ch,
                    'payment_intent' => $pi,
                    'amount'         => $cents,
                    'reason'         => 'fraudulent',
                    'status'         => 'needs_response',
                ];
            }

            $event_id = $evt_over !== '' ? $evt_over : ('evt_test_' . $kind . '_' . time() . '_' . substr($rt(''), 0, 6));
            $event = [
                'id'      => $event_id,
                'object'  => 'event',
                'type'    => $type,
                'created' => time(),
                'data'    => ['object' => $obj],
            ];
            $payload = wp_json_encode($event);

            // === Секрет для підпису (з фолбеком на тимчасовий) ===
            $effective = self::wh_secret();
            $temp_set  = false;
            $sign_secret = $effective;
            if ($effective === '' && !$bad_sig) {
                $sign_secret = 'whsec_tmp_' . wp_generate_password(24, false);
                update_option('lr_stripe_webhook_secret', $sign_secret);
                $temp_set = true;
            }

            if ($bad_sig) {
                $sig_header = 't=' . time() . ',v1=' . str_repeat('dead', 16);
                $sig_mode   = 'BAD (навмисно невалідний)';
            } else {
                $ts = time();
                $sig_header = 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $payload, $sign_secret);
                $sig_mode   = $temp_set ? 'valid (тимчасовий secret)' : 'valid (поточний secret)';
            }

            // === Знімок ДО ===
            $bal_before = (float)$wpdb->get_var($wpdb->prepare("SELECT balance FROM {$t_billing} WHERE partner_id = %d", $partner_id));
            $tx_before  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t_tx} WHERE partner_id = %d", $partner_id));
            $err_before = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t_errors} WHERE partner_id = %d", $partner_id));

            WP_CLI::log('=== Stripe webhook simulation ===');
            WP_CLI::log(sprintf('  event:     %s  (--event=%s)', $type, $kind));
            WP_CLI::log(sprintf('  event_id:  %s', $event_id));
            WP_CLI::log(sprintf('  partner:   #%d', $partner_id));
            WP_CLI::log(sprintf('  PI/charge: %s / %s', $obj['id'] ?? ($pi ?? '—'), $obj['payment_intent'] ?? ($obj['latest_charge'] ?? '—')));
            WP_CLI::log(sprintf('  amount:    %s %s', number_format($amount_usd, 2), strtoupper($currency)));
            WP_CLI::log(sprintf('  signature: %s', $sig_mode));
            WP_CLI::log('');

            // === Виклик хендлера ===
            $req = new WP_REST_Request('POST', '/leadrouter/v1/stripe-webhook');
            $req->set_body($payload);
            $req->set_header('Stripe-Signature', $sig_header);

            try {
                $resp = LR_Stripe_Webhook::handle($req);
                $code = (int)$resp->get_status();
                $data = $resp->get_data();
            } finally {
                // Прибрати тимчасовий secret, якщо ставили
                if ($temp_set) {
                    delete_option('lr_stripe_webhook_secret');
                }
            }

            // === Знімок ПІСЛЯ ===
            $bal_after = (float)$wpdb->get_var($wpdb->prepare("SELECT balance FROM {$t_billing} WHERE partner_id = %d", $partner_id));
            $tx_after  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t_tx} WHERE partner_id = %d", $partner_id));
            $err_after = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t_errors} WHERE partner_id = %d", $partner_id));

            $ev_row = $wpdb->get_row(
                $wpdb->prepare("SELECT status, processed_at FROM {$t_events} WHERE stripe_event_id = %s LIMIT 1", $event_id),
                ARRAY_A
            );

            // === Результат ===
            WP_CLI::log(sprintf('HTTP %d  →  %s', $code, wp_json_encode($data)));
            WP_CLI::log(sprintf('stripe_events:  %s', $ev_row ? ('status=' . $ev_row['status'] . ', processed_at=' . ($ev_row['processed_at'] ?: '—')) : '— (рядок не створено)'));
            WP_CLI::log(sprintf('balance:        %s → %s  (Δ %+0.2f)', number_format($bal_before, 2), number_format($bal_after, 2), $bal_after - $bal_before));
            WP_CLI::log(sprintf('transactions:   +%d (partner #%d)', $tx_after - $tx_before, $partner_id));
            WP_CLI::log(sprintf('billing_errors: +%d', $err_after - $err_before));

            // Деталі останньої транзакції/помилки, якщо з'явились
            if ($tx_after > $tx_before) {
                $last_tx = $wpdb->get_row(
                    $wpdb->prepare("SELECT type, amount, balance_after FROM {$t_tx} WHERE partner_id = %d ORDER BY id DESC LIMIT 1", $partner_id),
                    ARRAY_A
                );
                if ($last_tx) {
                    WP_CLI::log(sprintf('  └ остання tx: type=%s amount=%s balance_after=%s',
                        $last_tx['type'], number_format((float)$last_tx['amount'], 2), number_format((float)$last_tx['balance_after'], 2)));
                }
            }
            if ($err_after > $err_before) {
                $last_err = $wpdb->get_row(
                    $wpdb->prepare("SELECT error_code, severity FROM {$t_errors} WHERE partner_id = %d ORDER BY id DESC LIMIT 1", $partner_id),
                    ARRAY_A
                );
                if ($last_err) {
                    WP_CLI::log(sprintf('  └ остання error: %s [%s]', $last_err['error_code'], $last_err['severity']));
                }
            }
            WP_CLI::log('');

            // Підказка-вердикт за сценарієм
            if ($bad_sig) {
                ($code === 400) ? WP_CLI::success('Поганий підпис відхилено (400).')
                                : WP_CLI::warning('Очікувався 400 на поганий підпис.');
            } elseif ($ev_row && $ev_row['status'] === 'received' && is_array($data) && !empty($data['idempotent'])) {
                WP_CLI::success('Подію вже бачили — idempotent (повторно не оброблено).');
            } else {
                WP_CLI::success(sprintf('Оброблено (event status=%s).', $ev_row['status'] ?? '?'));
            }
        }

        /** Поточний webhook secret тим самим шляхом, що й LR_Stripe (carbon → get_option) */
        private static function wh_secret(): string {
            if (function_exists('carbon_get_theme_option')) {
                $v = carbon_get_theme_option('lr_stripe_webhook_secret');
                if ($v !== null && $v !== '') {
                    return (string)$v;
                }
            }
            $v = get_option('lr_stripe_webhook_secret');
            return ($v !== false && $v !== null) ? (string)$v : '';
        }

        /**
         * Знімок білінг-стану партнерів: active-meta + ключові поля білінг-профілю.
         * @return array<int,array> map partner_id => state
         */
        private static function demo_billing_state(array $pids): array {
            global $wpdb;
            $t = $wpdb->prefix . 'leadrouter_partner_billing';

            $out = [];
            foreach ($pids as $pid) {
                $pid = (int)$pid;
                $row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT balance, deactivated_by_billing,
                                notified_low_balance, notified_stopped, notified_admin_negative
                           FROM {$t} WHERE partner_id = %d LIMIT 1",
                        $pid
                    ),
                    ARRAY_A
                );

                $out[$pid] = [
                    'name'        => get_the_title($pid) ?: ('Partner #' . $pid),
                    'active'      => (string) get_post_meta($pid, '_leadrouter_partner_active', true),
                    'balance'     => $row ? (float)$row['balance'] : null,
                    'deactivated' => $row ? (int)$row['deactivated_by_billing'] : null,
                    'n_low'       => $row ? (int)$row['notified_low_balance'] : null,
                    'n_stop'      => $row ? (int)$row['notified_stopped'] : null,
                    'n_admin'     => $row ? (int)$row['notified_admin_negative'] : null,
                ];
            }
            return $out;
        }

        // ===== helpers =====

        private static function effective_weight_today(array $row, int $dow) : int {
            $base = isset($row['weight']) ? (int)$row['weight'] : 0;
            $key  = 'weight_' . $dow;
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return max(0, (int)$row[$key]);
            }
            return max(0, $base);
        }

        private static function weighted_pick(array $items, int $totalW) : ?array {
            $r = wp_rand(1, max(1, (int)$totalW));
            $c = 0;
            foreach ($items as $it) {
                $c += (int)$it['weight'];
                if ($r <= $c) return $it;
            }
            return end($items) ?: null;
        }
    }

    /*
     * Реєстрація WP-CLI команд.
     *
     * ВАЖЛИВО: реєструємо команди ЯВНО (а не цілий клас через
     * add_command('leadrouter', 'LeadRouter_CLI')). Catch-all авто-експонував би
     * УСІ публічні методи класу — зокрема тестові — як підкоманди, і їх не можна
     * було б приховати на продакшні. Явна реєстрація дає повний контроль.
     *
     * LEADROUTER_PRODUCTION — прапорець бойового середовища. Та сама логіка, що
     * ховає Seed/Purge кнопки в адмінці (leadrouter.php). Ставиться у wp-config.php
     * бойового сайту (файл НЕ в git):
     *
     *     define('LEADROUTER_PRODUCTION', true);
     *
     * Коли він true — тестові команди нижче ВЗАГАЛІ не реєструються, тож WP-CLI
     * про них не знає («'leadrouter billing-test-setup' is not a registered command»).
     */

    // ── Бойові команди (read-only симуляція розподілу) — реєструються ЗАВЖДИ ──
    WP_CLI::add_command('leadrouter simulate-proportion', ['LeadRouter_CLI', 'simulate_proportion']);
    WP_CLI::add_command('leadrouter simulate-capacity',   ['LeadRouter_CLI', 'simulate_capacity']);

    // ── DEV ONLY: тестові команди (фейкові дані / реальні Stripe-запити) ──
    // На продакшні (LEADROUTER_PRODUCTION === true) НЕ реєструються.
    if (!(defined('LEADROUTER_PRODUCTION') && LEADROUTER_PRODUCTION)) {
        WP_CLI::add_command('leadrouter billing-test-setup',    ['LeadRouter_CLI', 'billing_test_setup']);
        WP_CLI::add_command('leadrouter billing-test-send',     ['LeadRouter_CLI', 'billing_test_send']);
        WP_CLI::add_command('leadrouter billing-test-cron',     ['LeadRouter_CLI', 'billing_test_cron']);
        WP_CLI::add_command('leadrouter stripe-test-duplicate', ['LeadRouter_CLI', 'stripe_test_duplicate']);
        WP_CLI::add_command('leadrouter stripe-test-webhook',   ['LeadRouter_CLI', 'stripe_test_webhook']);
    }
}


