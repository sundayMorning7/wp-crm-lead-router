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

    /** @var string Ключ мета зі зв'язком партнер→група */
    private static $group_meta_key = '_leadrouter_partner_group';

    /** @var array Старі значення групи, зняті до оновлення мета: post_id => group_id */
    private static $old_group = [];

    public static function init(): void
    {
        // Перерахунок при додаванні/оновленні вагових мета у партнера
        add_action('added_post_meta', [__CLASS__, 'maybe_recalc_on_meta_change'], 10, 4);
        add_action('updated_post_meta', [__CLASS__, 'maybe_recalc_on_meta_change'], 10, 4);

        // Зміна групи партнера змінює ваги ДВОХ груп — старої і нової.
        // Раніше цей випадок не оброблявся взагалі: ваги лишались старими,
        // поки хтось не пересаче денний ліміт.
        add_filter('update_post_metadata', [__CLASS__, 'capture_old_group'], 10, 4);
        add_action('added_post_meta', [__CLASS__, 'maybe_recalc_on_group_change'], 10, 4);
        add_action('updated_post_meta', [__CLASS__, 'maybe_recalc_on_group_change'], 10, 4);

        // Перерахунок при видаленні партнера (ще є доступ до поста)

        add_action('trashed_post', [__CLASS__, 'recalc_on_partner_delete'], 10);
        add_action('before_delete_post', [__CLASS__, 'recalc_on_partner_delete'], 10);


        // Перерахунок при поверненні з кошика партнера/групи
        add_action('untrashed_post', [__CLASS__, 'recalc_on_untrash'], 10);

        // Коли партнер або група стає publish → перерахунок
        add_action('transition_post_status', [__CLASS__, 'recalc_on_publish'], 10, 3);

        // Перейменували групу — одразу синкаємо назву в таблицю.
        // Без цього назва оновлювалась лише при перерахунку ваг, а до того
        // всюди (панель, резервні групи, логи) світилась стара.
        add_action('post_updated', [__CLASS__, 'sync_group_name_on_rename'], 10, 3);

        // Група в кошику: НЕ видаляємо рядок, а лише гасимо active.
        // Рядок потрібен далі, бо lead_assignments.group_id — це внутрішній id
        // таблиці, і без рядка історія призначень втрачає назву групи.
        // Умова active = 1 і так стоїть у диспетчері та в усіх списках, тож
        // група одразу зникає звідусіль.
        add_action('trashed_post', [__CLASS__, 'deactivate_group_row_on_trash'], 10);
        add_action('untrashed_post', [__CLASS__, 'reactivate_group_row_on_untrash'], 10);

        // Остаточне видалення — рядок уже не потрібен (поста теж немає)
        add_action('before_delete_post', [__CLASS__, 'cleanup_group_rows_on_delete'], 10);

        // Разовий backfill назв груп, зіпсованих старою логікою
        add_action('init', [__CLASS__, 'maybe_backfill_group_names'], 20);
    }

    /** Назва групи змінилась → оновити її в leadrouter_groups */
    public static function sync_group_name_on_rename($post_id, $post_after, $post_before): void
    {
        if (!$post_after || $post_after->post_type !== 'leadrouter_group') {
            return;
        }
        if ($post_before && $post_after->post_title === $post_before->post_title) {
            return; // назва не змінилась
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'leadrouter_groups',
            ['name' => $post_after->post_title],
            ['post_id' => (int) $post_id],
            ['%s'],
            ['%d']
        );
    }

    /** Група в кошик → гасимо рядок, але лишаємо його для історії */
    public static function deactivate_group_row_on_trash(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'leadrouter_group') {
            return;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'leadrouter_groups',
            ['active' => 0],
            ['post_id' => (int) $post_id],
            ['%d'],
            ['%d']
        );
    }

    /** Повернули з кошика → знову активна */
    public static function reactivate_group_row_on_untrash(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'leadrouter_group') {
            return;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'leadrouter_groups',
            ['active' => 1, 'name' => $post->post_title],
            ['post_id' => (int) $post_id],
            ['%d', '%s'],
            ['%d']
        );
    }

    /**
     * Разово вирівняти name у leadrouter_groups за назвами постів і погасити
     * рядки груп, що лежать у кошику. Обидва наслідки старої логіки:
     * назва писалась лише при INSERT, а рядок трешнутої групи міг лишитись
     * активним (його перестворював перерахунок ваг).
     */
    public static function maybe_backfill_group_names(): void
    {
        if (get_option('leadrouter_group_names_backfilled') === 'done') {
            return;
        }

        global $wpdb;
        $t = $wpdb->prefix . 'leadrouter_groups';

        // назви — з постів
        $wpdb->query(
            "UPDATE {$t} g
               JOIN {$wpdb->posts} p ON p.ID = g.post_id AND p.post_type = 'leadrouter_group'
                SET g.name = p.post_title
              WHERE g.name <> p.post_title"
        );

        // групи в кошику не мають лишатись активними
        $wpdb->query(
            "UPDATE {$t} g
               JOIN {$wpdb->posts} p ON p.ID = g.post_id AND p.post_type = 'leadrouter_group'
                SET g.active = 0
              WHERE p.post_status = 'trash' AND g.active = 1"
        );

        update_option('leadrouter_group_names_backfilled', 'done');
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
         * Знімаємо стару групу партнера ДО оновлення мета — щоб потім
         * перерахувати ваги і тієї групи, з якої він пішов.
         */
        public
        static function capture_old_group($check, $object_id, $meta_key, $meta_value)
        {
            if ($meta_key === self::$group_meta_key && get_post_type($object_id) === 'leadrouter_partner') {
                self::$old_group[(int)$object_id] = (int)get_post_meta((int)$object_id, self::$group_meta_key, true);
            }

            return $check; // нічого не перехоплюємо
        }

        /**
         * Перерахунок ваг обох груп після зміни групи партнера.
         */
        public
        static function maybe_recalc_on_group_change($meta_id, $post_id, $meta_key, $meta_value): void
        {
            if ($meta_key !== self::$group_meta_key) {
                return;
            }
            if (get_post_type($post_id) !== 'leadrouter_partner') {
                return;
            }

            $post_id = (int)$post_id;
            $old = self::$old_group[$post_id] ?? 0;
            unset(self::$old_group[$post_id]);

            $new = (int)$meta_value;
            if ($old === $new) {
                return;
            }

            self::with_guard(function () use ($old, $new) {
                foreach (array_unique(array_filter([$old, $new])) as $group_id) {
                    if (get_post_type($group_id) === 'leadrouter_group') {
                        leadrouter_recalc_sum_weight($group_id);
                    }
                }
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
