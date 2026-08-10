<?php
/**
 * LeadRouter_Slot_Planner — розкладання денних лімітів партнерів по слотах
 * shared-групи (N колонок × L лідів).
 *
 * Це ВІЗУАЛІЗАЦІЯ ПЛАНУ, а не механізм виконання: реальний розподіл робить
 * дефіцитний WRR у Flow. План лише показує менеджеру, чи сходиться конфігурація.
 *
 * Правила пакування (план §1):
 *   - кластер власника пакується як ОДИН злитий відрізок (усередині — компанії);
 *   - сортування за спаданням, кожен блок цілим у перший слот, де влазить;
 *   - серед решти завжди спершу ті, що ще влазять цілими;
 *   - ріжемо лише тоді, коли цілих варіантів не лишилось;
 *   - частини розрізаного лягають у різні колонки, діапазони не перетинаються.
 */

defined('ABSPATH') || exit;

class LeadRouter_Slot_Planner
{
    /**
     * @param array $partners [['id'=>int,'label'=>string,'limit'=>int,'owner'=>string], ...]
     * @param int   $n        колонок (копій на лід)
     * @param int   $l        місткість колонки (денний обсяг групи)
     *
     * @return array{columns:array,issues:array,totals:array}
     */
    public static function plan(array $partners, int $n, int $l): array
    {
        $n = max(1, $n);
        $l = max(0, $l);

        $blocks = self::build_blocks($partners);
        $issues = self::validate($blocks, $partners, $n, $l);

        $columns = [];
        for ($i = 0; $i < $n; $i++) {
            $columns[$i] = ['used' => 0, 'segments' => []];
        }

        // блоки за спаданням розміру (tie-break — стабільний, за назвою)
        usort($blocks, static function ($a, $b) {
            if ($a['size'] !== $b['size']) {
                return $b['size'] <=> $a['size'];
            }
            return strcmp((string)$a['owner'], (string)$b['owner']);
        });

        $unplaced = [];

        while (!empty($blocks)) {
            // 1) найбільший блок, який ще влазить ЦІЛИМ
            $whole_i = null;
            $whole_col = null;
            foreach ($blocks as $i => $b) {
                foreach ($columns as $ci => $col) {
                    if ($col['used'] + $b['size'] <= $l) {
                        $whole_i   = $i;
                        $whole_col = $ci;
                        break 2;
                    }
                }
            }

            if ($whole_i !== null) {
                $block = $blocks[$whole_i];
                unset($blocks[$whole_i]);
                $blocks = array_values($blocks);
                self::place_part($columns, $whole_col, $block, $block['size'], 1, 1);
                continue;
            }

            // 2) цілим не влазить нічого — ріжемо найбільший
            $block = array_shift($blocks);
            $left  = $block['size'];

            // проміжки за спаданням (tie-break: менший індекс колонки)
            $gaps = [];
            foreach ($columns as $ci => $col) {
                $free = $l - $col['used'];
                if ($free > 0) {
                    $gaps[] = ['col' => $ci, 'free' => $free];
                }
            }
            usort($gaps, static function ($a, $b) {
                if ($a['free'] !== $b['free']) {
                    return $b['free'] <=> $a['free'];
                }
                return $a['col'] <=> $b['col'];
            });

            if (empty($gaps)) {
                $unplaced[] = $block;
                continue;
            }

            // Ріжемо на якомога МЕНШУ кількість частин: спершу шукаємо комбінацію
            // проміжків, що дає рівно розмір блоку (тоді хвостів не лишається).
            $plan_parts = self::exact_split($gaps, $block['size']);

            if ($plan_parts !== null) {
                $left = 0;
            } else {
                // точної комбінації нема — жадібно від найбільших проміжків
                $plan_parts = [];
                foreach ($gaps as $g) {
                    if ($left <= 0) {
                        break;
                    }
                    $take = min($left, $g['free']);
                    $plan_parts[] = ['col' => $g['col'], 'size' => $take];
                    $left -= $take;
                }
            }

            // частини — у порядку колонок, щоб схема читалась зліва направо
            usort($plan_parts, static fn($a, $b) => $a['col'] <=> $b['col']);
            $parts_total = count($plan_parts);

            foreach ($plan_parts as $pi => $part) {
                self::place_part($columns, $part['col'], $block, $part['size'], $pi + 1, $parts_total);
            }

            if ($left > 0) {
                $rest = $block;
                $rest['size'] = $left;
                $unplaced[] = $rest;
            }
        }

        $sum_limits = 0;
        foreach ($partners as $p) {
            $sum_limits += max(0, (int)($p['limit'] ?? 0));
        }

        return [
            'columns'  => $columns,
            'issues'   => $issues,
            'unplaced' => $unplaced,
            'totals'   => [
                'sum_limits' => $sum_limits,
                'capacity'   => $n * $l,
                'n'          => $n,
                'l'          => $l,
            ],
        ];
    }

    /* ===================== ВНУТРІШНЄ ===================== */

    /** Партнери → блоки: кластер власника = один злитий блок */
    private static function build_blocks(array $partners): array
    {
        $blocks = [];

        foreach ($partners as $p) {
            $limit = max(0, (int)($p['limit'] ?? 0));
            if ($limit <= 0) {
                continue;
            }

            $owner = (string)($p['owner'] ?? '');
            if ($owner === '') {
                $owner = 'p' . (int)($p['id'] ?? 0);
            }

            if (!isset($blocks[$owner])) {
                $blocks[$owner] = ['owner' => $owner, 'size' => 0, 'members' => [], 'is_cluster' => false];
            }

            $blocks[$owner]['size'] += $limit;
            $blocks[$owner]['members'][] = [
                'id'    => (int)($p['id'] ?? 0),
                'label' => (string)($p['label'] ?? ('#' . (int)($p['id'] ?? 0))),
                'limit' => $limit,
                'left'  => $limit, // скільки ще не розкладено
            ];
        }

        foreach ($blocks as &$b) {
            $b['is_cluster'] = count($b['members']) > 1;
        }
        unset($b);

        return array_values($blocks);
    }

    /**
     * Покласти частину блоку в колонку, роздрібнивши її між компаніями кластера.
     * Блоки пишуться зверху вниз, тож діапазони в колонці не перетинаються.
     */
    private static function place_part(array &$columns, int $col, array &$block, int $size, int $part_no, int $parts_total): void
    {
        $left = $size;

        foreach ($block['members'] as &$m) {
            if ($left <= 0) {
                break;
            }
            if ($m['left'] <= 0) {
                continue;
            }

            $take = min($left, $m['left']);
            $from = $columns[$col]['used'];

            $columns[$col]['segments'][] = [
                'partner_id'  => $m['id'],
                'label'       => $m['label'],
                'owner'       => $block['owner'],
                'is_cluster'  => $block['is_cluster'],
                'size'        => $take,
                'from'        => $from,
                'to'          => $from + $take,
                'is_split'    => $parts_total > 1,
                'part_no'     => $part_no,
                'parts_total' => $parts_total,
            ];

            $columns[$col]['used'] += $take;
            $m['left'] -= $take;
            $left      -= $take;
        }
        unset($m);
    }

    /**
     * Знайти комбінацію проміжків, сума яких дорівнює розміру блоку рівно.
     * Перебираємо за зростанням кількості частин — щоб різати якомога менше;
     * усередині однакової кількості віддаємо перевагу більшим проміжкам
     * (вони йдуть першими у вже відсортованому $gaps).
     *
     * @return array<int, array{col:int,size:int}>|null
     */
    private static function exact_split(array $gaps, int $size): ?array
    {
        $count = count($gaps);
        if ($count === 0 || $size <= 0 || $count > 16) {
            return null; // на великих N не перебираємо — жадібний варіант
        }

        for ($k = 1; $k <= $count; $k++) {
            $combo = self::find_combo($gaps, $size, $k, 0);
            if ($combo !== null) {
                return $combo;
            }
        }

        return null;
    }

    /** Рекурсивний перебір комбінацій рівно з $k проміжків */
    private static function find_combo(array $gaps, int $target, int $k, int $start): ?array
    {
        if ($k === 0) {
            return $target === 0 ? [] : null;
        }

        for ($i = $start; $i <= count($gaps) - $k; $i++) {
            $free = (int)$gaps[$i]['free'];
            if ($free > $target) {
                continue;
            }

            $rest = self::find_combo($gaps, $target - $free, $k - 1, $i + 1);
            if ($rest !== null) {
                array_unshift($rest, ['col' => (int)$gaps[$i]['col'], 'size' => $free]);
                return $rest;
            }
        }

        return null;
    }

    /** Умови здійсненності (план §1) */
    private static function validate(array $blocks, array $partners, int $n, int $l): array
    {
        $issues = [];

        // Різних власників має бути щонайменше N: Flow не віддає дві копії
        // одного ліда одному власнику (filter_one_partner_per_owner), тож при
        // меншій кількості кластерів лід фізично не продасться N разів —
        // скільки б не сходились суми лімітів.
        $owners = 0;
        foreach ($blocks as $b) {
            if ((int)$b['size'] > 0) {
                $owners++;
            }
        }

        if ($owners > 0 && $owners < $n) {
            $issues[] = [
                'level' => 'error',
                'code'  => 'owners_lt_n',
                'text'  => sprintf(
                    'Різних власників %d, а копій на лід потрібно N = %d. Дві копії одного ліда одному власнику не віддаються, тож жоден лід не продасться %d разів — бракує ще %d тегів власника.',
                    $owners,
                    $n,
                    $n,
                    $n - $owners
                ),
            ];
        }

        $sum = 0;
        foreach ($partners as $p) {
            $sum += max(0, (int)($p['limit'] ?? 0));
        }

        $capacity = $n * $l;
        if ($sum !== $capacity) {
            $issues[] = [
                'level' => 'warning',
                'code'  => 'sum_mismatch',
                'text'  => sprintf(
                    'Сума денних лімітів партнерів (%d) не дорівнює N × L (%d × %d = %d). Різниця: %+d.',
                    $sum,
                    $n,
                    $l,
                    $capacity,
                    $sum - $capacity
                ),
            ];
        }

        foreach ($partners as $p) {
            $limit = max(0, (int)($p['limit'] ?? 0));
            if ($l > 0 && $limit > $l) {
                $issues[] = [
                    'level' => 'error',
                    'code'  => 'partner_over_l',
                    'text'  => sprintf('Ліміт партнера «%s» (%d) більший за денний обсяг L (%d).', (string)($p['label'] ?? ''), $limit, $l),
                ];
            }
        }

        foreach ($blocks as $b) {
            if ($b['is_cluster'] && $l > 0 && $b['size'] > $l) {
                $names = implode(', ', array_column($b['members'], 'label'));
                $issues[] = [
                    'level' => 'error',
                    'code'  => 'owner_over_l',
                    'text'  => sprintf('Сумарний ліміт кластера власника «%s» (%d) більший за L (%d). Компанії: %s.', $b['owner'], $b['size'], $l, $names),
                ];
            }
        }

        return $issues;
    }

    /* ===================== ДАНІ ГРУПИ ===================== */

    /**
     * Партнери групи для планувальника: активні, опубліковані, з лімітом на
     * заданий день тижня (за замовчуванням — сьогодні в EST).
     */
    public static function partners_for_group(int $group_post_id, ?int $dow = null): array
    {
        if ($dow === null) {
            $dow = (int)(new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('N');
        }

        static $map = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
        $slug = $map[$dow] ?? 'mon';

        $ids = get_posts([
            'post_type'      => 'leadrouter_partner',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_leadrouter_partner_group', 'value' => $group_post_id],
                ['key' => '_leadrouter_partner_active', 'value' => '1', 'compare' => '='],
            ],
        ]);

        $out = [];
        foreach ($ids as $pid) {
            $pid = (int)$pid;
            $out[] = [
                'id'    => $pid,
                'label' => get_the_title($pid) ?: ('Partner #' . $pid),
                'limit' => (int)get_post_meta($pid, "_leadrouter_partner_{$slug}_limit", true),
                'owner' => class_exists('LR_Shared_Sync')
                    ? LR_Shared_Sync::normalize_owner(get_post_meta($pid, '_lr_partner_owner', true))
                    : trim((string)get_post_meta($pid, '_lr_partner_owner', true)),
            ];
        }

        return $out;
    }

    /**
     * План слотів для сторінки групи: звірка умов + схема N колонок × L.
     * Чистий HTML/CSS, без зовнішніх бібліотек.
     */
    public static function render_html(array $plan): string
    {
        $t = $plan['totals'];

        $html = '<div class="lr-slot-plan" style="max-width:1100px">';

        // ── Звірка умов ──
        $html .= '<div style="margin:0 0 12px">';
        if (empty($plan['issues'])) {
            $html .= '<p style="margin:0;padding:8px 12px;border-left:4px solid #00a32a;background:#f0f7ee;">'
                . '<strong>' . esc_html__('Звірка: усі умови виконані.', 'leadrouter') . '</strong> '
                . sprintf(
                    esc_html__('Σ лімітів = %1$d = N × L (%2$d × %3$d).', 'leadrouter'),
                    (int)$t['sum_limits'],
                    (int)$t['n'],
                    (int)$t['l']
                )
                . '</p>';
        } else {
            foreach ($plan['issues'] as $issue) {
                $is_err = ($issue['level'] === 'error');
                $html .= sprintf(
                    '<p style="margin:0 0 6px;padding:8px 12px;border-left:4px solid %s;background:%s;">%s</p>',
                    $is_err ? '#d63638' : '#dba617',
                    $is_err ? '#fcf0f1' : '#fcf9e8',
                    esc_html($issue['text'])
                );
            }
        }
        $html .= '</div>';

        if ((int)$t['l'] <= 0) {
            $html .= '<p><em>' . esc_html__('Задайте денний обсяг L, щоб побачити схему слотів.', 'leadrouter') . '</em></p></div>';
            return $html;
        }

        // ── Схема ──
        $px_per_lead = 9; // висота однієї одиниці ліміту
        $col_height  = max(90, (int)$t['l'] * $px_per_lead);

        $html .= '<div style="display:flex;gap:10px;align-items:flex-start;overflow-x:auto;padding-bottom:8px">';

        foreach ($plan['columns'] as $ci => $col) {
            $html .= '<div style="flex:0 0 130px">';
            $html .= '<div style="font-size:12px;color:#50575e;margin-bottom:4px;">'
                . sprintf(esc_html__('Колонка %1$d — %2$d/%3$d', 'leadrouter'), $ci + 1, (int)$col['used'], (int)$t['l'])
                . '</div>';
            $html .= '<div style="position:relative;height:' . $col_height . 'px;border:1px solid #c3c4c7;background:#f6f7f7;border-radius:3px;overflow:hidden">';

            foreach ($col['segments'] as $s) {
                $top    = (int)round($s['from'] * $px_per_lead);
                $height = max(7, (int)round($s['size'] * $px_per_lead));
                $color  = self::color_for($s['partner_id']);

                $label = $s['label'];
                if ($s['is_split']) {
                    $label .= sprintf(' · %d/%d', $s['part_no'], $s['parts_total']);
                }

                $title = sprintf(
                    '%s — %d (слоти %d–%d)%s%s',
                    $s['label'],
                    $s['size'],
                    $s['from'] + 1,
                    $s['to'],
                    $s['is_cluster'] ? ' · власник: ' . $s['owner'] : '',
                    $s['is_split'] ? ' · розрізано' : ''
                );

                $html .= sprintf(
                    '<div title="%s" style="position:absolute;left:0;right:0;top:%dpx;height:%dpx;background:%s;%s'
                    . 'box-sizing:border-box;color:#fff;font-size:11px;line-height:1.2;padding:2px 4px;overflow:hidden;text-shadow:0 1px 1px rgba(0,0,0,.35)">%s<br><span style="opacity:.85">%d</span></div>',
                    esc_attr($title),
                    $top,
                    $height,
                    esc_attr($color),
                    $s['is_split'] ? 'border:2px dashed rgba(255,255,255,.85);' : 'border:1px solid rgba(0,0,0,.12);',
                    esc_html($label),
                    (int)$s['size']
                );
            }

            $html .= '</div></div>';
        }

        $html .= '</div>';

        // ── Легенда ──
        $legend = [];
        foreach ($plan['columns'] as $col) {
            foreach ($col['segments'] as $s) {
                $pid = (int)$s['partner_id'];
                if (!isset($legend[$pid])) {
                    $legend[$pid] = ['label' => $s['label'], 'owner' => $s['is_cluster'] ? $s['owner'] : '', 'size' => 0, 'parts' => 0];
                }
                $legend[$pid]['size'] += (int)$s['size'];
                $legend[$pid]['parts']++;
            }
        }

        $html .= '<div style="display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:10px;font-size:12px">';
        foreach ($legend as $pid => $info) {
            $html .= sprintf(
                '<span><span style="display:inline-block;width:10px;height:10px;background:%s;border-radius:2px;margin-right:4px"></span>%s — %d%s%s</span>',
                esc_attr(self::color_for($pid)),
                esc_html($info['label']),
                (int)$info['size'],
                $info['parts'] > 1 ? esc_html(sprintf(' (%d частини)', $info['parts'])) : '',
                $info['owner'] !== '' ? esc_html(' · власник: ' . $info['owner']) : ''
            );
        }
        $html .= '</div>';

        if (!empty($plan['unplaced'])) {
            $names = [];
            foreach ($plan['unplaced'] as $b) {
                $names[] = implode(', ', array_column($b['members'], 'label')) . ' — ' . (int)$b['size'];
            }
            $html .= '<p style="margin:10px 0 0;padding:8px 12px;border-left:4px solid #d63638;background:#fcf0f1;">'
                . esc_html__('Не вмістилось у місткість N × L:', 'leadrouter') . ' ' . esc_html(implode('; ', $names))
                . '</p>';
        }

        $html .= '<p style="margin:10px 0 0;color:#646970;font-size:12px">'
            . esc_html__('Схема — це план на добу, а не порядок відправок: реальний розподіл робить дефіцитний WRR. Пунктир — розрізаний ліміт партнера.', 'leadrouter')
            . '</p>';

        $html .= '</div>';

        return $html;
    }

    /** Стабільний колір відрізка за id партнера */
    private static function color_for(int $partner_id): string
    {
        static $palette = [
            '#2271b1', '#00a32a', '#d63638', '#dba617', '#7f54b3',
            '#0f766e', '#b45309', '#be185d', '#3f6212', '#1e40af',
        ];

        return $palette[$partner_id % count($palette)];
    }

    /** Текстовий вигляд плану — для WP-CLI і швидкої перевірки */
    public static function render_text(array $plan): string
    {
        $out = '';
        $t = $plan['totals'];

        $out .= sprintf("N = %d, L = %d, місткість = %d, Σ лімітів = %d\n", $t['n'], $t['l'], $t['capacity'], $t['sum_limits']);

        foreach ($plan['issues'] as $issue) {
            $out .= sprintf("[%s] %s\n", strtoupper($issue['level']), $issue['text']);
        }
        if (empty($plan['issues'])) {
            $out .= "Звірка: усі умови виконані.\n";
        }

        foreach ($plan['columns'] as $ci => $col) {
            $out .= sprintf("\nКолонка %d (зайнято %d/%d):\n", $ci + 1, $col['used'], $t['l']);
            foreach ($col['segments'] as $s) {
                $out .= sprintf(
                    "  %3d–%-3d  %-22s %s%s\n",
                    $s['from'],
                    $s['to'],
                    $s['label'],
                    $s['is_split'] ? sprintf('(частина %d/%d, %d)', $s['part_no'], $s['parts_total'], $s['size']) : sprintf('(%d)', $s['size']),
                    $s['is_cluster'] ? ' [власник: ' . $s['owner'] . ']' : ''
                );
            }
        }

        if (!empty($plan['unplaced'])) {
            $out .= "\nНЕ РОЗКЛАДЕНО (місткості не вистачило):\n";
            foreach ($plan['unplaced'] as $b) {
                $out .= sprintf("  %s — %d\n", implode(', ', array_column($b['members'], 'label')), $b['size']);
            }
        }

        return $out;
    }
}
