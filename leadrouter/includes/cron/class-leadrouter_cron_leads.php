<?php

if ( ! class_exists( 'LeadRouter_Cron_Leads' ) ) {

    class LeadRouter_Cron_Leads {

        public static function init() {
            add_action( 'leadrouter_cron_leads_dispatch', [ __CLASS__, 'run' ] );
        }

        /**
         * Основний крон-хендлер: обробляє ОДИН лід зі статусом new/retry
         */
        public static function run() {
            if ( ! class_exists( 'LeadRouter_Flow' ) ) {
                return;
            }

            $now = time();

            // Беремо кілька кандидатів, щоб у PHP вибрати того, у кого настав час
            $q = new WP_Query( [
                'post_type'      => 'leadrouter_lead', // підкоригуй, якщо в тебе інший CPT
                'post_status'    => 'publish',
                'posts_per_page' => 5,
                'orderby'        => 'meta_value_num',
                'meta_key'       => '_lr_created_at',
                'order'          => 'ASC',
                'meta_query'     => [
                    [
                        'key'     => '_lr_status',
                        'value'   => [ 'new', 'retry' ],
                        'compare' => 'IN',
                    ],
                ],
                'fields' => 'ids',
            ] );

            if ( empty( $q->posts ) ) {
                return;
            }

            $lead_id_to_process = 0;

            foreach ( $q->posts as $lead_id ) {
                $next_attempt = (int) get_post_meta( $lead_id, '_lr_next_attempt_at', true );
                if ( $next_attempt === 0 || $next_attempt <= $now ) {
                    $lead_id_to_process = (int) $lead_id;
                    break;
                }
            }

            if ( ! $lead_id_to_process ) {
                // Нема жодного, кому вже настав час
                return;
            }

            // Лочимо: ставимо processing + інкремент спроб
            $current_status = get_post_meta( $lead_id_to_process, '_lr_status', true );
            if ( $current_status !== 'new' && $current_status !== 'retry' ) {
                // Хтось вже встиг змінити статус
                return;
            }

            $attempts_total = (int) get_post_meta( $lead_id_to_process, '_lr_attempts_total', true );
            $attempts_total++;

            update_post_meta( $lead_id_to_process, '_lr_status', 'processing' );
            update_post_meta( $lead_id_to_process, '_lr_attempts_total', $attempts_total );

            // Викликаємо Flow
            $result = LeadRouter_Flow::dispatch_broadcast( $lead_id_to_process, [
                'group_meta_key'   => '_leadrouter_partner_group',
                'statuses'         => [ 'queued', 'sent', 'accepted' ],
                'initial_status'   => 'sent',
                'dispatch_method'  => 'cron_auto_first',
                'queue_if_closed'  => true,
            ] );

            self::update_lead_status_by_result( $lead_id_to_process, $result );
        }

        /**
         * Класифікація результату й оновлення _lr_status / next_attempt / last_error
         *
         * !!! ВАЖЛИВО: підлаштуй цей метод під реальну структуру $result твого Flow !!!
         */
        protected static function update_lead_status_by_result( $lead_id, $result ) {
            $now = time();

            // ↓↓↓ тут дуже груба, узагальнена логіка — адаптуй під свій $result ↓↓↓

            $has_success   = ! empty( $result['success'] ) || ! empty( $result['accepted'] );
            $all_closed    = ! empty( $result['all_groups_closed'] ) || ( ! empty( $result['meta']['all_groups_closed'] ) );
            $only_retryable = ! empty( $result['only_retryable'] );
            $hard_fail     = ! empty( $result['hard_fail'] );

            if ( $has_success ) {
                update_post_meta( $lead_id, '_lr_status', 'sent' );
                update_post_meta( $lead_id, '_lr_next_attempt_at', 0 );
                update_post_meta( $lead_id, '_lr_last_error_code', '' );
                update_post_meta( $lead_id, '_lr_last_error_at', 0 );
                return;
            }

            if ( $all_closed ) {
                $delay = rand( 60, 300 ); // 1–5 хв
                update_post_meta( $lead_id, '_lr_status', 'await' );
                update_post_meta( $lead_id, '_lr_next_attempt_at', $now + $delay );
                update_post_meta( $lead_id, '_lr_last_error_code', 'all_groups_closed' );
                update_post_meta( $lead_id, '_lr_last_error_at', $now );
                // Тут же можна зберегти _lr_await_groups
                return;
            }

            if ( $only_retryable ) {
                $delay = rand( 30, 120 ); // 30–120 сек
                update_post_meta( $lead_id, '_lr_status', 'retry' );
                update_post_meta( $lead_id, '_lr_next_attempt_at', $now + $delay );
                update_post_meta( $lead_id, '_lr_last_error_code', 'retryable' );
                update_post_meta( $lead_id, '_lr_last_error_at', $now );
                return;
            }

            if ( $hard_fail ) {
                update_post_meta( $lead_id, '_lr_status', 'failed' );
                update_post_meta( $lead_id, '_lr_next_attempt_at', 0 );
                update_post_meta( $lead_id, '_lr_last_error_code', 'hard_fail' );
                update_post_meta( $lead_id, '_lr_last_error_at', $now );
                return;
            }

            // Фолбек — вважаємо, що немає куди відправити
            update_post_meta( $lead_id, '_lr_status', 'failed' );
            update_post_meta( $lead_id, '_lr_next_attempt_at', 0 );
            update_post_meta( $lead_id, '_lr_last_error_code', 'unknown' );
            update_post_meta( $lead_id, '_lr_last_error_at', $now );
        }
    }
}
