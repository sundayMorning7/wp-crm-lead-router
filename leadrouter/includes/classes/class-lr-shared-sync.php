<?php
/**
 * LR_Shared_Sync — синхронізація налаштувань розподілу з post meta групи
 * у робочу таблицю leadrouter_groups + журнал змін коефіцієнтів.
 *
 * Джерело правди — post meta (Carbon Fields). Правила застосування:
 *   - mode / share_n / daily_volume — з НАСТУПНОЇ доби EST (синк робиться там же,
 *     де скидається eff: LeadRouter_Dispatcher_Eff::reset_eff_if_new_day());
 *   - coef (група і партнер) — НЕГАЙНО при збереженні, кожна зміна пишеться
 *     в leadrouter_coef_audit;
 *   - щойно опублікована група синкається одразу, щоб її рядок не чекав доби.
 */

defined('ABSPATH') || exit;

class LR_Shared_Sync
{
    const META_GROUP_MODE         = '_lr_group_mode';
    const META_GROUP_SHARE_N      = '_lr_group_share_n';
    const META_GROUP_VOLUME       = '_lr_group_daily_volume';
    const META_GROUP_COEF         = '_lr_group_coef';
    const META_PARTNER_COEF       = '_lr_partner_coef';
    const META_GROUP_OVERFLOW_ON  = '_lr_group_overflow_on';
    const META_GROUP_OVERFLOW_CAP = '_lr_group_overflow_cap';

    /** Старі значення коефіцієнтів, зняті до оновлення меты: "{post_id}:{meta_key}" => float */
    private static $old_coef = [];

    public static function init(): void
    {
        // Коефіцієнти діють негайно
        add_filter('update_post_metadata', [__CLASS__, 'capture_old_coef'], 10, 4);
        add_action('updated_post_meta', [__CLASS__, 'on_coef_meta_changed'], 10, 4);
        add_action('added_post_meta', [__CLASS__, 'on_coef_meta_changed'], 10, 4);

        // Нова (щойно опублікована) група — синк одразу
        add_action('transition_post_status', [__CLASS__, 'on_group_published'], 20, 3);

        // Overflow-налаштування діють негайно (як coef): після збереження
        // всіх Carbon-полів групи синкаємо їх у робочу таблицю. Чекбокс при
        // знятті видаляє мету, тож ловимо подію контейнера, а не окремих мет.
        add_action('carbon_fields_post_meta_container_saved', [__CLASS__, 'on_group_meta_saved'], 10, 1);
    }

    /* ===================== ПУБЛІЧНИЙ API ===================== */

    /**
     * Синк налаштувань групи meta → таблиця.
     *
     * @param int  $group_post_id post_id групи
     * @param bool $with_coef     чи оновлювати також coef (на межі доби — так, для самолікування)
     */
    public static function sync_group_settings(int $group_post_id, bool $with_coef = true): bool
    {
        global $wpdb;

        if ($group_post_id <= 0 || get_post_type($group_post_id) !== 'leadrouter_group') {
            return false;
        }

        $table = $wpdb->prefix . 'leadrouter_groups';

        $row_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d LIMIT 1",
            $group_post_id
        ));
        if ($row_id <= 0) {
            // рядок групи ще не створений (створює leadrouter_recalc_sum_weight)
            return false;
        }

        $settings = self::read_group_meta($group_post_id);

        $data    = [
            'mode'         => $settings['mode'],
            'share_n'      => $settings['share_n'],
            'daily_volume' => $settings['daily_volume'],
            'overflow_on'  => $settings['overflow_on'],
            'overflow_cap' => $settings['overflow_cap'],
        ];
        $formats = ['%s', '%d', '%d', '%d', '%d'];

        if ($with_coef) {
            $data['coef'] = $settings['coef'];
            $formats[]    = '%f';
        }

        // Для shared-групи вага дня = L (у класичної ваги рахує
        // leadrouter_recalc_sum_weight() з лімітів партнерів)
        if ($settings['mode'] === 'shared') {
            for ($d = 1; $d <= 7; $d++) {
                $data['weight_' . $d] = $settings['daily_volume'];
                $formats[] = '%d';
            }
        }

        return $wpdb->update($table, $data, ['id' => $row_id], $formats, ['%d']) !== false;
    }

    /** Синк усіх активних груп — викликається на межі доби EST */
    public static function sync_all_active_groups(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'leadrouter_groups';

        $post_ids = $wpdb->get_col("SELECT post_id FROM {$table} WHERE active = 1");
        foreach ($post_ids as $post_id) {
            self::sync_group_settings((int)$post_id);
        }
    }

    /**
     * Робочі налаштування групи (уже синхронізовані значення з таблиці).
     * Потрібні диспетчеру у фазі 1; тут — єдина точка читання.
     */
    public static function get_group_settings(int $group_post_id): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'leadrouter_groups';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT mode, share_n, daily_volume, coef, overflow_on, overflow_cap
               FROM {$table} WHERE post_id = %d LIMIT 1",
            $group_post_id
        ), ARRAY_A);

        if (!$row) {
            return [
                'mode' => 'classic', 'share_n' => 1, 'daily_volume' => 0,
                'coef' => 1.0, 'overflow_on' => 0, 'overflow_cap' => 0,
            ];
        }

        return [
            'mode'         => $row['mode'] === 'shared' ? 'shared' : 'classic',
            'share_n'      => max(1, (int)$row['share_n']),
            'daily_volume' => max(0, (int)$row['daily_volume']),
            'coef'         => self::normalize_coef($row['coef']),
            'overflow_on'  => (int)!empty($row['overflow_on']),
            'overflow_cap' => max(0, (int)$row['overflow_cap']),
        ];
    }

    /** Коефіцієнт партнера (1.0 = нейтрально) */
    public static function get_partner_coef(int $partner_post_id): float
    {
        return self::normalize_coef(get_post_meta($partner_post_id, self::META_PARTNER_COEF, true));
    }

    /**
     * Тег власника партнера. Порожній — партнер сам собі кластер ('p{id}').
     */
    public static function get_partner_owner(int $partner_post_id): string
    {
        $owner = self::normalize_owner(get_post_meta($partner_post_id, '_lr_partner_owner', true));

        return $owner !== '' ? $owner : ('p' . $partner_post_id);
    }

    /**
     * Канонічний вигляд тега власника: trim + нижній регістр + один пробіл
     * між словами, не довше 64 символів (розмір колонки assignments.owner_id).
     *
     * Навіщо: тег — вільний текст, а правило «один лід — один власник»
     * порівнює саме рядки. Без нормалізації «OwnerX» і «ownerx» у PHP — два
     * різні кластери, і обидві компанії могли отримати той самий лід (у БД
     * колація case-insensitive, тож другий INSERT просто тихо не проходив).
     * Нормалізуємо і при читанні, і при збереженні — старі значення в БД
     * порівнюються коректно без міграції.
     */
    public static function normalize_owner($raw): string
    {
        $owner = trim((string)$raw);
        if ($owner === '') {
            return '';
        }

        $owner = function_exists('mb_strtolower') ? mb_strtolower($owner, 'UTF-8') : strtolower($owner);
        $owner = preg_replace('/\s+/u', ' ', $owner);

        return function_exists('mb_substr') ? mb_substr($owner, 0, 64, 'UTF-8') : substr($owner, 0, 64);
    }

    /* ===================== ХУКИ ===================== */

    /** Знімаємо старе значення коефіцієнта ДО оновлення меты (для журналу) */
    public static function capture_old_coef($check, $object_id, $meta_key, $meta_value)
    {
        if ($meta_key === self::META_GROUP_COEF || $meta_key === self::META_PARTNER_COEF) {
            self::$old_coef[$object_id . ':' . $meta_key] = self::normalize_coef(
                get_post_meta((int)$object_id, $meta_key, true)
            );
        }

        return $check; // нічого не перехоплюємо
    }

    /** Коефіцієнт змінився → негайно в таблицю (для групи) + рядок у журнал */
    public static function on_coef_meta_changed($meta_id, $object_id, $meta_key, $meta_value): void
    {
        if ($meta_key !== self::META_GROUP_COEF && $meta_key !== self::META_PARTNER_COEF) {
            return;
        }

        $object_id = (int)$object_id;
        $new       = self::normalize_coef($meta_value);

        $key = $object_id . ':' . $meta_key;
        $old = array_key_exists($key, self::$old_coef) ? self::$old_coef[$key] : 1.0;
        unset(self::$old_coef[$key]);

        if ($meta_key === self::META_GROUP_COEF) {
            if (get_post_type($object_id) !== 'leadrouter_group') {
                return;
            }
            self::sync_group_coef($object_id, $new);
            self::log_coef_change('group', $object_id, $old, $new);
            return;
        }

        if (get_post_type($object_id) !== 'leadrouter_partner') {
            return;
        }
        self::log_coef_change('partner', $object_id, $old, $new);
    }

    /** Щойно опублікована група — синкуємо одразу, не чекаючи доби */
    public static function on_group_published(string $new_status, string $old_status, WP_Post $post): void
    {
        if ($post->post_type !== 'leadrouter_group' || $new_status !== 'publish') {
            return;
        }

        self::sync_group_settings((int)$post->ID);
    }

    /**
     * Після збереження Carbon-полів групи негайно синкаємо overflow-налаштування.
     * mode/N/L свідомо НЕ чіпаємо — вони діють з наступної доби (див. шапку).
     */
    public static function on_group_meta_saved($post_id): void
    {
        $post_id = (int)$post_id;
        if ($post_id <= 0 || get_post_type($post_id) !== 'leadrouter_group') {
            return;
        }

        global $wpdb;

        // Carbon-чекбокс: 'yes' коли увімкнено; знятий — мета порожня/відсутня
        $on  = get_post_meta($post_id, self::META_GROUP_OVERFLOW_ON, true);
        $on  = ($on === 'yes' || $on === '1' || $on === 1 || $on === true) ? 1 : 0;
        $cap = max(0, (int)get_post_meta($post_id, self::META_GROUP_OVERFLOW_CAP, true));

        $wpdb->update(
            $wpdb->prefix . 'leadrouter_groups',
            ['overflow_on' => $on, 'overflow_cap' => $cap],
            ['post_id' => $post_id],
            ['%d', '%d'],
            ['%d']
        );
    }

    /* ===================== HELPERS ===================== */

    /** Меты групи з дефолтами; для класичної групи N/L завжди нейтральні */
    private static function read_group_meta(int $group_post_id): array
    {
        $mode = get_post_meta($group_post_id, self::META_GROUP_MODE, true) === 'shared' ? 'shared' : 'classic';

        $share_n = (int)get_post_meta($group_post_id, self::META_GROUP_SHARE_N, true);
        $volume  = (int)get_post_meta($group_post_id, self::META_GROUP_VOLUME, true);

        $overflow_on  = get_post_meta($group_post_id, self::META_GROUP_OVERFLOW_ON, true);
        $overflow_on  = ($overflow_on === 'yes' || $overflow_on === '1' || $overflow_on === 1 || $overflow_on === true) ? 1 : 0;
        $overflow_cap = max(0, (int)get_post_meta($group_post_id, self::META_GROUP_OVERFLOW_CAP, true));

        if ($mode !== 'shared') {
            // класична група продає лід один раз — тримаємо рядок нейтральним
            $share_n      = 1;
            $volume       = 0;
            $overflow_on  = 0;
            $overflow_cap = 0;
        } else {
            $share_n = max(2, $share_n);
            $volume  = max(0, $volume);
        }

        return [
            'mode'         => $mode,
            'share_n'      => $share_n,
            'daily_volume' => $volume,
            'coef'         => self::normalize_coef(get_post_meta($group_post_id, self::META_GROUP_COEF, true)),
            'overflow_on'  => $overflow_on,
            'overflow_cap' => $overflow_cap,
        ];
    }

    /** Оновити лише coef у рядку групи */
    private static function sync_group_coef(int $group_post_id, float $coef): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'leadrouter_groups';

        $wpdb->update(
            $table,
            ['coef' => $coef],
            ['post_id' => $group_post_id],
            ['%f'],
            ['%d']
        );
    }

    /** Рядок у leadrouter_coef_audit (тільки якщо значення реально змінилось) */
    private static function log_coef_change(string $object_type, int $object_id, float $old, float $new): void
    {
        if (abs($old - $new) < 0.001) {
            return;
        }

        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'leadrouter_coef_audit',
            [
                'object_type' => $object_type,
                'object_id'   => $object_id,
                'old_val'     => $old,
                'new_val'     => $new,
                'user_id'     => get_current_user_id(),
                'changed_at'  => (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m-d H:i:s'),
            ],
            ['%s', '%d', '%f', '%f', '%d', '%s']
        );
    }

    /** Порожнє/некоректне значення коефіцієнта = 1.0 (нейтрально) */
    private static function normalize_coef($raw): float
    {
        $val = (float)str_replace(',', '.', (string)$raw);

        return $val > 0 ? round($val, 2) : 1.0;
    }
}
