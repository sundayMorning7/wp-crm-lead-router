<?php

if (!defined('ABSPATH')) exit;

/**
 * Поточний час у таймзоні плагіна (EST) у форматі MySQL DATETIME.
 *
 * Усі денні вікна LeadRouter (квоти груп, ліміти партнерів, скидання eff)
 * рахуються в America/New_York. Раніше частина записів робилась через
 * current_time('mysql'), тобто залежала від налаштування таймзони сайту —
 * достатньо змінити Settings → General, щоб вікна розʼїхались із даними.
 */
function leadrouter_now_mysql_est(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m-d H:i:s');
}

function leadrouter_recalc_sum_weight($post = null)
{


    if (is_null($post)) {
        return false;
    }

    if (is_numeric($post)) {
        $post = get_post((int)$post);
        if (!$post) return false;
    }
    if (!($post instanceof WP_Post)) {
        return false;
    }

    $ptype = get_post_type($post);

    $PT_GROUP   = 'leadrouter_group';
    $PT_PARTNER = 'leadrouter_partner';

    $PARTNER_GROUP_META = '_leadrouter_partner_group';

    if ($ptype === $PT_PARTNER) {
        $group_id = (int) get_post_meta($post->ID, $PARTNER_GROUP_META, true);
    } elseif ($ptype === $PT_GROUP) {
        $group_id = (int) $post->ID;
    } else {
        $group_id = (int) $post->ID;
    }

    if ($group_id <= 0) {
        return false;
    }

    // Група в кошику або видалена: не перераховуємо і, головне, не даємо
    // upsert-у нижче створити для неї НОВИЙ рядок active=1 (перерахунок міг
    // прилетіти від неактивного партнера, що досі вказує на стару групу)
    $group_post = get_post($group_id);
    if (!$group_post || $group_post->post_type !== 'leadrouter_group' || $group_post->post_status === 'trash') {
        return false;
    }





    $partners = get_posts([
        'post_type' => $PT_PARTNER,
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'fields' => 'ids',
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => $PARTNER_GROUP_META,
                'value' => $group_id,
                'type' => 'NUMERIC',
                'compare' => '='],
            [
                'key' => '_leadrouter_partner_active',
                'value' => 1,
                'type' => 'NUMERIC',
                'compare' => '=',
            ]],
    ]);


    $days_weight = array_fill(1, 7, 0);
    $days = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];

    // Shared-група: вага дня = денний обсяг L (скільки лідів вона має отримати),
    // а не MAX ліміту партнерів — ліміти партнерів там дають N × L продажів.
    $group_settings = class_exists('LR_Shared_Sync')
        ? LR_Shared_Sync::get_group_settings($group_id)
        : ['mode' => 'classic'];

    if (($group_settings['mode'] ?? 'classic') === 'shared') {
        $L = (int)($group_settings['daily_volume'] ?? 0);
        $days_weight = array_fill(1, 7, max(0, $L));

        $name = get_the_title($group_id) ?: ('Group #' . $group_id);
        // active = null: перерахунок ваг не чіпає вимикач групи. Літерал 1 тут
        // мовчки вмикав групу, вимкнену тумблером, при будь-якій правці лімітів.
        // Новий рядок (INSERT) все одно створюється активним — DEFAULT 1 у схемі.
        leadrouter_save_group_day_weights_by_post($group_id, $days_weight, $name, null);

        return $days_weight;
    }

    foreach ($partners as $pid) {
        $pid = (int)$pid;
        foreach ($days as $i => $day) {
            // швидше через ядро, кеш мета
            $limit = (int) get_post_meta($pid, "_leadrouter_partner_{$day}_limit", true);
            if ($limit > $days_weight[$i]) {
                $days_weight[$i] = $limit;
            }
        }
    }

    $name = get_the_title($group_id) ?: ('Group #' . $group_id);

    // active = null — див. коментар у shared-гілці вище. ($active з мета
    // _leadrouter_group_active прибрано: ця мета не існує в жодної групи,
    // читалась завжди в 0 і нікуди не передавалась.)
    leadrouter_save_group_day_weights_by_post($group_id, $days_weight, $name, null);

    return $days_weight; // корисно повертати, щоб можна було логувати/тестувати



}

/**
 * Зберегти $days_weight (1..7 => int) у таблицю leadrouter_groups для заданого post_id.
 *
 * @param int $post_id WP Post ID групи.
 * @param array<int> $days_weight Ключі 1..7 => int (Mon..Sun).
 * @param string|null $name Назва групи (якщо INSERT). За замовчуванням — title поста або "Group #ID".
 * @return bool
 */
function leadrouter_save_group_day_weights_by_post(int $post_id, array $days_weight, ?string $name = null, ?int $active = 1): bool
{
    global $wpdb;
    $table = $wpdb->prefix . 'leadrouter_groups';


    for ($i = 1; $i <= 7; $i++) {
        $days_weight[$i] = isset($days_weight[$i]) ? (int)$days_weight[$i] : 0;
    }

    if ($name === null) {
        $name = get_the_title($post_id) ?: ('Group #' . $post_id);
    }

    $data_common = [
        'weight_1' => $days_weight[1],
        'weight_2' => $days_weight[2],
        'weight_3' => $days_weight[3],
        'weight_4' => $days_weight[4],
        'weight_5' => $days_weight[5],
        'weight_6' => $days_weight[6],
        'weight_7' => $days_weight[7],
        // EST: цю колонку читає reset_eff_if_new_day() як дату «останнього дня»
        'updated_at' => leadrouter_now_mysql_est(),
        // Назву оновлюємо і на UPDATE. Раніше вона писалась лише при INSERT, тож
        // після перейменування групи в таблиці назавжди лишалась стара назва —
        // і саме її показували панель балансування, вкладка «Резервні групи»
        // та логи диспетчера (group_name).
        'name' => $name,
    ];

    if ($active !== null) {
        $data_common['active'] = (int)$active;
    }

    // Порядок форматів має збігатися з порядком ключів у $data_common
    $formats_common = ['%d','%d','%d','%d','%d','%d','%d','%s','%s'];
    if ($active !== null) {
        $formats_common[] = '%d';
    }

    $existing_id = (int)$wpdb->get_var(
        $wpdb->prepare("SELECT id FROM {$table} WHERE post_id = %d LIMIT 1", $post_id)
    );

    if ($existing_id > 0) {
        $updated = $wpdb->update(
            $table,
            $data_common,
            ['id' => $existing_id],
            $formats_common,
            ['%d']
        );
        return $updated !== false;
    }


    // 'name' уже є в $data_common — тут лише прив'язка до поста
    $insert_data = array_merge(['post_id' => $post_id], $data_common);

    $insert_formats = array_merge(['%d'], $formats_common);

    $inserted = $wpdb->insert($table, $insert_data, $insert_formats);
    return $inserted !== false;
}



/**
 * Нормалізує номер телефону → 10 цифр без форматування.
 * Приклад: "+1 (346) 350-2904" → "3463502904".
 */
function leadrouter_normalize_phone(?string $raw, array $opts = [])
{
    $strict = (bool)($opts['strict'] ?? false);
    $raw = trim((string)$raw);

    if ($raw === '') {
        return $strict
            ? new WP_Error('invalid_phone', 'Порожній номер телефону')
            : ['original' => $raw, 'warning' => 'phone_unparsed'];
    }

    $digits = preg_replace('/\D+/', '', $raw);

    // Якщо більше 10 цифр — беремо останні 10
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }

    if (strlen($digits) !== 10) {
        return $strict
            ? new WP_Error('invalid_phone', 'Невірна кількість цифр', ['input' => $raw])
            : ['original' => $raw, 'warning' => 'phone_unparsed'];
    }

    return $digits;
}

/**
 * Значення для колонки leads.phone_norm (анти-дубль).
 * Лише цифри, беремо останні 10; коротший номер — як є; порожній → NULL.
 * На відміну від leadrouter_normalize_phone() нічого не валідує і не
 * повертає WP_Error — це просто ключ для пошуку дублів.
 */
function leadrouter_phone_norm_value($raw): ?string
{
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if ($digits === '' || $digits === null) {
        return null;
    }
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }

    return $digits;
}

/**
 * Значення для колонки leads.email_norm (анти-дубль): lower + trim.
 * Порожній → NULL. Обрізаємо до 191 символу — розмір колонки.
 */
function leadrouter_email_norm_value($raw): ?string
{
    $email = strtolower(trim((string)$raw));
    if ($email === '') {
        return null;
    }
    if (strlen($email) > 191) {
        $email = substr($email, 0, 191);
    }

    return $email;
}

/**
 * Пара нормалізованих колонок для INSERT у leads.
 * Використовується в усіх місцях, де створюється лід.
 */
function leadrouter_lead_norm_columns($phone, $email): array
{
    return [
        'phone_norm' => leadrouter_phone_norm_value($phone),
        'email_norm' => leadrouter_email_norm_value($email),
    ];
}

/**
 * Нормалізує дату → формат YYYY-MM-DD для MySQL.
 * Приймає багато форматів, включаючи "08-22-2025", "01 5 2025", "8/22/25" тощо.
 */
function leadrouter_normalize_date(?string $raw, array $opts = [])
{
    $strict = (bool)($opts['strict'] ?? false);
    $raw = trim((string)$raw);

    if ($raw === '') {
        return $strict
            ? new WP_Error('invalid_date', 'Порожня дата')
            : ['original' => $raw, 'warning' => 'date_unparsed'];
    }

    if (!preg_match_all('/\d+/', $raw, $m) || count($m[0]) < 3) {
        $ts = strtotime($raw);
        if ($ts) {
            return date('Y-m-d', $ts);
        }
        return $strict
            ? new WP_Error('invalid_date', 'Не вдалося розпізнати дату', ['input' => $raw])
            : ['original' => $raw, 'warning' => 'date_unparsed'];
    }

    $parts = $m[0];

    // Якщо перша частина має 4 цифри → Y M D
    if (strlen($parts[0]) === 4) {
        $y = (int)$parts[0];
        $mth = (int)$parts[1];
        $d = (int)$parts[2];
    } else {
        $mth = (int)$parts[0];
        $d = (int)$parts[1];
        $y = (int)$parts[2];
        if ($mth > 12 && $d <= 12) {
            [$mth, $d] = [$d, $mth];
        }
    }

    // Двозначний рік → 20xx
    if ($y >= 0 && $y <= 99) {
        $y = 2000 + $y;
    }

    if (!checkdate($mth, $d, $y)) {
        return $strict
            ? new WP_Error('invalid_date', 'Некоректна календарна дата', ['input' => $raw])
            : ['original' => $raw, 'warning' => 'date_unparsed'];
    }

    return sprintf('%04d-%02d-%02d', $y, $mth, $d);
}
