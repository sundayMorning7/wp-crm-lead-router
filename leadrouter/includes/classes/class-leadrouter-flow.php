<?php
if (!defined('ABSPATH')) exit;

class LeadRouter_Flow
{
    /* ============================================================
     * 🧭 Константи — базові налаштування
     * ============================================================ */
    private const CONSUME_STATUSES = ['queued', 'sent', 'accepted']; // Доступні статуси партнерів для розсилки
    private const DEFAULT_META_GROUP = '_leadrouter_partner_group';  // Ключ мети для групи партнерів
    private const EST_TZ = 'America/New_York';                       // Основна таймзона
    private const RETRY_MAX_ATTEMPTS = 3;                            // Макс. кількість спроб повторної відправки
    private const RETRY_BACKOFF_SEC = [0, 2, 5];                     // Затримки між спробами у секундах

    // Статуси, які крони перепризначають щохвилини: пишемо в leadrouter_logs
    // тільки при реальній зміні або раз на LOG_DEDUP_WINDOW_MIN хвилин.
    private const LOG_DEDUP_STATUSES = ['await', 'sent_partial'];
    private const LOG_DEDUP_WINDOW_MIN = 15;

    /* ============================================================
     * 🪵 Логування з ротацією файлів
     * ============================================================ */
    private const LOG_DIR_NAME = 'leadrouter/logs';                  // Папка логів
    private const LOG_FILE_NAME = 'leadrouter.log';                  // Основний файл логів
    private const LOG_MAX_BYTES = 10485760;                          // 10 MB — ліміт розміру логів

    /** Обгортки для зручного виклику логів різних рівнів */
    public static function log_error($msg, $ctx = [])
    {
        self::log('error', $msg, $ctx);
    }

    public static function log_info($msg, $ctx = [])
    {
        self::log('info', $msg, $ctx);
    }

    public static function log_debug($msg, $ctx = [])
    {
        self::log('debug', $msg, $ctx);
    }

    /** Основний логер з ротацією */
    protected static function log(string $level, string $message, array $context = []): void
    {
        try {
            list($file, $dir) = self::log_paths();

            // 🔸 Створюємо папку для логів, якщо її ще нема
            if (!file_exists($dir)) {
                if (!wp_mkdir_p($dir)) {
                    error_log('[LeadRouter][log] cannot create dir: ' . $dir);
                    return;
                }
            }

            // 🔸 Ротація при досягненні ліміту розміру
            if (file_exists($file) && (@filesize($file) >= self::LOG_MAX_BYTES)) {
                $ts = (new DateTimeImmutable('now', new DateTimeZone(self::EST_TZ)))->format('Ymd-His');
                @rename($file, trailingslashit($dir) . 'leadrouter-' . $ts . '.log');
            }

            // 🕒 Додаємо рядок у лог
            $ts = self::now_mysql_est();
            $line = sprintf(
                "%s [%s] %s%s\n",
                $ts,
                strtoupper($level),
                $message,
                $context ? ' | ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
            );
            @file_put_contents($file, $line, FILE_APPEND);

            // 🪝 Глобальний хук, якщо потрібно відправити лог кудись ще (Slack, Telegram тощо)
            do_action('leadrouter_log', $level, $message, $context);
        } catch (\Throwable $e) {
            error_log('[LeadRouter][log] exception: ' . $e->getMessage());
        }
    }

    /** Отримати шлях до файлу та папки логів */
    protected static function log_paths(): array
    {
        $up = wp_upload_dir(null, false);
        $dir = trailingslashit($up['basedir']) . self::LOG_DIR_NAME;
        $file = trailingslashit($dir) . self::LOG_FILE_NAME;
        return [$file, $dir];
    }

    /* ============================================================
     * 📤 Головний метод dispatch_broadcast — відправка ліда партнерам
     * ============================================================ */
    public static function dispatch_broadcast(int $lead_id, array $opts = [])
    {
        global $wpdb;

        $start = microtime(true); // замір часу виконання

        // 🛑 Перевірка валідності lead_id
        if ($lead_id <= 0) {
            self::log_error('dispatch_broadcast: bad lead_id', ['lead_id' => $lead_id]);
            return new WP_Error('leadrouter_flow_bad_lead', 'Некоректний lead_id');
        }

        // 🧩 Перевірка залежностей
        $dep = self::check_dependencies();
        if (is_wp_error($dep)) {
            self::log_error('dispatch_broadcast: dependencies missing', ['error' => $dep->get_error_message()]);
            return $dep;
        }

        do_action('leadrouter_before_dispatch', $lead_id, $opts);


        // 🔎 Дістаємо зі сховища два штати ліда (для фільтрації AK/HI по обох напрямках)
        $lead_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT from_state, to_state FROM {$wpdb->prefix}leadrouter_leads WHERE id = %d",
                $lead_id
            ),
            ARRAY_A
        );
        $lead_from_state = strtoupper(trim((string)($lead_row['from_state'] ?? '')));
        $lead_to_state = strtoupper(trim((string)($lead_row['to_state'] ?? '')));

        $opts['lead_from_state'] = $lead_from_state;
        $opts['lead_to_state'] = $lead_to_state;

        // 🎯 Ручна відправка на КОНКРЕТНОГО партнера: група береться з партнера,
        // WRR/квоти/eff не задіюються. Години й ліміти партнера не блокують
        // (усвідомлений override адміна — попередження показує UI), але
        // неактивному партнеру і повторно тому ж партнеру/власнику — відмова.
        $force_partner_id = (int)($opts['force_partner_id'] ?? 0);
        $forced_partners  = null;

        if ($force_partner_id > 0) {
            $prep = self::prepare_forced_partner($lead_id, $force_partner_id, $opts);
            if (is_wp_error($prep)) {
                // статус ліда не чіпаємо — це ручна дія, лід лишається як був
                self::log_info('force partner rejected', [
                    'lead_id'    => $lead_id,
                    'partner_id' => $force_partner_id,
                    'error'      => $prep->get_error_code(),
                ]);
                do_action('leadrouter_after_dispatch', $lead_id, $opts, $prep);
                return $prep;
            }
            [$group, $forced_partners] = $prep;
        } else {
            // 🔁 Досилка копій shared-ліда: якщо лід уже має призначення у shared-групі,
            // не крутимо WRR наново (і не витрачаємо квоту групи вдруге) — добираємо
            // відсутні копії в тій самій групі.
            $group = self::shared_group_for_topup($lead_id);

            // 🎯 Явно вказана група (ручна відправка адміном або крон помилок)
            // має пріоритет над досилкою: інакше topup мовчки повертав лід у
            // стару shared-групу, і обрана група не отримувала нічого.
            // Якщо ж форс указує САМЕ на групу досилки — лишаємо topup, щоб не
            // прогонити її через WRR і не списати денну квоту групи вдруге.
            $force_group_post_id = (int)($opts['force_group_post_id'] ?? 0);
            if ($group !== null
                && $force_group_post_id > 0
                && (int)$group['group_post_id'] !== $force_group_post_id) {
                self::log_info('topup group overridden by forced group', [
                    'lead_id'             => $lead_id,
                    'topup_group_post_id' => (int)$group['group_post_id'],
                    'force_group_post_id' => $force_group_post_id,
                    'dispatch_method'     => (string)($opts['dispatch_method'] ?? ''),
                ]);
                $group = null;
            }

            // 📦 Отримання групи для цього ліда і тут перерахунок eff
            if ($group === null) {
                $group = self::group_for_lead($lead_id, $opts);
            }
        }


        if (is_wp_error($group)) {
            self::log_error('group_for_lead failed', [
                'lead_id' => $lead_id,
                'error' => $group->get_error_message(),
                'data' => $group->get_error_data()
            ]);

            do_action('leadrouter_after_dispatch', $lead_id, $opts, $group);


            // TODO Дурна логіка відслідковування гаваїв аляски - багато дублюючих перевірок, треба переробити архітектуру


            $from_state = strtoupper(trim((string)($opts['lead_from_state'] ?? '')));
            $to_state = strtoupper(trim((string)($opts['lead_to_state'] ?? '')));
            $excluded = ['AK', 'HI'];
            $isExcludedState = in_array($from_state, $excluded, true) || in_array($to_state, $excluded, true);


            // КРОК 6. Оновлення eff — тільки якщо НЕ AK/HI
            if (!$isExcludedState) {
                self::mark_lead_status($lead_id, 'await', [
                    'reason' => 'no_group_for_lead',
                    'error_msg' => $group->get_error_message(),
                ]);
            } else {
                self::mark_lead_status($lead_id, 'state_error', [
                    'reason' => 'state_filter_fail',
                    'error_msg' => $group->get_error_message(),
                ]);
            }


            return $group;
        }

        $group_post_id = (int)$group['group_post_id'];
        $group_name = (string)($group['name'] ?? (get_the_title($group_post_id) ?: "Group #{$group_post_id}"));


        // 🧭 Отримуємо список доступних партнерів групи (+ прокидуємо стейти).
        // При force_partner_id список уже зібраний у prepare_forced_partner().
        $partners = $forced_partners !== null
            ? $forced_partners
            : LeadRouter_Partners::available_in_group(
                $group_post_id,
                [
                    'group_meta_key' => $opts['group_meta_key'] ?? self::DEFAULT_META_GROUP,
                    // У Partners::available() за замовчуванням рахуємо тільки sent/accepted,
                    // але якщо ти явно передаєш свій набір — він застосовується:
                    'statuses' => $opts['statuses'] ?? ['sent', 'accepted'],
                    'lead_from_state' => $lead_from_state,
                    'lead_to_state' => $lead_to_state,
                    'dispatch_method' => $opts['dispatch_method']
                ]
            );

/*
        file_put_contents(
            __DIR__ . '/partners.log',
            print_r($partners, true)
        );*/

        // 🟡 Якщо партнерів немає — ставимо AWAIT
        if (empty($partners)) {
            self::log_info('no available partners now', [
                'lead_id' => $lead_id,
                'group_post_id' => $group_post_id,
                'group_name' => $group_name
            ]);

            self::mark_lead_status($lead_id, 'await', [
                'reason' => 'no_available_partners_now',
                'group_post_id' => $group_post_id,
                'group_name' => $group_name,
            ]);

            $err = new WP_Error(
                'no_available_partners_now',
                'Немає доступних партнерів у групі просто зараз',
                ['group_post_id' => $group_post_id, 'group_name' => $group_name]
            );
            do_action('leadrouter_after_dispatch', $lead_id, $opts, $err);
            return $err;
        }

        // ⚙️ Базові змінні для відправки
        $initial_status = (string)($opts['initial_status'] ?? 'sent');
        $dispatch_method = (string)($opts['dispatch_method'] ?? 'none');

        // 🔒 Жорстка модель: черги не використовуємо — закриті/без ліміту просто пропускаємо
        $results = [
            'lead_id' => $lead_id,
            'group_post_id' => $group_post_id,
            'group_name' => $group_name,
            'sent' => [],
            'failed' => [],
            'all' => [],
        ];

        /* ============================================================
         * 👥 Кластери власників: на один лід — максимум один партнер власника.
         * Поки поле «Власник» ні в кого не заповнене, список не змінюється.
         * ============================================================ */
        $owner_dropped = self::filter_one_partner_per_owner($partners);

        foreach ($owner_dropped as $dropped) {
            $dpid = (int)($dropped['partner_id'] ?? 0);
            $log_id = self::log_attempt($lead_id, $dpid, 'skipped', [
                'group_post_id'   => $group_post_id,
                'group_name'      => $group_name,
                'dispatch_method' => $dispatch_method,
                'meta'            => self::compact_partner_meta($dropped),
                'error_code'      => 'owner_duplicate',
                'is_skipped'      => 1,
                'lead_from_state' => $lead_from_state,
                'lead_to_state'   => $lead_to_state,
            ]);
            $results['failed'][] = [
                'partner_id'   => $dpid,
                'partner_name' => (string)($dropped['name'] ?? ''),
                'status'       => 'skipped',
                'log_id'       => is_wp_error($log_id) ? 0 : (int)$log_id,
                'error'        => 'owner_duplicate',
            ];
            $results['all'][] = end($results['failed']);
        }

        /* ============================================================
         * 🔀 Режим групи: класика (лід продається раз) чи shared (N копій)
         * ============================================================ */
        $group_settings = class_exists('LR_Shared_Sync')
            ? LR_Shared_Sync::get_group_settings($group_post_id)
            : ['mode' => 'classic', 'share_n' => 1];

        $is_shared     = ($group_settings['mode'] ?? 'classic') === 'shared';
        $copies_target = $is_shared ? max(1, (int)$group_settings['share_n']) : 1;
        $pick_mode     = $is_shared ? 'wrr' : 'classic';
        if ($force_partner_id > 0) {
            $pick_mode = 'manual'; // адресна відправка адміном — видно в assignments
        }

        if ($is_shared) {
            // Скільки копій ще бракує (при досилці лід уже має частину продажів).
            // Саме цю кількість добираємо, а не N наново.
            $copies_needed = max(0, $copies_target - self::count_lead_copies($lead_id, 'sent'));

            // Дефіцитний WRR: виключаємо тих, хто (або чий власник) уже має
            // копію цього ліда — тож досилка добирає рівно брак.
            $partners = $copies_needed > 0
                ? self::select_partners_shared($lead_id, $partners, $group, $copies_needed)
                : [];

            if (empty($partners)) {
                $sold = self::count_lead_copies($lead_id, 'sent');
                self::update_lead_copies($lead_id, $copies_target, $sold);

                if ($sold >= $copies_target) {
                    self::mark_lead_status($lead_id, 'sent', ['reason' => 'shared_complete']);
                } elseif ($sold > 0) {
                    self::mark_lead_status($lead_id, 'sent_partial', [
                        'reason'        => 'shared_no_more_candidates',
                        'copies_sold'   => $sold,
                        'copies_target' => $copies_target,
                        'group_post_id' => $group_post_id,
                    ]);

                    // 🅿️ Пул кандидатів вичерпано НАЗАВЖДИ, а не «зараз закриті»:
                    // усі, хто лишився, (або їхній власник) уже мають копію цього
                    // ліда. Новий кандидат з'явиться, лише якщо в групу додадуть
                    // партнера — тож паркуємо лід до півночі EST, щоб він не
                    // крутився в черзі крона вхолосту.
                    self::park_exhausted_shared_lead($lead_id, $sold, $copies_target, $group_post_id);
                } else {
                    self::mark_lead_status($lead_id, 'await', [
                        'reason'        => 'no_available_partners_now',
                        'group_post_id' => $group_post_id,
                        'group_name'    => $group_name,
                    ]);
                }

                $results['summary'] = self::build_summary_from_result($results);
                do_action('leadrouter_after_dispatch', $lead_id, $opts, $results);

                return $results;
            }
        }

        // Номер копії ліда: продовжуємо нумерацію, якщо копії вже є (досилка)
        $copy_no = self::count_lead_copies($lead_id);

        /* ============================================================
         * 🔁 Основний цикл відправки по партнерах
         * ============================================================ */
        foreach ($partners as $p) {
            $pid = (int)($p['partner_id'] ?? 0);
            $pname = (string)($p['name'] ?? (get_the_title($pid) ?: "Partner #{$pid}"));

            $p['group_post_id'] = $group_post_id;


            // TODO подвійне логування
            // 🧰 Фільтрація партнера — якщо причина повернеться, партнер пропускається
            $reason = self::filter_partner($p); // тут має перевіряти і from_state, і to_state


            if ($reason) {
                $log_id = self::log_attempt($lead_id, $pid, 'skipped', [
                    'group_post_id' => $group_post_id,
                    'group_name' => $group_name,
                    'dispatch_method' => $dispatch_method,
                    'error_code' => $reason,
                    'is_skipped' => 1,
                    'lead_from_state' => $lead_from_state,
                    'lead_to_state' => $lead_to_state,
                ]);
                $results['failed'][] = [
                    'partner_id' => $pid,
                    'partner_name' => $pname,
                    'status' => 'skipped',
                    'log_id' => is_wp_error($log_id) ? 0 : (int)$log_id,
                    'error' => $reason
                ];
                $results['all'][] = end($results['failed']);
                continue;
            }

            // 🕒 Закритий або без ліміту — просто пропускаємо (без постановки в чергу)
            if ((empty($p['open_now']) || (int)$p['limit_left'] <= 0) && ($opts['dispatch_method'] != 'manual_bulk') && $opts['dispatch_method'] != 'auto_cron_error_lead') {
                $err_code = empty($p['open_now']) ? 'partner_closed' : 'limit_exceeded';
                $log_id = self::log_attempt($lead_id, $pid, 'skipped', [
                    'group_post_id' => $group_post_id,
                    'group_name' => $group_name,
                    'dispatch_method' => $dispatch_method,
                    'meta' => self::compact_partner_meta($p),
                    'error_code' => $err_code,
                    'is_skipped' => 1,
                    'lead_from_state' => $lead_from_state,
                    'lead_to_state' => $lead_to_state,
                ]);
                $results['failed'][] = [
                    'partner_id' => $pid,
                    'partner_name' => $pname,
                    'status' => 'skipped',
                    'log_id' => is_wp_error($log_id) ? 0 : (int)$log_id,
                    'error' => $err_code
                ];
                $results['all'][] = end($results['failed']);
                continue;
            }

            // 🎟 Shared: бронюємо копію ДО відправки. Якщо UNIQUE не пустив —
            // копію цього ліда партнеру/власнику вже записано (паралельний крон
            // або досилка), тож партнера пропускаємо і йдемо до наступного.
            if ($is_shared) {
                $copy_no++;
                $reserved = self::reserve_assignment([
                    'lead_id'    => $lead_id,
                    'group_id'   => (int)($group['group_id'] ?? 0),
                    'partner_id' => $pid,
                    'copy_no'    => $copy_no,
                    'pick_mode'  => $pick_mode,
                ]);

                if (!$reserved) {
                    $copy_no--;
                    $log_id = self::log_attempt($lead_id, $pid, 'skipped', [
                        'group_post_id'   => $group_post_id,
                        'group_name'      => $group_name,
                        'dispatch_method' => $dispatch_method,
                        'meta'            => self::compact_partner_meta($p),
                        'error_code'      => 'assignment_exists',
                        'is_skipped'      => 1,
                        'lead_from_state' => $lead_from_state,
                        'lead_to_state'   => $lead_to_state,
                    ]);
                    $results['failed'][] = [
                        'partner_id'   => $pid,
                        'partner_name' => $pname,
                        'status'       => 'skipped',
                        'log_id'       => is_wp_error($log_id) ? 0 : (int)$log_id,
                        'error'        => 'assignment_exists',
                    ];
                    $results['all'][] = end($results['failed']);
                    continue;
                }
            }

            // 📡 Пряма відправка з ретраями
            $t0 = microtime(true);
            $send_res = self::send_with_retries($lead_id, $p, $dispatch_method);
            $runtime = round((microtime(true) - $t0) * 1000, 2);

            $is_error = ($send_res instanceof WP_Error);
            $err_msg = $is_error ? $send_res->get_error_message() : null;
            $attempts = $is_error ? 1 : ($send_res['attempts'] ?? 1);

            $log_status = $is_error ? 'failed' : $initial_status;
            $delivery = $is_error
                ? [
                    'error' => ($err_msg ?: 'unknown_error'),
                    'status_code' => is_wp_error($send_res) ? ($send_res->get_error_data()['status_code'] ?? null) : null,
                ]
                : [
                    'ok' => true,
                    'attempts' => $attempts,
                    'runtime_ms' => $runtime,
                    'status_code' => $send_res['status_code'] ?? null,
                    'external_id' => $send_res['external_id'] ?? null,
                ];

            // 📝 Логування спроби
            $log_id = self::log_attempt($lead_id, $pid, $log_status, [
                'group_post_id' => $group_post_id,
                'group_name' => $group_name,
                'dispatch_method' => $dispatch_method,
                'meta' => self::compact_partner_meta($p),
                'delivery' => $delivery,
                'error_code' => $is_error ? 'internal_error' : null,
                'lead_from_state' => $lead_from_state,
                'lead_to_state' => $lead_to_state,
            ]);

            $entry = [
                'partner_id' => $pid,
                'partner_name' => $pname,
                'status' => $log_status,
                'log_id' => is_wp_error($log_id) ? 0 : (int)$log_id,
                'error' => $err_msg
            ];

            // 🧾 Факт продажу копії ліда (append-only, з UNIQUE-запобіжниками)
            if ($is_shared) {
                // копія вже заброньована до відправки — фіксуємо результат
                self::finish_assignment($lead_id, $pid, $is_error ? 'failed' : 'sent');
            } else {
                $copy_no++;
                self::record_assignment([
                    'lead_id'    => $lead_id,
                    'group_id'   => (int)($group['group_id'] ?? 0),
                    'partner_id' => $pid,
                    'copy_no'    => $copy_no,
                    'status'     => $is_error ? 'failed' : 'sent',
                    'pick_mode'  => $pick_mode,
                ]);
            }

            $results['all'][] = $entry;
            if ($is_error) $results['failed'][] = $entry;
            else $results['sent'][] = $entry;
        }

        // Скільки копій ліда планували продати і скільки реально продано.
        // Для shared рахуємо по assignments — там і копії з попередніх досилок.
        $copies_sold = $is_shared
            ? self::count_lead_copies($lead_id, 'sent')
            : count($results['sent']);
        self::update_lead_copies($lead_id, $copies_target, $copies_sold);

        /* ============================================================
 * 📊 Фінальний статус ліда після відправки (fixed)
 * ============================================================ */
        {
            $cnt_sent = count($results['sent']);
            $cnt_failedF = 0; // реальні фейли
            $cnt_skipped = 0; // пропуски (закрито, ліміти тощо)

            foreach ($results['all'] as $e) {
                $st = $e['status'] ?? '';
                if ($st === 'failed') $cnt_failedF++;
                if ($st === 'skipped') $cnt_skipped++;
            }

            $cnt_attempted = $cnt_sent + $cnt_failedF;

            // Shared: статус визначають КОПІЇ, а не одна вдала відправка
            if ($is_shared) {
                if ($copies_sold >= $copies_target) {
                    self::mark_lead_status($lead_id, 'sent', [
                        'copies_sold'   => $copies_sold,
                        'copies_target' => $copies_target,
                    ]);
                } elseif ($copies_sold > 0) {
                    // недокомплект — await-крон добере решту, поки не скінчився день
                    self::mark_lead_status($lead_id, 'sent_partial', [
                        'copies_sold'   => $copies_sold,
                        'copies_target' => $copies_target,
                        'group_post_id' => $group_post_id,
                    ]);
                } elseif ($cnt_attempted === 0) {
                    self::mark_lead_status($lead_id, 'await', [
                        'reason'        => 'no_attempts_all_skipped',
                        'group_post_id' => $group_post_id,
                    ]);
                } else {
                    self::mark_lead_status($lead_id, 'error', [
                        'reason'       => 'all_attempts_failed',
                        'failed_count' => $cnt_failedF,
                    ]);
                }

                if ($cnt_sent > 0 && class_exists('LeadRouter_Leads')) {
                    $partners_summary = [];
                    foreach ($results['sent'] as $row) {
                        $partners_summary[] = [
                            'partner_id' => (int)($row['partner_id'] ?? 0),
                            'group_id'   => (int)$group_post_id,
                            'status'     => (string)($row['status'] ?? 'sent'),
                            'method'     => (string)$dispatch_method,
                        ];
                    }
                    if (!empty($partners_summary)) {
                        LeadRouter_Leads::update_sent_summary($lead_id, $partners_summary);
                    }
                }
            } elseif ($cnt_sent > 0) {
                // ✅ Є хоча б один успішний партнер → processed
                self::mark_lead_status($lead_id, 'sent', []);

                error_log('update_sent_summary before: lead_id=' . $lead_id);
                // 🧾 Оновлюємо sent_summary_json у таблиці лідів
                if (class_exists('LeadRouter_Leads')) {
                    $partners_summary = [];


                    error_log('update_sent_summary start: lead_id=' . $lead_id);
                    foreach ($results['sent'] as $row) {
                        $partners_summary[] = [
                            'partner_id' => (int)($row['partner_id'] ?? 0),
                            'group_id' => isset($group_post_id) ? (int)$group_post_id : 0,
                            'status' => (string)($row['status'] ?? 'sent'),
                            'method' => (string)$dispatch_method,
                        ];
                    }

                    if (!empty($partners_summary)) {
                        LeadRouter_Leads::update_sent_summary($lead_id, $partners_summary);
                    }
                }
            } elseif ($cnt_attempted === 0) {
                // 🕒 Жодної спроби → всі пропущені → await
                self::mark_lead_status($lead_id, 'state_error', ['reason' => 'no_attempts_all_skipped']);
            } else {
                // ❌ Були спроби → всі впали → error
                self::mark_lead_status($lead_id, 'error', [
                    'reason' => 'all_attempts_failed',
                    'failed_count' => $cnt_failedF,
                ]);
            }
        }


        $results['summary'] = self::build_summary_from_result($results);

        // 🧭 Підсумковий лог з таймінгом
        self::log_info('dispatch_broadcast completed', [
            'lead_id' => $lead_id,
            'partners_total' => count($results['all']),
            'sent' => count($results['sent']),
            'failed' => count($results['failed']),
            'runtime_ms' => round((microtime(true) - $start) * 1000, 2)
        ]);

/*
        $log_file = WP_CONTENT_DIR . '/leadrouter-flow.log';

        file_put_contents(
            $log_file,
            json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND
        );
*/

        do_action('leadrouter_after_dispatch', $lead_id, $opts, $results);
        return $results;
    }

    private static function build_summary_from_result(array $result, bool $is_ak_hi = false): array
    {
        $sent = is_array($result['sent'] ?? null) ? $result['sent'] : [];
        $failed = is_array($result['failed'] ?? null) ? $result['failed'] : [];

        $has_sent = !empty($sent);
        $has_real_fail = false;
        $has_skipped = false;

        foreach ($failed as $row) {
            $st = $row['status'] ?? '';
            if ($st === 'skipped') {
                $has_skipped = true;
            } else {
                $has_real_fail = true;
            }
        }

        if ($has_sent) {
            $lead_status = 'sent';
        } elseif ($has_real_fail) {
            $lead_status = 'error';
        } elseif ($has_skipped) {
            // якщо захочеш — сюди можна додати AK/HI через $is_ak_hi
            $lead_status = $is_ak_hi ? 'skipped' : 'await';
        } else {
            $lead_status = 'error';
        }

        return [
            'lead_id' => $result['lead_id'] ?? 0,
            'group_id' => $result['group_post_id'] ?? 0,
            'lead_status' => $lead_status,
        ];
    }


    /* ============================================================
     * 🧰 Фільтрація партнерів перед відправкою
     * ============================================================ */
    protected static function filter_partner(array $p): ?string
    {
        $pid = (int)($p['partner_id'] ?? 0);

        // ⛔ Якщо партнер вимкнений — пропускаємо
        $active = (int)get_post_meta($pid, '_leadrouter_partner_active', true);
        if ($active === 0) return 'deactivated_partner';

        // 📍 Обмеження по штатах Alaska / Hawaii
        $from_state = strtoupper(trim((string)($p['lead_from_state'] ?? '')));
        $to_state = strtoupper(trim((string)($p['lead_to_state'] ?? '')));

        $allowAK = get_post_meta($pid, '_leadrouter_partner_allow_alaska', true);
        $allowHI = get_post_meta($pid, '_leadrouter_partner_allow_hawaii', true);

        if (
            ($from_state === 'AK' || $to_state === 'AK') && !$allowAK
        ) {
            return 'state_filter_fail';
        }

        if (
            ($from_state === 'HI' || $to_state === 'HI') && !$allowHI
        ) {
            return 'state_filter_fail';
        }

        return null;
    }

    /* ============================================================
     * 🧭 Допоміжні методи
     * ============================================================ */

    /**
     * Зібрати стандартний BATS-payload з рядка таблиці leadrouter_leads.
     * Повертає масив як у твоїх прикладах (first_name/last_name/email/phone/... + Vehicles[0] + origin/destination).
     */
    protected static function build_bats_payload_from_lead_row(array $row): array
    {
        $full_name = trim((string)($row['name'] ?? ''));
        $name_parts = preg_split('/\s+/', $full_name, 2);
        $first = $name_parts[0] ?? '';
        $last = $name_parts[1] ?? '';

        // vehicle_inop: Running → '0', інше → '1'
        $cond = trim((string)($row['vehicle_condition'] ?? ''));
        $vehicle_inop = (strcasecmp($cond, 'Running') === 0) ? '0' : '1';

        // ship_date: лишаємо як у БД; трансформації під партнера зробить маппер (date_mdy, тощо)
        $ship_date = (string)($row['est_ship_date'] ?? '');

        $payload = [
            'first_name' => $first,
            'last_name' => $last,
            'email' => (string)($row['email'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
            'ship_date' => $ship_date,
            'comment_from_shipper' => '',
            'transport_type' => '1',

            'Vehicles' => [
                [
                    'vehicle_type' => (string)($row['vehicle_bodytype'] ?? ''), // якщо є
                    'vehicle_model_year' => (int)($row['vehicle_year'] ?? 0),
                    'vehicle_make' => (string)($row['vehicle_brand'] ?? ''),
                    'vehicle_model' => (string)($row['vehicle_model'] ?? ''),
                    'vehicle_inop' => $vehicle_inop,
                ]
            ],

            // from
            'origin_country' => 'USA',
            'origin_city' => (string)($row['from_city'] ?? ''),
            'origin_state' => (string)($row['from_state'] ?? ''),
            'origin_postal_code' => (string)($row['from_zip'] ?? ''),

            // to
            'destination_country' => 'USA',
            'destination_city' => (string)($row['to_city'] ?? ''),
            'destination_state' => (string)($row['to_state'] ?? ''),
            'destination_postal_code' => (string)($row['to_zip'] ?? ''),
            
            // utm
            'utm_source' => (string)($row['utm_source'] ?? ''),
        ];


        return $payload;


    }

    /** Дістати рядок ліда з БД (тільки потрібні поля) */
    protected static function get_lead_row(int $lead_id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'leadrouter_leads';
        $row = $wpdb->get_row(
            $wpdb->prepare("
            SELECT id, name, email, phone, est_ship_date,
                   vehicle_bodytype, vehicle_year, vehicle_brand, vehicle_model, vehicle_condition,
                   from_city, from_state, from_zip, to_city, to_state, to_zip, utm_source
            FROM {$table} WHERE id = %d
        ", $lead_id),
            ARRAY_A
        );
        return $row ?: null;
    }


    protected static function now_mysql_est(): string
    {
        $tz = new DateTimeZone(self::EST_TZ);
        return (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
    }

    protected static function check_dependencies()
    {
        if (!class_exists('LeadRouter_Dispatcher_Eff') ||
            !method_exists('LeadRouter_Dispatcher_Eff', 'assign_group_for_lead')) {
            return new WP_Error('leadrouter_flow_no_dispatcher', 'LeadRouter_Dispatcher_Eff::assign_group_for_lead не знайдено');
        }
        if (!class_exists('LeadRouter_Partners') ||
            !method_exists('LeadRouter_Partners', 'available_in_group')) {
            return new WP_Error('leadrouter_flow_no_partners_module', 'LeadRouter_Partners::available_in_group не знайдено');
        }
        return true;
    }

    public static function group_for_lead(int $lead_id, array $opts = [])
    {
        $group = LeadRouter_Dispatcher_Eff::assign_group_for_lead($lead_id, $opts);
        if (is_wp_error($group)) return $group;
        if (empty($group['group_post_id'])) {
            return new WP_Error('leadrouter_flow_group_empty', 'Алгоритм не повернув group_post_id', ['result' => $group]);
        }
        $group['name'] = $group['name'] ?? (get_the_title((int)$group['group_post_id']) ?: ('Group #' . (int)$group['group_post_id']));
        return $group;
    }

    /**
     * Кластери власників: лишити з кожного кластера ОДНОГО партнера —
     * з найбільшим limit_left × коефіцієнт (tie-break: менший partner_id).
     *
     * Порожнє поле «Власник» = партнер сам собі кластер ('p{id}'), тож поки
     * власники ні в кого не заповнені, список не змінюється взагалі.
     *
     * @param array $partners Список партнерів (модифікується: лишаються тільки обрані)
     * @return array Відсіяні рядки — їх треба залогувати як skipped/owner_duplicate
     */
    protected static function filter_one_partner_per_owner(array &$partners): array
    {
        if (count($partners) < 2) {
            return [];
        }

        $clusters = [];
        foreach ($partners as $idx => $p) {
            $owner = self::owner_id_for_partner((int)($p['partner_id'] ?? 0));
            $clusters[$owner][] = $idx;
        }

        $drop = [];
        foreach ($clusters as $idxs) {
            if (count($idxs) < 2) {
                continue;
            }

            $best_idx = null;
            $best_score = null;
            $best_pid = null;

            foreach ($idxs as $i) {
                $pid = (int)($partners[$i]['partner_id'] ?? 0);

                // Представника кластера обираємо за ЧАСТКОЮ невибраного ліміту,
                // а не за абсолютним залишком. З абсолютним партнер із більшим
                // лімітом вигравав усі ранкові ліди поспіль (30 > 20), і другий
                // простоював, доки перший не спуститься до його рівня — тобто
                // кластер працював послідовно, а не по черзі.
                //
                // Частка ставить обох в одну шкалу: після кожної видачі частка
                // переможця падає, і наступний лід іде сусідові. За день кожен
                // добирає свій ліміт (30/20 → чергування 3:2), але рівномірно
                // від ранку, без простою.
                //
                // Коефіцієнт партнера тут НЕ застосовуємо навмисно: він уже
                // задає частку в select_partners_shared (вага limit_today*coef).
                // Якщо помножити ще й тут, вплив подвоюється — партнер із
                // більшим coef забирає всі ранкові ліди, і простій просто
                // переїжджає на сусіда. Тут вирішується лише «чия черга»,
                // а «скільки кому належить» — нижче, за коефіцієнтом.
                $limit_today = (int)($partners[$i]['limit_today'] ?? 0);
                $left        = max(0, (int)($partners[$i]['limit_left'] ?? 0));
                $score       = $limit_today > 0 ? ($left / $limit_today) : 0.0;

                $better = $best_idx === null
                    || $score > $best_score + 0.000001
                    || (abs($score - $best_score) < 0.000001 && $pid < $best_pid);

                if ($better) {
                    $best_idx   = $i;
                    $best_score = $score;
                    $best_pid   = $pid;
                }
            }

            foreach ($idxs as $i) {
                if ($i !== $best_idx) {
                    $drop[$i] = true;
                }
            }
        }

        if (empty($drop)) {
            return [];
        }

        $kept = [];
        $dropped = [];
        foreach ($partners as $i => $p) {
            if (isset($drop[$i])) {
                $dropped[] = $p;
            } else {
                $kept[] = $p;
            }
        }

        $partners = $kept;

        return $dropped;
    }

    /* ============================================================
     * 🔗 SHARED: вибір N партнерів дефіцитним WRR
     * ============================================================ */

    /**
     * Підготовка ручної відправки на конкретного партнера (force_partner_id):
     * група — з мети партнера, без WRR/квот/eff. Повертає [$group, $partners]
     * або WP_Error, якщо відправка неможлива в принципі (партнер не існує /
     * неактивний / він чи його власник уже має копію цього ліда).
     *
     * @return array{0: array, 1: array}|WP_Error
     */
    protected static function prepare_forced_partner(int $lead_id, int $partner_id, array $opts)
    {
        global $wpdb;

        if (get_post_type($partner_id) !== 'leadrouter_partner') {
            return new WP_Error('force_partner_not_found', "Партнера #{$partner_id} не знайдено");
        }

        // UNIQUE-запобіжники наперед: той самий лід тому самому партнеру або
        // власнику не продаємо навіть вручну
        [$has_partners, $has_owners] = self::lead_assignment_sets($lead_id);
        if (isset($has_partners[$partner_id])) {
            return new WP_Error('force_partner_already_has_lead', 'Цей партнер уже має копію цього ліда');
        }
        $owner = (string)self::owner_id_for_partner($partner_id);
        if ($owner !== '' && isset($has_owners[$owner])) {
            return new WP_Error(
                'force_owner_already_has_lead',
                "Інша компанія власника «{$owner}» вже має копію цього ліда"
            );
        }

        $group_post_id = (int)get_post_meta($partner_id, '_leadrouter_partner_group', true);
        if ($group_post_id <= 0) {
            return new WP_Error('force_partner_no_group', 'У партнера не задано групу');
        }

        $group_row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}leadrouter_groups WHERE post_id = %d",
            $group_post_id
        ), ARRAY_A);

        $group = [
            'group_id'      => (int)($group_row['id'] ?? 0),
            'group_post_id' => $group_post_id,
            'name'          => (string)($group_row['name'] ?? (get_the_title($group_post_id) ?: "Group #{$group_post_id}")),
            'weight'        => 0,
        ];

        // dispatch_method=manual_bulk вмикає force-режим у Partners: години й
        // ліміти не блокують; неактивного/неопублікованого партнера не поверне
        $partners = LeadRouter_Partners::available([
            'partner_ids'     => [$partner_id],
            'statuses'        => $opts['statuses'] ?? ['sent', 'accepted'],
            'lead_from_state' => (string)($opts['lead_from_state'] ?? ''),
            'lead_to_state'   => (string)($opts['lead_to_state'] ?? ''),
            'dispatch_method' => 'manual_bulk',
        ]);

        if (empty($partners)) {
            return new WP_Error('force_partner_unavailable', 'Партнер неактивний або неопублікований');
        }

        return [$group, $partners];
    }

    /**
     * Якщо лід уже має копії у shared-групі — повертає ТУ САМУ групу, щоб
     * досилка добирала брак там же (без нового WRR і без другої витрати квоти).
     *
     * @return array{group_id:int,group_post_id:int,name:string,weight:int}|null
     */
    protected static function shared_group_for_topup(int $lead_id): ?array
    {
        global $wpdb;

        $t_assign = $wpdb->prefix . 'leadrouter_lead_assignments';
        $t_groups = $wpdb->prefix . 'leadrouter_groups';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT g.id, g.post_id, g.name
               FROM {$t_assign} a
               INNER JOIN {$t_groups} g ON g.id = a.group_id
              WHERE a.lead_id = %d AND g.mode = 'shared'
              ORDER BY a.id DESC
              LIMIT 1",
            $lead_id
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        return [
            'group_id'      => (int)$row['id'],
            'group_post_id' => (int)$row['post_id'],
            'name'          => (string)$row['name'],
            'weight'        => 0,
        ];
    }

    /**
     * Дефіцитний WRR (план §6.3): беремо до N партнерів.
     *
     *   target_share_i = (limit_today_i × coef_i) / Σ(limit_today × coef)
     *   deficit_i      = target_share_i × copies_issued_today − received_today_i
     *
     * Сортування: deficit DESC → limit_left DESC → partner_id ASC.
     * Виключаємо тих, хто (або чий власник) уже має копію цього ліда — саме це
     * робить досилку добором лише відсутніх копій.
     */
    protected static function select_partners_shared(int $lead_id, array $candidates, array $group, int $n): array
    {
        if (empty($candidates) || $n <= 0) {
            return [];
        }

        [$taken_partners, $taken_owners] = self::lead_assignment_sets($lead_id);

        $group_row_id = (int)($group['group_id'] ?? 0);
        $issued_today = self::group_copies_today($group_row_id);
        $received     = self::partner_copies_today(array_map(static function ($p) {
            return (int)($p['partner_id'] ?? 0);
        }, $candidates));

        $pool = [];
        $sum_weight = 0.0;

        foreach ($candidates as $p) {
            $pid = (int)($p['partner_id'] ?? 0);
            if ($pid <= 0 || isset($taken_partners[$pid])) {
                continue;
            }

            $owner = self::owner_id_for_partner($pid);
            if (isset($taken_owners[$owner])) {
                continue;
            }

            $coef   = class_exists('LR_Shared_Sync') ? LR_Shared_Sync::get_partner_coef($pid) : 1.0;
            $weight = max(0, (int)($p['limit_today'] ?? 0)) * $coef;

            $p['_owner_id'] = $owner;
            $p['_weight']   = $weight;
            $p['_received'] = (int)($received[$pid] ?? 0);

            $pool[] = $p;
            $sum_weight += $weight;
        }

        if (empty($pool)) {
            return [];
        }

        foreach ($pool as &$p) {
            $target_share = $sum_weight > 0 ? ($p['_weight'] / $sum_weight) : 0.0;
            $p['_deficit'] = $target_share * $issued_today - $p['_received'];
        }
        unset($p);

        usort($pool, static function ($a, $b) {
            if (abs($a['_deficit'] - $b['_deficit']) > 0.000001) {
                return $b['_deficit'] <=> $a['_deficit'];
            }
            if ((int)$a['limit_left'] !== (int)$b['limit_left']) {
                return (int)$b['limit_left'] <=> (int)$a['limit_left'];
            }

            return (int)$a['partner_id'] <=> (int)$b['partner_id'];
        });

        $picked = [];
        $owners_in_set = $taken_owners;

        foreach ($pool as $p) {
            if (count($picked) >= $n) {
                break;
            }

            $owner = (string)$p['_owner_id'];
            if (isset($owners_in_set[$owner])) {
                continue; // на один лід — максимум один партнер власника
            }

            $owners_in_set[$owner] = true;
            $picked[] = $p;
        }

        return $picked;
    }

    /**
     * Партнери й власники, що вже мають копію цього ліда.
     *
     * failed-рядки НЕ рахуємо: невдала спроба — це не продана копія, і вона
     * не повинна назавжди блокувати пару лід+партнер. Ранковий DNS-збій 10.08
     * лишив 15 failed-бронювань на одному партнері, і після відновлення
     * зв'язку добрати ці копії було неможливо — вони пішли іншим партнерам,
     * передчасно виївши їхні ліміти. Повторну бронь поверх failed пускають
     * reserve_assignment()/record_assignment(), які попередньо прибирають
     * failed-рядок пари.
     */
    protected static function lead_assignment_sets(int $lead_id): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT partner_id, owner_id
               FROM {$wpdb->prefix}leadrouter_lead_assignments
              WHERE lead_id = %d AND status <> 'failed'",
            $lead_id
        ), ARRAY_A) ?: [];

        $partners = [];
        $owners   = [];
        foreach ($rows as $r) {
            $partners[(int)$r['partner_id']] = true;
            // нормалізуємо і те, що вже лежить у БД: історичні рядки могли
            // писатись до нормалізації тега («OwnerX» проти «ownerx»)
            $owners[self::normalize_owner_value((string)$r['owner_id'])] = true;
        }

        return [$partners, $owners];
    }

    /**
     * Скільки копій група роздала сьогодні (EST).
     * failed не рахуємо — це не роздана копія (симетрично до deficit-математики).
     */
    protected static function group_copies_today(int $group_row_id): int
    {
        global $wpdb;

        if ($group_row_id <= 0) {
            return 0;
        }

        [$day_start, $day_end] = self::today_window_est();

        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}leadrouter_lead_assignments
              WHERE group_id = %d AND status <> 'failed'
                AND created_at BETWEEN %s AND %s",
            $group_row_id,
            $day_start,
            $day_end
        ));
    }

    /** Скільки копій отримав сьогодні кожен із партнерів (EST) */
    protected static function partner_copies_today(array $partner_ids): array
    {
        global $wpdb;

        $partner_ids = array_values(array_unique(array_filter(array_map('intval', $partner_ids))));
        if (empty($partner_ids)) {
            return [];
        }

        [$day_start, $day_end] = self::today_window_est();
        $ids_in = implode(',', $partner_ids);

        // failed не рахуємо як «отриману копію»: інакше партнер, що падав
        // (напр. DNS-збій), виглядає ситим у deficit-математиці й недоотримує
        // нові ліди навіть після відновлення зв'язку.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT partner_id, COUNT(*) AS cnt
               FROM {$wpdb->prefix}leadrouter_lead_assignments
              WHERE partner_id IN ({$ids_in})
                AND status <> 'failed'
                AND created_at BETWEEN %s AND %s
              GROUP BY partner_id",
            $day_start,
            $day_end
        ), ARRAY_A) ?: [];

        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['partner_id']] = (int)$r['cnt'];
        }

        return $map;
    }

    /** Скільки копій ліда вже записано (за потреби — лише в певному статусі) */
    protected static function count_lead_copies(int $lead_id, ?string $status = null): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'leadrouter_lead_assignments';

        if ($status === null) {
            return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE lead_id = %d", $lead_id));
        }

        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE lead_id = %d AND status = %s",
            $lead_id,
            $status
        ));
    }

    /**
     * Забронювати копію ДО відправки (shared). false — UNIQUE не пустив,
     * тобто копію цього ліда партнеру або його власнику вже записано.
     */
    protected static function reserve_assignment(array $a): bool
    {
        global $wpdb;

        $lead_id    = (int)($a['lead_id'] ?? 0);
        $partner_id = (int)($a['partner_id'] ?? 0);
        if ($lead_id <= 0 || $partner_id <= 0) {
            return false;
        }

        $owner = self::owner_id_for_partner($partner_id);

        // Прибираємо failed-бронь цієї пари (партнера або його власника):
        // невдала спроба не має назавжди займати UNIQUE-слот, інакше після
        // тимчасового збою (напр. DNS 10.08) партнер уже ніколи не добере
        // цей лід. Активні sending/sent рядки не чіпаємо — їх UNIQUE блокує
        // як і раніше. Історія самих спроб живе в send_log/partner_logs.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}leadrouter_lead_assignments
              WHERE lead_id = %d AND status = 'failed'
                AND (partner_id = %d OR owner_id = %s)",
            $lead_id,
            $partner_id,
            $owner
        ));

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}leadrouter_lead_assignments
                (lead_id, group_id, partner_id, owner_id, copy_no, status, pick_mode, created_at)
             VALUES (%d, %d, %d, %s, %d, %s, %s, %s)",
            $lead_id,
            (int)($a['group_id'] ?? 0),
            $partner_id,
            $owner,
            max(1, (int)($a['copy_no'] ?? 1)),
            'sending',
            (string)($a['pick_mode'] ?? 'wrr'),
            self::now_mysql_est()
        ));

        return (int)$wpdb->rows_affected === 1;
    }

    /** Зафіксувати результат відправки у заброньованій копії */
    protected static function finish_assignment(int $lead_id, int $partner_id, string $status): void
    {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'leadrouter_lead_assignments',
            ['status' => $status],
            ['lead_id' => $lead_id, 'partner_id' => $partner_id],
            ['%s'],
            ['%d', '%d']
        );
    }

    /** Сьогоднішнє вікно EST як рядки MySQL */
    protected static function today_window_est(): array
    {
        $tz  = new DateTimeZone(self::EST_TZ);
        $now = new DateTimeImmutable('now', $tz);

        return [
            $now->format('Y-m-d 00:00:00'),
            $now->format('Y-m-d 23:59:59'),
        ];
    }

    /** Тег власника партнера (порожній → сам собі кластер) */
    protected static function owner_id_for_partner(int $partner_id): string
    {
        if (class_exists('LR_Shared_Sync')) {
            return LR_Shared_Sync::get_partner_owner($partner_id);
        }

        return 'p' . $partner_id;
    }

    /** Канонічний вигляд тега власника (див. LR_Shared_Sync::normalize_owner) */
    protected static function normalize_owner_value(string $raw): string
    {
        return class_exists('LR_Shared_Sync')
            ? LR_Shared_Sync::normalize_owner($raw)
            : strtolower(trim($raw));
    }

    /**
     * Записати факт продажу копії ліда в leadrouter_lead_assignments.
     *
     * INSERT IGNORE: два UNIQUE-ключі (lead+partner, lead+owner) — останній
     * рубіж проти повторної відправки того самого ліда партнеру або власнику
     * (у т.ч. при досилках через await/error-крони).
     */
    protected static function record_assignment(array $a): bool
    {
        global $wpdb;

        $lead_id    = (int)($a['lead_id'] ?? 0);
        $partner_id = (int)($a['partner_id'] ?? 0);
        if ($lead_id <= 0 || $partner_id <= 0) {
            return false;
        }

        $table = $wpdb->prefix . 'leadrouter_lead_assignments';

        $owner = self::owner_id_for_partner($partner_id);

        // Симетрично до reserve_assignment(): failed-рядок пари не повинен
        // блокувати запис нового результату (класика/досилання після збою).
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table}
              WHERE lead_id = %d AND status = 'failed'
                AND (partner_id = %d OR owner_id = %s)",
            $lead_id,
            $partner_id,
            $owner
        ));

        $ok = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table}
                (lead_id, group_id, partner_id, owner_id, copy_no, status, pick_mode, created_at)
             VALUES (%d, %d, %d, %s, %d, %s, %s, %s)",
            $lead_id,
            (int)($a['group_id'] ?? 0),
            $partner_id,
            $owner,
            max(1, (int)($a['copy_no'] ?? 1)),
            (string)($a['status'] ?? 'sent'),
            (string)($a['pick_mode'] ?? 'classic'),
            self::now_mysql_est()
        ));

        if ($ok === false) {
            self::log('error', 'assignment insert failed', [
                'lead_id'    => $lead_id,
                'partner_id' => $partner_id,
                'db_error'   => $wpdb->last_error,
            ]);
            return false;
        }

        return true;
    }

    /** Скільки копій ліда планували продати і скільки продали */
    protected static function update_lead_copies(int $lead_id, int $target, int $sold): void
    {
        global $wpdb;

        if ($lead_id <= 0) {
            return;
        }

        $wpdb->update(
            $wpdb->prefix . 'leadrouter_leads',
            [
                'copies_target' => max(1, $target),
                'copies_sold'   => max(0, $sold),
            ],
            ['id' => $lead_id],
            ['%d', '%d'],
            ['%d']
        );
    }

    /**
     * Паркування shared-ліда з вичерпаним пулом кандидатів: до півночі EST
     * (нові кандидати можуть з'явитись хіба з новим днем/партнером).
     * Статус лишається sent_partial — без маркера last_error_code такий лід
     * у списку виглядав би точнісінько як той, що просто чекає черги.
     */
    protected static function park_exhausted_shared_lead(int $lead_id, int $sold, int $target, int $group_post_id): void
    {
        global $wpdb;

        $now  = new DateTimeImmutable('now', new DateTimeZone(self::EST_TZ));
        $next = $now->modify('+1 day')->format('Y-m-d 00:00:00'); // найближча північ EST

        $wpdb->update(
            $wpdb->prefix . 'leadrouter_leads',
            [
                'next_attempt_at' => $next,
                'last_error_code' => 'shared_pool_exhausted',
                'last_error_at'   => $now->format('Y-m-d H:i:s'),
            ],
            ['id' => $lead_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        [$taken_partners, ] = self::lead_assignment_sets($lead_id);

        // окрема подія в логах: retry_parked НЕ дедупиться (LOG_DEDUP_STATUSES),
        // бо це рідкісна подія і кожна щось означає
        self::log_event($lead_id, 'retry_parked', [
            'reason'        => 'shared_pool_exhausted',
            'copies_sold'   => $sold,
            'copies_target' => $target,
            'group_post_id' => $group_post_id,
            'partner_id'    => array_keys($taken_partners),
        ]);
    }

    protected static function compact_partner_meta(array $p): array
    {
        return [
            'open_now' => (bool)($p['open_now'] ?? true),
            'limit_today' => (int)($p['limit_today'] ?? 0),
            'used_today' => (int)($p['used_today'] ?? 0),
            'limit_left' => (int)($p['limit_left'] ?? 0),
        ];
    }

    /* --- решта допоміжних методів (queue, send_with_retries, log_event тощо) залишаються без змін --- */


    /** Відправка з ретраями */
    /** Відправка з ретраями через LeadRouter_Sender_Light */
    protected static function send_with_retries(int $lead_id, array $partner_row, string $dispatch_method)
    {
        do_action('leadrouter_before_send', $lead_id, $partner_row, ['dispatch_method' => $dispatch_method]);



/*
        file_put_contents(
            __DIR__ . '/partners_row.log',
            print_r($partner_row, true)
        );*/

        // 1) Дані ліда з БД → наш стандартний payload
        $lead = self::get_lead_row($lead_id);
        if (!$lead) {
            $err = new WP_Error('lead_not_found', 'Лід не знайдено');
            do_action('leadrouter_after_send', $lead_id, $partner_row, $err);
            return $err;
        }
        $our_payload = self::build_bats_payload_from_lead_row($lead);

        // 2) Партнер
        $partner_id = (int)($partner_row['partner_id'] ?? 0);
        if ($partner_id <= 0) {
            $err = new WP_Error('bad_partner', 'Некоректний partner_id');
            do_action('leadrouter_after_send', $lead_id, $partner_row, $err);
            return $err;
        }

        // 3) Налаштування ретраїв: Flow робить до RETRY_MAX_ATTEMPTS спроб Sender’а,
        //    а всередині Sender можна лишити http_retries=0 (щоб не плодити подвійні ретраї),
        //    або поставити 1–2 якщо хочеш комбінований підхід.
        $max_attempts = (int)self::RETRY_MAX_ATTEMPTS;
        $attempt = 0;
        $last_error = null;

        while ($attempt < $max_attempts) {
            $attempt++;

            $ctx = [
                'lead_id' => $lead_id,
                'group_post_id' => (int)($partner_row['group_post_id'] ?? 0),
                'attempt' => $attempt,
                'dispatch_method' => $dispatch_method,
                'http_retries' => 0,      // ретраї робимо на рівні Flow
                'timeout' => 20,     // сек; за потреби прокинь із опцій
            ];


            $t0 = microtime(true);
            $out = LeadRouter_Sender_Light::send($our_payload, $partner_id, $ctx);
            $ms = round((microtime(true) - $t0) * 1000, 2);

            // Локальне «збагачення» результату — щоб Flow мав коротку картину
            $res = $out['result'] ?? [];
            $resp = $out['resp'] ?? null;

            // Якщо успіх — віддаємо дані вверх
            if (!empty($res['success'])) {
                $ok = [
                    'ok' => true,
                    'attempts' => $attempt,
                    'runtime_ms' => $ms,
                    'status_code' => $res['status_code'] ?? ($resp['status_code'] ?? null),
                    'external_id' => $res['external_id'] ?? null,
                ];
                do_action('leadrouter_after_send', $lead_id, $partner_row, $ok);
                return $ok;
            }

            // Якщо невдача — вирішуємо, чи робити ще спробу
            $retryable = !empty($res['retryable']);
            $err_code = $res['error_code'] ?? 'send_failed';
            $err_msg = $res['error_message'] ?? ('HTTP ' . ($res['status_code'] ?? 'n/a'));

            $last_error = new WP_Error($err_code, $err_msg, [
                'status_code' => $res['status_code'] ?? null,
                'attempt' => $attempt,
                'runtime_ms' => $ms,
            ]);

            if ($retryable && $attempt < $max_attempts) {
                // твій бекоф із констант
                $delay = self::RETRY_BACKOFF_SEC[$attempt - 1] ?? end(self::RETRY_BACKOFF_SEC);
                if ($delay > 0) sleep((int)$delay);
                continue;
            }

            // non-retryable або вичерпали спроби
            break;
        }

        do_action('leadrouter_after_send', $lead_id, $partner_row, $last_error);
        return $last_error ?: new WP_Error('send_failed', 'Збій відправки');
    }

    /**
     * Заглушка відправки — сюди підключай реальний транспорт (HTTP webhook/API/email/queue).
     * Поверни WP_Error у разі невдачі.
     */
    public static function send_to_partner(int $lead_id, array $partner_row, array $opts = [])
    {
        // приклад HTTP:
        // $endpoint = get_post_meta( (int)$partner_row['partner_id'], '_leadrouter_partner_endpoint', true );
        // if ( ! $endpoint ) return new WP_Error('no_endpoint', 'У партнера не задано endpoint');
        // $payload = self::build_payload( $lead_id, $partner_row, $opts );
        // $resp    = wp_remote_post( $endpoint, [ 'timeout' => 10, 'body' => $payload ] );
        // if ( is_wp_error($resp) || wp_remote_retrieve_response_code($resp) >= 400 ) {
        //     return new WP_Error('send_failed', 'Збій відправки');
        // }

        // тимчасова симуляція успіху
        return ['ok' => true];
    }

    /** Покласти завдання у cron-чергу (для закритих партнерів/пізнішої доставки) */
    protected static function queue_partner_send(int $lead_id, int $partner_id, int $group_post_id, string $dispatch_method, array $partner_row): void
    {
        // Ім'я події
        $hook = 'leadrouter_queue_send';
        if (!has_action($hook)) {
            // Споживач має десь підписатися: add_action('leadrouter_queue_send', [LeadRouter_Flow::class, 'cron_send_worker'], 10, 5);
        }
        // Плануємо через хвилину
        $when = time() + 60;
        wp_schedule_single_event($when, $hook, [$lead_id, $partner_id, $group_post_id, $dispatch_method, $partner_row]);
    }

    /** Робітник cron для відкладеної відправки */
    public static function cron_send_worker(int $lead_id, int $partner_id, int $group_post_id, string $dispatch_method, array $partner_row): void
    {
        // Можна повторно перевірити відкриття/ліміти і викликати send_with_retries()
        self::send_with_retries($lead_id, $partner_row, $dispatch_method);
        // Логування тут за бажанням (ми логуємо на постановці у чергу)
    }

    /** Логування спроби доставки до партнера (leadrouter_partner_logs) */
    public static function log_attempt(int $lead_id, int $partner_id, string $status, array $extra = [])
    {
        global $wpdb;
        $table = $wpdb->prefix . 'leadrouter_partner_logs';

        $attempted_at = self::now_mysql_est();
        $dispatch_method = sanitize_text_field($extra['dispatch_method'] ?? 'none');
        $group_post_id = isset($extra['group_post_id']) ? (int)$extra['group_post_id'] : 0;

        // Пакуємо payload
        $payload = null;
        if (!empty($extra)) {
            $payload = wp_json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $data = [
            'lead_id' => (int)$lead_id,
            'partner_id' => (int)$partner_id,
            // УВАГА: для сумісності колонка називається group_id, але тут пишемо саме group_post_id
            'group_id' => $group_post_id ?: null,
            'status' => sanitize_text_field($status),
            'attempted_at' => $attempted_at,
            'request_json' => $payload,
            'dispatch_method' => $dispatch_method,
        ];

        $format = [];
        foreach ($data as $k => $v) {
            if (is_null($v)) {
                unset($data[$k]);
                continue;
            }
            $format[] = is_int($v) ? '%d' : '%s';
        }

        do_action('leadrouter_before_log_attempt', $lead_id, $partner_id, $status, $extra, $data);

        $ok = $wpdb->insert($table, $data, $format);

        if (!$ok) {
            $err = new WP_Error('leadrouter_flow_log_failed', 'Не вдалося записати лог', ['db_error' => $wpdb->last_error]);
            self::log('error', 'log_attempt insert failed', ['lead_id' => $lead_id, 'partner_id' => $partner_id, 'db_error' => $wpdb->last_error, 'data' => $data]);

            do_action('leadrouter_after_log_attempt', $lead_id, $partner_id, $status, $extra, $err);
            return $err;
        }

        self::log('debug', 'log_attempt ok', ['id' => (int)$wpdb->insert_id, 'lead_id' => $lead_id, 'partner_id' => $partner_id, 'status' => $status]);
        $insert_id = (int)$wpdb->insert_id;
        do_action('leadrouter_after_log_attempt', $lead_id, $partner_id, $status, $extra, $insert_id);
        return $insert_id;
    }

    /** Лог подій (не по конкретному партнеру) — leadrouter_logs */
    public static function log_event(int $lead_id, string $status, array $extra = []): void
    {
        global $wpdb;
        $table_logs = $wpdb->prefix . 'leadrouter_logs';

        $now_mysql = self::now_mysql_est();

        // partner_id може бути int|array|string(JSON) — збережемо як JSON-рядок (якщо масив) або як рядок (якщо int — теж рядком)
        $partner_raw = null;
        if (isset($extra['partner_id'])) {
            $partner_raw = is_array($extra['partner_id'])
                ? wp_json_encode(array_values(array_unique(array_map('intval', $extra['partner_id']))))
                : (string)$extra['partner_id'];
        }

        $payload = !empty($extra) ? wp_json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $data = [
            'lead_id' => (int)$lead_id,
            // УВАГА: колонка називається group_id, але ми зберігаємо саме group_post_id (WP post ID)
            'group_id' => isset($extra['group_post_id']) ? (int)$extra['group_post_id'] : null,
            'partner_id' => $partner_raw, // може бути JSON-рядок
            'assigned_at' => $now_mysql,
            'status' => sanitize_text_field($status),
            'payload' => $payload,
        ];

        $format = [];
        foreach ($data as $k => $v) {
            if (is_null($v)) {
                unset($data[$k]);
                continue;
            }
            $format[] = is_int($v) ? '%d' : '%s';
        }

        // 🧹 Дедуп «шумних» статусів: await/sent_partial крони перепризначають
        // щохвилини, і кожен прохід писав новий рядок — за 09.08 вийшло 333
        // записи await на 16 лідів і 192 sent_partial на 20. Пишемо лише коли
        // щось реально змінилось або спливло вікно дедупу.
        if (self::is_duplicate_log_event($lead_id, $data)) {
            self::log('debug', 'log_event skipped (duplicate)', [
                'lead_id' => $lead_id,
                'status'  => $status,
            ]);
            return;
        }

        do_action('leadrouter_before_log_event', $lead_id, $status, $extra, $data);
        $ok = $wpdb->insert($table_logs, $data, $format);
        if (!$ok) {
            self::log('error', 'log_event insert failed', ['lead_id' => $lead_id, 'status' => $status, 'db_error' => $wpdb->last_error, 'data' => $data]);
        } else {
            self::log('debug', 'log_event ok', ['id' => (int)$wpdb->insert_id, 'lead_id' => $lead_id, 'status' => $status]);
        }
        do_action('leadrouter_after_log_event', $lead_id, $status, $extra, $wpdb->insert_id);
    }

    /**
     * Чи є подія повним дублем попередньої по цьому ліду.
     *
     * Дедупимо тільки статуси з LOG_DEDUP_STATUSES — інші події (group_assigned,
     * sent, error) рідкісні й важливі, їх пишемо завжди. Порівнюємо з ОСТАННІМ
     * рядком ліда, а не з будь-яким у вікні: якщо стан встиг змінитись і
     * повернутись назад — це нова подія, її треба зафіксувати.
     *
     * Вікно можна змінити фільтром leadrouter_log_dedup_window_min (0 = вимкнути).
     *
     * @param array $data Рядок, підготовлений до вставки (уже без NULL-полів).
     */
    protected static function is_duplicate_log_event(int $lead_id, array $data): bool
    {
        $status = (string)($data['status'] ?? '');

        if ($lead_id <= 0 || !in_array($status, self::LOG_DEDUP_STATUSES, true)) {
            return false;
        }

        $window_min = (int)apply_filters(
            'leadrouter_log_dedup_window_min',
            self::LOG_DEDUP_WINDOW_MIN,
            $status,
            $lead_id
        );

        if ($window_min <= 0) {
            return false;
        }

        global $wpdb;
        $table_logs = $wpdb->prefix . 'leadrouter_logs';

        $last = $wpdb->get_row($wpdb->prepare(
            "SELECT status, group_id, partner_id, payload, assigned_at
               FROM {$table_logs}
              WHERE lead_id = %d
              ORDER BY id DESC
              LIMIT 1",
            $lead_id
        ), ARRAY_A);

        if (!$last || (string)$last['status'] !== $status) {
            return false; // попередньої події немає або стан змінився
        }

        // NULL і відсутнє поле мають зводитись до одного значення, інакше
        // рядок без group_id ніколи не збігся б сам із собою.
        $same = static function ($a, $b): bool {
            return (string)($a ?? '') === (string)($b ?? '');
        };

        if (!$same($last['group_id'], $data['group_id'] ?? null)
            || !$same($last['partner_id'], $data['partner_id'] ?? null)
            || !$same($last['payload'], $data['payload'] ?? null)) {
            return false; // зміст інший (напр. copies_sold зріс) — це прогрес
        }

        // Обидві мітки в EST, тож різниця коректна незалежно від TZ сервера.
        $prev_ts = strtotime((string)$last['assigned_at']);
        $now_ts  = strtotime((string)($data['assigned_at'] ?? ''));

        if ($prev_ts === false || $now_ts === false || $now_ts < $prev_ts) {
            return false;
        }

        return ($now_ts - $prev_ts) < $window_min * 60;
    }

    /** Позначити статус ліда (тут же викликаємо log_event) */
    public static function mark_lead_status(int $lead_id, string $status, array $extra = []): bool
    {
        if ($lead_id <= 0) return false;

        // нормалізуємо статус
        $status = strtolower(trim($status));

        global $wpdb;
        $table = $wpdb->prefix . 'leadrouter_leads';

        // за бажанням можеш обмежити список дозволених статусів
        // $allowed = ['new','await','processed','error','sent'];
        // if (!in_array($status, $allowed, true)) { $status = 'error'; }

        // оновлюємо статус у leads
        $wpdb->update(
            $table,
            ['response_status' => $status, 'status' => $status],
            ['id' => $lead_id],
            ['%s'],
            ['%d']
        );


        if ($status === 'sent') {
            $wpdb->update(
                $table,
                ['sent_at' => self::now_mysql_est()],
                ['id' => $lead_id],
                ['%s'],
                ['%d']
            );
        }

        // Хук для розширень
        do_action('leadrouter_mark_lead_status', $lead_id, $status, $extra);

        // + лог події у leadrouter_logs
        self::log_event($lead_id, $status, $extra);

        return true;
    }

    /** Побудова payload для транспорту */
    public static function build_payload(int $lead_id, array $partner_row, array $opts = []): array
    {
        return [
            'lead_id' => $lead_id,
            'partner_id' => (int)($partner_row['partner_id'] ?? 0),
            'partner_name' => (string)($partner_row['name'] ?? ''),
            'limit_left' => (int)($partner_row['limit_left'] ?? 0),
            'attempt' => (int)($opts['attempt'] ?? 1),
            'dispatch' => (string)($opts['dispatch_method'] ?? 'none'),
            'ts' => self::now_mysql_est(),
        ];
    }

    /* ===================== ТЕСТ-СІД ===================== */

    /** Створити рандомний лід (тільки для сіду/демо) */
    public static function leadrouter_create_random_lead()
    {
        // використовуємо create_lead_simple з генерацією випадкових даних
        $names = ['John Doe', 'Jane Smith', 'Mike Johnson', 'Emily Davis', 'Chris Brown'];
        $emails = ['test1@example.com', 'test2@example.com', 'test3@example.com'];
        $phones = ['555-1234', '555-5678', '555-8765'];
        $brands = ['Toyota', 'Honda', 'Ford', 'Volkswagen', 'BMW'];
        $models = ['Camry', 'Civic', 'Focus', 'Passat', 'X5'];
        $conds = ['Running', 'NonRunning'];
        $cities = [
            ['city' => 'New York', 'state' => 'NY', 'zip' => '10001'],
            ['city' => 'Miami', 'state' => 'FL', 'zip' => '33101'],
            ['city' => 'Chicago', 'state' => 'IL', 'zip' => '60601'],
            ['city' => 'Houston', 'state' => 'TX', 'zip' => '77001'],
            ['city' => 'Los Angeles', 'state' => 'CA', 'zip' => '90001'],
        ];

        $from = $cities[array_rand($cities)];
        $to = $cities[array_rand($cities)];

        $data = [
            'name' => $names[array_rand($names)],
            'email' => $emails[array_rand($emails)],
            'phone' => $phones[array_rand($phones)],
            'est_ship_date' => (new DateTimeImmutable('now', new DateTimeZone(self::EST_TZ)))->modify('+' . rand(1, 10) . ' days')->format('Y-m-d'),
            'vehicle_year' => rand(2000, 2022),
            'vehicle_brand' => $brands[array_rand($brands)],
            'vehicle_model' => $models[array_rand($models)],
            'vehicle_condition' => $conds[array_rand($conds)],
            'from_city' => $from['city'],
            'from_state' => $from['state'],
            'from_zip' => $from['zip'],
            'to_city' => $to['city'],
            'to_state' => $to['state'],
            'to_zip' => $to['zip'],
            'dispatch_method' => 'seed',
        ];
        return self::create_lead_simple($data);
    }

    /**
     * Створити простий лід напряму в БД (для тестів і сіду)
     *
     * @param array $data Дані ліда
     * @return int|WP_Error ID створеного ліда або WP_Error
     */
    public static function create_lead_simple(array $data)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'leadrouter_leads';

        $now = self::now_mysql_est();


        /**
         * 📞 Нормалізація телефону
         */
        if (isset($data['phone'])) {
            $normalizedPhone = leadrouter_normalize_phone($data['phone'], ['strict' => false]);
            if (is_wp_error($normalizedPhone)) {
                return $normalizedPhone; // або логування/м’яка обробка
            } elseif (is_array($normalizedPhone) && isset($normalizedPhone['warning'])) {
                // soft mode: можна залогувати warning
                LeadRouter_Flow::log_event(0, 'warning_normalization', [
                    'field' => 'phone',
                    'original' => $normalizedPhone['original'],
                    'warning' => $normalizedPhone['warning']
                ]);
                $data['phone'] = $normalizedPhone['original'];
            } else {
                $data['phone'] = $normalizedPhone;
            }
        }

        /**
         * 📅 Нормалізація дати
         */
        if (isset($data['est_ship_date'])) {
            $normalizedDate = leadrouter_normalize_date($data['est_ship_date'], ['strict' => false]);
            if (is_wp_error($normalizedDate)) {
                return $normalizedDate;
            } elseif (is_array($normalizedDate) && isset($normalizedDate['warning'])) {
                LeadRouter_Flow::log_event(0, 'warning_normalization', [
                    'field' => 'est_ship_date',
                    'original' => $normalizedDate['original'],
                    'warning' => $normalizedDate['warning']
                ]);
                $data['est_ship_date'] = $now; // напр. дефолт на сьогодні
            } else {
                $data['est_ship_date'] = $normalizedDate;
            }
        }


        $insert_data = [
            'name' => sanitize_text_field($data['name'] ?? ''),
            'email' => sanitize_email($data['email'] ?? ''),
            'phone' => sanitize_text_field($data['phone'] ?? ''),
            'vehicle_year' => (int)($data['vehicle_year'] ?? 0),
            'vehicle_brand' => sanitize_text_field($data['vehicle_brand'] ?? ''),
            'vehicle_model' => sanitize_text_field($data['vehicle_model'] ?? ''),
            'vehicle_condition' => sanitize_text_field($data['vehicle_condition'] ?? ''),
            'vehicle_bodytype' => sanitize_text_field($data['vehicle_bodytype'] ?? ''),
            'from_city' => sanitize_text_field($data['from_city'] ?? ''),
            'from_state' => sanitize_text_field($data['from_state'] ?? ''),
            'from_zip' => sanitize_text_field($data['from_zip'] ?? ''),
            'to_city' => sanitize_text_field($data['to_city'] ?? ''),
            'to_state' => sanitize_text_field($data['to_state'] ?? ''),
            'to_zip' => sanitize_text_field($data['to_zip'] ?? ''),
            'est_ship_date' => sanitize_text_field($data['est_ship_date'] ?? $now),
            'created_at' => $now,
            'dispatch_method' => sanitize_text_field($data['dispatch_method'] ?? 'manual'),

            'utm_source' => sanitize_text_field($data['utm_source'] ?? ''),
            'utm_content' => sanitize_text_field($data['utm_content'] ?? ''),
            'utm_medium' => sanitize_text_field($data['utm_medium'] ?? ''),
            'utm_term' => sanitize_text_field($data['utm_term'] ?? ''),
            'utm_campaign' => sanitize_text_field($data['utm_campaign'] ?? ''),

            'status' => 'new',
            'attempts_total' => 0,
            // явний нульовий datetime: int 0 сюди проходив лише завдяки
            // нестрогому режиму MySQL
            'next_attempt_at' => '0000-00-00 00:00:00',
            'last_error_code' => '',
            // так само явний нульовий datetime, як і next_attempt_at вище
            'last_error_at' => '0000-00-00 00:00:00',
            'await_groups' => null,
        ];

        // Нормалізовані ключі для пошуку дублів (анти-дубль у крон-і нових лідів)
        $insert_data += leadrouter_lead_norm_columns($insert_data['phone'], $insert_data['email']);

        $format = [
            '%s', '%s', '%s',
            '%d', '%s', '%s', '%s', '%s',
            '%s', '%s', '%s',
            '%s', '%s', '%s',
            '%s', '%s', '%s', '%s'
        ];

        $ok = $wpdb->insert($table, $insert_data, $format);

        if (!$ok) {
            return new WP_Error(
                'leadrouter_insert_failed',
                'Не вдалося створити лід',
                ['db_error' => $wpdb->last_error, 'data' => $insert_data]
            );
        }

        return (int)$wpdb->insert_id;
    }


    /**
     * Генерує N лідів, кожен розсилає всім доступним партнерам.
     * Запуск: /wp-admin/?flow_seed=1&_wpnonce=...
     */
    public static function run_seed_ui(int $n = 10, array $opts = []): void
    {
        if (!is_admin()) return;
        if (!current_user_can('manage_options')) return;
        if (!isset($_GET['flow_seed']) || $_GET['flow_seed'] !== '1') return;
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'leadrouter_flow_seed')) return;
        if (defined('DOING_CRON') && DOING_CRON) return;
        if (defined('DOING_AJAX') && DOING_AJAX) return;
        if (defined('REST_REQUEST') && REST_REQUEST) return;

        ob_start();
        echo '<div class="wrap"><h1>LeadRouter Flow — Broadcast SEED</h1>';
        echo '<p>Створюємо ' . (int)$n . ' випадкових лідів і розсилаємо всім доступним партнерам їхньої групи (EST time).</p>';

        echo '<table class="widefat striped" style="max-width:1200px"><thead><tr>';
        echo '<th>#</th><th>Lead ID</th><th>Group (post_id)</th><th>Partners total</th><th>Sent</th><th>Failed</th><th>Details</th>';
        echo '</tr></thead><tbody>';

        for ($i = 1; $i <= $n; $i++) {
            $lead_id = self::leadrouter_create_random_lead();
            $res = is_wp_error($lead_id)
                ? $lead_id
                : self::dispatch_broadcast((int)$lead_id, $opts);

            echo '<tr>';
            echo '<td>' . (int)$i . '</td>';

            if (is_wp_error($res)) {
                $data = $res->get_error_data();
                echo '<td>' . (int)($lead_id instanceof WP_Error ? 0 : $lead_id) . '</td>';
                echo '<td>' . esc_html((string)($data['group_post_id'] ?? '-')) . '</td>';
                echo '<td>0</td><td>0</td><td>0</td>';
                echo '<td style="color:#b00">' . esc_html($res->get_error_message()) . '</td>';
            } else {
                $total = count($res['all']);
                $sent = count($res['sent']);
                $failed = count($res['failed']);

                echo '<td>' . (int)$res['lead_id'] . '</td>';
                echo '<td>' . esc_html($res['group_name'] . ' (post_id ' . $res['group_post_id'] . ')') . '</td>';
                echo '<td>' . (int)$total . '</td>';
                echo '<td>' . (int)$sent . '</td>';
                echo '<td>' . (int)$failed . '</td>';

                $snippet = array_slice($res['all'], 0, 5);
                $cells = [];
                foreach ($snippet as $e) {
                    $cells[] = sprintf(
                        '%s (ID %d): %s%s',
                        esc_html($e['partner_name']),
                        (int)$e['partner_id'],
                        esc_html($e['status']),
                        $e['error'] ? ' — ' . esc_html($e['error']) : ''
                    );
                }
                if ($total > 5) {
                    $cells[] = '… +' . ($total - 5) . ' more';
                }
                echo '<td>' . implode('<br/>', $cells) . '</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table><p><a href="' . esc_url(admin_url('admin.php?page=leadrouter')) . '">← Повернутись в адмінку</a></p></div>';
        echo ob_get_clean();
        exit;
    }


    /**
     * Повне очищення всіх лідів і логів + скидання станів у таблиці груп.
     * УВАГА: НЕЗВОРОТНО.
     *
     * @param array $opts [
     *   'confirm' => bool  // обов’язково true, інакше поверне WP_Error
     * ]
     * @return array|WP_Error
     */
    public static function purge_all_leads_and_logs(array $opts = [])
    {
        if (is_admin() && !current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'Недостатньо прав для purge');
        }
        if (empty($opts['confirm'])) {
            return new WP_Error('purge_not_confirmed', 'Потрібно явне підтвердження: ["confirm" => true]');
        }

        global $wpdb;

        $tables = [
            'leads' => $wpdb->prefix . 'leadrouter_leads',
            'partner_logs' => $wpdb->prefix . 'leadrouter_partner_logs',
            'logs' => $wpdb->prefix . 'leadrouter_logs',
            'groups' => $wpdb->prefix . 'leadrouter_groups',
            'send_log' => $wpdb->prefix . 'leadrouter_send_log',
        ];

        $ts_est = self::now_mysql_est();
        $result = [
            'timestamp_est' => $ts_est,
            'tables' => $tables,
            'queries' => [],
        ];

        do_action('leadrouter_before_purge', $opts, $tables);

        // TRUNCATE leads
        $q1 = "TRUNCATE TABLE {$tables['leads']}";
        $ok1 = $wpdb->query($q1);
        if ($ok1 === false) {
            $err = new WP_Error('purge_error', 'Помилка TRUNCATE leadrouter_leads', ['sql' => $q1, 'db_error' => $wpdb->last_error]);
            do_action('leadrouter_after_purge', $opts, $err);
            return $err;
        }
        $result['queries'][] = $q1;

        // TRUNCATE partner logs
        $q2 = "TRUNCATE TABLE {$tables['partner_logs']}";
        $ok2 = $wpdb->query($q2);
        if ($ok2 === false) {
            $err = new WP_Error('purge_error', 'Помилка TRUNCATE leadrouter_partner_logs', ['sql' => $q2, 'db_error' => $wpdb->last_error]);
            do_action('leadrouter_after_purge', $opts, $err);
            return $err;
        }
        $result['queries'][] = $q2;

        // TRUNCATE general logs
        $q3 = "TRUNCATE TABLE {$tables['logs']}";
        $ok3 = $wpdb->query($q3);
        if ($ok3 === false) {
            $err = new WP_Error('purge_error', 'Помилка TRUNCATE leadrouter_logs', ['sql' => $q3, 'db_error' => $wpdb->last_error]);
            do_action('leadrouter_after_purge', $opts, $err);
            return $err;
        }
        $result['queries'][] = $q3;


        // TRUNCATE send logs
        $q5 = "TRUNCATE TABLE {$tables['send_log']}";
        $ok5 = $wpdb->query($q5);
        if ($ok5 === false) {
            $err = new WP_Error('purge_error', 'Помилка TRUNCATE leadrouter_send_log', ['sql' => $q5, 'db_error' => $wpdb->last_error]);
            do_action('leadrouter_after_purge', $opts, $err);
            return $err;
        }
        $result['queries'][] = $q5;


        // RESET groups: eff=0, active=1
        $q4 = "UPDATE {$tables['groups']} SET eff = 0, active = 1";
        $ok4 = $wpdb->query($q4);
        if ($ok4 === false) {
            $err = new WP_Error('purge_error', 'Помилка RESET у leadrouter_groups', ['sql' => $q4, 'db_error' => $wpdb->last_error]);
            do_action('leadrouter_after_purge', $opts, $err);
            return $err;
        }
        $result['queries'][] = $q4;

        do_action('leadrouter_after_purge', $opts, $result);
        return $result;
    }

}
