<?php
if ( ! class_exists( 'LeadRouter_Cron_Await_Leads' ) ) {

    class LeadRouter_Cron_Await_Leads {

        const CRON_HOOK       = 'leadrouter_cron_dispatch_await_lead';
        const LOCK_KEY        = 'leadrouter_cron_await_lock';
        const OPTION_NEXT_TS  = 'leadrouter_cron_await_next_ts';
        const STATUS_AWAIT    = 'await';
        // sent_partial — shared-лід, проданий менше ніж N разів: добираємо
        // відсутні копії тим самим шляхом, поки не скінчився день
        const STATUSES_RETRY  = ['await', 'sent_partial'];
        const STATUS_OK = 'sent';       // після успішної відправки

        public static function init() {
            add_filter( 'cron_schedules', [ __CLASS__, 'add_every_minute_schedule' ] );
            add_action( 'wp', [ __CLASS__, 'schedule_event' ] );
            add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
        }

        public static function add_every_minute_schedule( $schedules ) {
            if ( ! isset( $schedules['every_minute'] ) ) {
                $schedules['every_minute'] = [
                    'interval' => 60,
                    'display'  => __( 'Every Minute (LeadRouter)', 'leadrouter' ),
                ];
            }
            return $schedules;
        }

        public static function schedule_event() {
            if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
                wp_schedule_event( time() + 60, 'every_minute', self::CRON_HOOK );
            }
        }

        public static function run() {
            global $wpdb;

/*
            $log_file = WP_CONTENT_DIR . '/leadrouter-cronawait.log';

            file_put_contents(
                $log_file,
                'First start \n',
                FILE_APPEND
            );*/


            // простий лок, щоб не було накладень
            if ( get_transient( self::LOCK_KEY ) ) {
                return;
            }
            set_transient( self::LOCK_KEY, 1, 55 );

            $now     = time();
            $next_ts = (int) get_option( self::OPTION_NEXT_TS, 0 );

            $min = (int) carbon_get_theme_option('leadrouter_pause_min');
            $max = (int) carbon_get_theme_option('leadrouter_pause_max');

            if ($min <= 0) {
                $min = 10;
            }

            if ($max <= 0) {
                $max = 20;
            }

            if ($min > $max) {
                $tmp = $min;
                $min = $max;
                $max = $tmp;
            }


            // якщо немає запланованого часу — ставимо новий інтервал і виходимо
            if ( ! $next_ts ) {
                $delay_min = rand( $min, $max );
                $next_ts   = $now + $delay_min * 60;
                update_option( self::OPTION_NEXT_TS, $next_ts );
                delete_transient( self::LOCK_KEY );
                return;
            }


            // ще не настав час — просто чекаємо
            if ( $now < $next_ts ) {
                delete_transient( self::LOCK_KEY );
                return;
            }

/*
            file_put_contents(
                $log_file,
                'before db'. json_encode(array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );*/

            $table = $wpdb->prefix . 'leadrouter_leads';

            // 0) термін давності досилки: прострочені await/sent_partial → expired
            self::expire_stale_retries($table);

            // 1) беремо один лід зі статусом await
            // Дві різні ролі, які легко сплутати:
            // - next_attempt_at — ПРАВО пробувати зараз (тільки WHERE). Лід,
            //   що зафейлив, випадає з вибірки на час бекофу, тому «найстаріший
            //   завжди» більше не блокує чергу (head-of-line blocking);
            // - created_at — ПРІОРИТЕТ у черзі (тільки ORDER BY): серед тих,
            //   хто вже має право на спробу, першим іде найстаріший лід.
            // Сортувати за next_attempt_at не можна: бекоф росте зі спробами,
            // тож старі ліди систематично відсувались би у хвіст, а свіжі
            // (нульовий datetime = мінімум) ставали б поперед усіх.
            // id ASC — детермінований тай-брейкер: created_at збігається.
            $statuses     = self::retry_statuses();
            $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
            $now_est      = leadrouter_now_mysql_est();

            $lead = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
         WHERE status IN ({$placeholders})
           AND (next_attempt_at IS NULL
                OR next_attempt_at = '0000-00-00 00:00:00'
                OR next_attempt_at <= %s)
         ORDER BY created_at ASC, id ASC
         LIMIT 1",
                    ...array_merge($statuses, [$now_est])
                ),
                ARRAY_A
            );

/*
            file_put_contents(
                'test3',
                'after db'. json_encode($lead, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );
*/

            if ( ! $lead ) {
                // немає лідів в await → обнуляємо таймер, щоб при появі нових все починалось з нуля
                update_option( self::OPTION_NEXT_TS, 0 );
                delete_transient( self::LOCK_KEY );
                return;
            }

            $lead_id = (int) $lead['id'];

            // Проверка: если имя начинается с 'test', не отправлять лид партнёрам
            $lead_name = isset($lead['name']) ? trim(strtolower($lead['name'])) : '';
            if (strpos($lead_name, 'test') === 0) {
                // Просто помечаем как 'sent', не отправляем
                $wpdb->update(
                    $table,
                    [ 'status' => self::STATUS_OK ],
                    [ 'id' => $lead_id ],
                    [ '%s' ],
                    [ '%d' ]
                );

                $log_file = WP_CONTENT_DIR . '/leadrouter-cron.log';
                $log_payload = [
                    'timestamp' => current_time('mysql'),
                    'lead_id'   => $lead_id,
                    'result'    => 'Skipped test lead',
                ];
                file_put_contents(
                    $log_file,
                    json_encode($log_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                    FILE_APPEND
                );

                delete_transient(self::LOCK_KEY);
                return;
            }

            // 2) відправка через Flow
            $result = LeadRouter_Flow::dispatch_broadcast( $lead_id, [
                'group_meta_key'   => '_leadrouter_partner_group',
                'statuses'         => [ 'queued', 'sent', 'accepted' ],
                'initial_status'   => 'sent',
                'dispatch_method'  => 'auto_cron_await_lead',
                'queue_if_closed'  => true,
            ] );

            // 🛡 dispatch_broadcast може повернути WP_Error (нема групи, нема
            // партнерів, відхилений force-партнер). WP_Error не реалізує
            // ArrayAccess — масивний доступ нижче в PHP 8 дає Fatal Error,
            // `??` його не гасить: лок не знімається і крон пропускає тік.
            if ( is_wp_error( $result ) ) {
                LeadRouter_Flow::log_error( 'await cron: dispatch_broadcast returned WP_Error', [
                    'lead_id' => $lead_id,
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ] );
                // бекоф, щоб помилковий лід не блокував голову черги
                self::schedule_next_attempt( $lead_id );
                update_option( self::OPTION_NEXT_TS, 0 );
                delete_transient( self::LOCK_KEY );
                return;
            }

            $lead_status = $result['summary']['lead_status'] ?? 'error';

            // Бекоф: не дійшов до термінального 'sent' — у хвіст черги;
            // дійшов — обнуляємо лічильники, щоб досилка наступної копії
            // не тягла бекоф попередньої.
            if ( $lead_status === self::STATUS_OK ) {
                self::reset_attempts( $lead_id );
            } else {
                self::schedule_next_attempt( $lead_id );
            }


/*
            file_put_contents(
                $log_file,
                'after broadcast'. json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );

            // 3) оновлюємо поле status ліда згідно summary
            $wpdb->update(
                $table,
                [ 'status' => $lead_status ],
                [ 'id' => $lead_id ],
                [ '%s' ],
                [ '%d' ]
            );*/

            // 4) інтервал:
            // - для skipped / error — "обнуляємо" (на наступній хвилині призначиться новий інтервал)
            // - для sent / await — ставимо новий рандом 15–30 хв
            if ( in_array( $lead_status, [ 'skipped', 'error' ], true ) ) {
                update_option( self::OPTION_NEXT_TS, 0 );
            } else {
                $delay_min = rand( $min, $max );
                $next_ts   = $now + $delay_min * 60;
                update_option( self::OPTION_NEXT_TS, $next_ts );
            }

            delete_transient( self::LOCK_KEY );
        }

        /**
         * Статуси, які добирає цей крон. Автодосилку копій shared-лідів
         * (sent_partial) можна вимкнути галочкою в налаштуваннях — тоді
         * добираємо лише первинні await, а копії дошиває менеджер вручну.
         * Незбережена опція = увімкнено: щоб після оновлення на сайті, де
         * налаштування ще не зберігали, поведінка не змінилась мовчки.
         */
        private static function retry_statuses(): array {
            if ( function_exists( 'carbon_get_theme_option' ) ) {
                // сирий рядок опції: null — налаштування ще не зберігали
                $raw = get_option( '_leadrouter_shared_topup_enabled', null );
                if ( $raw !== null && ! (bool) carbon_get_theme_option( 'leadrouter_shared_topup_enabled' ) ) {
                    return [ self::STATUS_AWAIT ];
                }
            }

            return self::STATUSES_RETRY;
        }

        /**
         * Бекоф у хвилинах за номером спроби. Таблицю можна крутити без
         * релізу фільтром leadrouter_await_backoff_minutes.
         */
        private static function backoff_minutes( int $attempts_total, int $lead_id ): int {
            if ( $attempts_total <= 2 ) {
                $minutes = 2;
            } elseif ( $attempts_total <= 4 ) {
                $minutes = 8;
            } else {
                $minutes = 30;
            }

            return (int) apply_filters( 'leadrouter_await_backoff_minutes', $minutes, $attempts_total, $lead_id );
        }

        /**
         * Невдала спроба: attempts_total++, next_attempt_at = now + бекоф.
         * Лід не втрачає місце в черзі (вона за created_at) — він просто
         * випадає з вибірки на час бекофу і повертається на своє місце за віком.
         */
        private static function schedule_next_attempt( int $lead_id ): void {
            global $wpdb;
            $table = $wpdb->prefix . 'leadrouter_leads';

            $attempts = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT attempts_total FROM {$table} WHERE id = %d",
                $lead_id
            ) ) + 1;

            $minutes = self::backoff_minutes( $attempts, $lead_id );
            $next    = ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/New_York' ) ) )
                ->modify( "+{$minutes} minutes" )
                ->format( 'Y-m-d H:i:s' );

            // GREATEST: dispatch міг щойно запаркувати лід далі в майбутнє
            // (shared_pool_exhausted → до півночі EST) — бекоф не має
            // відкочувати це назад. COALESCE, бо GREATEST(NULL, ...) = NULL.
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table}
                    SET attempts_total  = %d,
                        next_attempt_at = GREATEST(COALESCE(next_attempt_at, '0000-00-00 00:00:00'), %s)
                  WHERE id = %d",
                $attempts,
                $next,
                $lead_id
            ) );
        }

        /** Успішна відправка: обнуляємо лічильники бекофу. */
        private static function reset_attempts( int $lead_id ): void {
            global $wpdb;

            $wpdb->update(
                $wpdb->prefix . 'leadrouter_leads',
                [ 'attempts_total' => 0, 'next_attempt_at' => '0000-00-00 00:00:00' ],
                [ 'id' => $lead_id ],
                [ '%d', '%s' ],
                [ '%d' ]
            );
        }

        /**
         * Межа давності досилки за налаштуваннями Dispatch.
         * null — безстроково (галочка увімкнена або налаштування недоступні).
         */
        private static function retry_cutoff(): ?string {
            if ( ! function_exists( 'carbon_get_theme_option' ) ) {
                return null;
            }
            if ( (bool) carbon_get_theme_option( 'leadrouter_retry_no_expiry' ) ) {
                return null;
            }

            $now   = new DateTimeImmutable( 'now', new DateTimeZone( 'America/New_York' ) );
            $hours = (int) carbon_get_theme_option( 'leadrouter_retry_max_hours' );

            if ( $hours > 0 ) {
                return $now->modify( "-{$hours} hours" )->format( 'Y-m-d H:i:s' );
            }

            // 0/порожньо — «до кінця доби EST створення»: усе, що створено до
            // сьогоднішньої півночі EST, вважається простроченим
            return $now->format( 'Y-m-d 00:00:00' );
        }

        /**
         * Прострочені await/sent_partial переводимо у термінальний expired,
         * щоб вони не висіли «в очікуванні» вічно. created_at у БД — EST.
         */
        private static function expire_stale_retries( string $table ): void {
            global $wpdb;

            $cutoff = self::retry_cutoff();
            if ( $cutoff === null ) {
                return;
            }

            // при вимкненій автодосилці sent_partial не протерміновуємо —
            // ці ліди чекають менеджера, а не expired
            $statuses     = self::retry_statuses();
            $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$table}
                  WHERE status IN ({$placeholders}) AND created_at < %s
                  ORDER BY id ASC
                  LIMIT 50",
                ...array_merge( $statuses, [ $cutoff ] )
            ) );
            if ( empty( $ids ) ) {
                return;
            }

            $now_est = ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/New_York' ) ) )->format( 'Y-m-d H:i:s' );
            $in      = implode( ',', array_map( 'intval', $ids ) );

            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table}
                    SET status = 'expired', last_error_code = 'retry_expired', last_error_at = %s
                  WHERE id IN ({$in})",
                $now_est
            ) );

            foreach ( $ids as $id ) {
                LeadRouter_Flow::log_event( (int) $id, 'retry_expired', [ 'cutoff' => $cutoff ] );
            }
        }
    }
}

// десь у bootstrap плагіну:
