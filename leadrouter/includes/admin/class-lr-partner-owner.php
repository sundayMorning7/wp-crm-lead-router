<?php
/**
 * LR_Partner_Owner — тег «Власник» на сторінці партнера.
 *
 * Дві речі:
 *   1) після збереження партнера тег приводиться до канонічного вигляду
 *      (LR_Shared_Sync::normalize_owner) — щоб «OwnerX» і «ownerx» не жили
 *      в базі як два різні кластери;
 *   2) у поле підставляється datalist з уже наявними власниками — щоб тег
 *      обирали зі списку, а не набирали щоразу заново.
 *
 * Саме правило «один лід — один власник» від цього не залежить: читання тега
 * нормалізується в LR_Shared_Sync::get_partner_owner() у будь-якому разі.
 */

defined('ABSPATH') || exit;

class LR_Partner_Owner
{
    const META_OWNER = '_lr_partner_owner';

    public static function register(): void
    {
        add_action('carbon_fields_post_meta_container_saved', [__CLASS__, 'canonicalize_on_save'], 20, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /** Після збереження партнера — привести тег до канонічного вигляду */
    public static function canonicalize_on_save($post_id, $container = null): void
    {
        $post_id = (int)$post_id;
        if ($post_id <= 0 || get_post_type($post_id) !== 'leadrouter_partner') {
            return;
        }

        $raw = (string)get_post_meta($post_id, self::META_OWNER, true);
        if ($raw === '') {
            return;
        }

        $normalized = class_exists('LR_Shared_Sync')
            ? LR_Shared_Sync::normalize_owner($raw)
            : strtolower(trim($raw));

        if ($normalized !== $raw) {
            update_post_meta($post_id, self::META_OWNER, $normalized);
        }
    }

    /** Автопідказка існуючих власників на екрані партнера */
    public static function enqueue_assets($hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'leadrouter_partner') {
            return;
        }

        $rel = 'assets/js/partner-owner.js';
        $abs = LEADROUTER_PLUGIN_DIR . $rel;

        wp_enqueue_script(
            'lr-partner-owner',
            LEADROUTER_PLUGIN_URL . $rel,
            [],
            file_exists($abs) ? (string)filemtime($abs) : LEADROUTER_VERSION,
            true
        );

        wp_localize_script('lr-partner-owner', 'LRPartnerOwner', [
            'metaKey' => self::META_OWNER,
            'owners'  => self::known_owners(),
            'i18n'    => [
                'hint' => __('Оберіть існуючого власника зі списку або введіть новий тег. Значення зберігається в нижньому регістрі.', 'leadrouter'),
            ],
        ]);
    }

    /**
     * Усі власники, що вже використовуються (канонічно, без дублів).
     * Партнери в кошику не враховуються.
     */
    public static function known_owners(): array
    {
        global $wpdb;

        $rows = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value
               FROM {$wpdb->postmeta} pm
               INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = '" . esc_sql(self::META_OWNER) . "'
                AND pm.meta_value <> ''
                AND p.post_type = 'leadrouter_partner'
                AND p.post_status <> 'trash'"
        ) ?: [];

        $owners = [];
        foreach ($rows as $raw) {
            $owner = class_exists('LR_Shared_Sync')
                ? LR_Shared_Sync::normalize_owner($raw)
                : strtolower(trim((string)$raw));

            if ($owner !== '') {
                $owners[$owner] = true;
            }
        }

        $owners = array_keys($owners);
        natcasesort($owners);

        return array_values($owners);
    }
}
