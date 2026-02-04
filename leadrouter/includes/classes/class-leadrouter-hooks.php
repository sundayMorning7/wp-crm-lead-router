<?php
/**
 * Class LeadRouter_Hooks
 * Глобальні хуки для CPT leadrouter_partner та leadrouter_group.
 *
 * ВИМАГАЄ наявності функції leadrouter_recalc_sum_weight( WP_Post $post )
 */

defined('ABSPATH') || exit;

class LeadRouter_Hooks
{

    /** @var bool Реентерабельний прапорець, щоб уникнути рекурсії */
    private static $running = false;

    /** @var string[] Ключі мета, що тригерять перерахунок */
    private static $weight_meta_keys = [
        '_leadrouter_partner_sun_limit',
        '_leadrouter_partner_sat_limit',
        '_leadrouter_partner_fri_limit',
        '_leadrouter_partner_thu_limit',
        '_leadrouter_partner_wed_limit',
        '_leadrouter_partner_tue_limit',
        '_leadrouter_partner_mon_limit'
    ];

    public static function init(): void
    {
        // Перерахунок при додаванні/оновленні вагових мета у партнера
        add_action('added_post_meta', [__CLASS__, 'maybe_recalc_on_meta_change'], 10, 4);
        add_action('updated_post_meta', [__CLASS__, 'maybe_recalc_on_meta_change'], 10, 4);

        // Перерахунок при видаленні партнера (ще є доступ до поста)

        add_action('trashed_post', [__CLASS__, 'recalc_on_partner_delete'], 10);
        add_action('before_delete_post', [__CLASS__, 'recalc_on_partner_delete'], 10);


        // Перерахунок при поверненні з кошика партнера/групи
        add_action('untrashed_post', [__CLASS__, 'recalc_on_untrash'], 10);

        // Коли партнер або група стає publish → перерахунок
        add_action('transition_post_status', [__CLASS__, 'recalc_on_publish'], 10, 3);

        // Очистка кастомної таблиці при видаленні групи
        add_action('trashed_post', [__CLASS__, 'cleanup_group_rows_on_delete'], 10);
        add_action('before_delete_post', [__CLASS__, 'cleanup_group_rows_on_delete'], 10);
    }

        /**
         * Перерахунок при зміні потрібних метаполів у leadrouter_partner.
         */
        public
        static function maybe_recalc_on_meta_change($meta_id, $post_id, $meta_key, $meta_value): void
        {
            if (get_post_type($post_id) !== 'leadrouter_partner') {
                return;
            }
            if (!in_array($meta_key, self::$weight_meta_keys, true)) {
                return;
            }
            $post = get_post($post_id);
            if (!$post) {
                return;
            }
            self::with_guard(function () use ($post) {
                leadrouter_recalc_sum_weight($post);
            });
        }

        /**
         * Перерахунок при постійному видаленні партнера.
         */
        public
        static function recalc_on_partner_delete(int $post_id): void
        {
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'leadrouter_partner') {
                return;
            }
            self::with_guard(function () use ($post) {
                leadrouter_recalc_sum_weight($post);
            });
        }

        /**
         * Перерахунок при поверненні з кошика партнера або групи.
         */
        public
        static function recalc_on_untrash(int $post_id): void
        {
            $post = get_post($post_id);
            if (!$post) {
                return;
            }
            if ($post->post_type !== 'leadrouter_partner' /*&& $post->post_type !== 'leadrouter_group'*/) {
                return;
            }
            self::with_guard(function () use ($post) {
                leadrouter_recalc_sum_weight($post);
            });
        }

        /**
         * Перерахунок, коли leadrouter_partner або leadrouter_group стає publish.
         */
        public
        static function recalc_on_publish(string $new_status, string $old_status, WP_Post $post): void
        {
            if ($post->post_type !== 'leadrouter_partner' && $post->post_type !== 'leadrouter_group') {
                return;
            }
            if ($new_status !== 'publish') {
                return;
            }
            self::with_guard(function () use ($post) {
                leadrouter_recalc_sum_weight($post);
            });
        }

        /**
         * Чистка рядків з кастомної таблиці при видаленні leadrouter_group.
         * Видалення — саме постійне (не trash).
         */
        public
        static function cleanup_group_rows_on_delete(int $post_id): void
        {
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'leadrouter_group') {
                return;
            }
            global $wpdb;
            $table_groups = $wpdb->prefix . 'leadrouter_groups';
            // видаляємо всі рядки, що прив'язані до цього поста-групи
            $wpdb->delete($table_groups, ['post_id' => (int)$post_id], ['%d']);
            // Можна увімкнути лог:
            // error_log(sprintf('[LeadRouter] Deleted %d rows from %s for group post_id=%d', $wpdb->rows_affected, $table_groups, $post_id));
        }

        /**
         * Хелпер: викликати callback з анти-рекурсійним прапорцем.
         */
        private
        static function with_guard(callable $cb): void
        {
            if (self::$running) {
                return;
            }
            self::$running = true;
            try {
                $cb();
            } finally {
                self::$running = false;
            }
        }
    }
