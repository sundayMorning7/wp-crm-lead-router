<?php
/**
 * LR_SimWeek_Report — аналіз доби/тижня, згенерованих LR_SimWeek.
 *
 * Спершу інваріанти (порушення = баг, без варіантів), потім числа, які
 * порівнюємо з очікуваними. Клас лише читає.
 *
 * ВАЖЛИВО ПРО ЛІМІТИ. Стенд не підміняє час: усі доби фактично проганяються
 * у день реального запуску, тож діють ліміти саме цього дня тижня, а мітки
 * часу вже потім зсуваються на потрібну дату. Тому перевірка «не перевищив
 * денний ліміт» звіряється з лімітом ДНЯ ПРОГОНУ, а не дня-мітки.
 */

defined('ABSPATH') || exit;

class LR_SimWeek_Report
{
    /** Ліміт партнера на день тижня прогону */
    private static function limit_for(int $partner_id, string $day_slug): int
    {
        return max(0, (int)get_post_meta($partner_id, "_leadrouter_partner_{$day_slug}_limit", true));
    }

    private static function day_slug(): string
    {
        static $slugs = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
        $dow = (int)(new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('N');

        return $slugs[$dow];
    }

    /**
     * Звіт по одній добі.
     *
     * @param string $date  дата-мітка (Y-m-d)
     * @param int[]  $ids   ліди, створені цією добою (з маніфесту)
     */
    public static function day(string $date, array $ids): array
    {
        global $wpdb;

        $t_assign = $wpdb->prefix . 'leadrouter_lead_assignments';
        $t_leads  = $wpdb->prefix . 'leadrouter_leads';
        $t_send   = $wpdb->prefix . 'leadrouter_send_log';
        $t_groups = $wpdb->prefix . 'leadrouter_groups';

        $from = $date . ' 00:00:00';
        $to   = $date . ' 23:59:59';

        $out = ['date' => $date, 'leads_created' => count($ids), 'invariants' => [], 'numbers' => []];

        /* ===================== ІНВАРІАНТИ =====================
         * Доставленою копією вважається рядок assignments зі статусом 'sent'.
         * Рядки 'failed' — це спроба, яку партнер відхилив: вона НЕ споживає
         * денний ліміт (Flow рахує used_today по partner_logs sent/queued/
         * accepted) і не є копією. Рахувати їх як копії — помилка звіту.
         */

        // 1) дві копії одного ліда одному партнеру
        $dup_partner = $wpdb->get_results($wpdb->prepare(
            "SELECT lead_id, partner_id, COUNT(*) c FROM {$t_assign}
              WHERE created_at BETWEEN %s AND %s AND status = 'sent'
              GROUP BY lead_id, partner_id HAVING c > 1",
            $from,
            $to
        ), ARRAY_A) ?: [];

        // 2) дві копії одного ліда одному ВЛАСНИКУ (кластер)
        $dup_owner = $wpdb->get_results($wpdb->prepare(
            "SELECT lead_id, owner_id, COUNT(*) c FROM {$t_assign}
              WHERE created_at BETWEEN %s AND %s AND status = 'sent' AND owner_id <> ''
              GROUP BY lead_id, owner_id HAVING c > 1",
            $from,
            $to
        ), ARRAY_A) ?: [];

        // 3) перевищення денного ліміту партнера
        $per_partner = $wpdb->get_results($wpdb->prepare(
            "SELECT partner_id, COUNT(*) c FROM {$t_assign}
              WHERE created_at BETWEEN %s AND %s AND status = 'sent' GROUP BY partner_id",
            $from,
            $to
        ), ARRAY_A) ?: [];

        $slug = self::day_slug();
        $over = [];
        $partner_rows = [];
        foreach ($per_partner as $p) {
            $pid   = (int)$p['partner_id'];
            $limit = self::limit_for($pid, $slug);
            $got   = (int)$p['c'];

            $partner_rows[] = [
                'partner_id' => $pid,
                'label'      => html_entity_decode((string)get_the_title($pid)),
                'owner'      => class_exists('LR_Shared_Sync')
                    ? LR_Shared_Sync::normalize_owner(get_post_meta($pid, '_lr_partner_owner', true))
                    : '',
                'limit'      => $limit,
                'received'   => $got,
                'left'       => max(0, $limit - $got),
                'pct'        => $limit > 0 ? round($got * 100 / $limit, 1) : null,
            ];

            if ($limit > 0 && $got > $limit) {
                $over[] = ['partner_id' => $pid, 'limit' => $limit, 'received' => $got];
            }
        }

        usort($partner_rows, static fn($a, $b) => $b['received'] <=> $a['received']);

        // 4) кожна доставлена копія має успішний запис у send_log
        $assign_total = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t_assign} WHERE created_at BETWEEN %s AND %s AND status = 'sent'",
            $from,
            $to
        ));
        $assign_failed = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t_assign} WHERE created_at BETWEEN %s AND %s AND status <> 'sent'",
            $from,
            $to
        ));
        $send_ok = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t_send} WHERE attempted_at BETWEEN %s AND %s AND status = 'success'",
            $from,
            $to
        ));

        // 5) статус ліда проти кількості копій
        $bad_status = [];
        if (!empty($ids)) {
            $in = implode(',', array_map('intval', $ids));
            $bad_status = $wpdb->get_results(
                "SELECT l.id, l.status, l.copies_target, l.copies_sold,
                        (SELECT COUNT(*) FROM {$t_assign} a WHERE a.lead_id = l.id AND a.status = 'sent') AS copies
                   FROM {$t_leads} l
                  WHERE l.id IN ({$in})
                    AND ( (l.status = 'sent'  AND (SELECT COUNT(*) FROM {$t_assign} a WHERE a.lead_id = l.id AND a.status = 'sent') = 0)
                       OR (l.status = 'await' AND (SELECT COUNT(*) FROM {$t_assign} a WHERE a.lead_id = l.id AND a.status = 'sent') > 0) )",
                ARRAY_A
            ) ?: [];
        }

        // 6) eff груп
        $eff = $wpdb->get_results("SELECT name, eff FROM {$t_groups} WHERE active = 1", ARRAY_A) ?: [];
        $eff_sum = 0;
        foreach ($eff as $e) {
            $eff_sum += (int)$e['eff'];
        }

        $out['invariants'] = [
            'dup_partner_per_lead' => ['ok' => empty($dup_partner), 'rows' => $dup_partner],
            'dup_owner_per_lead'   => ['ok' => empty($dup_owner),   'rows' => $dup_owner],
            'over_daily_limit'     => ['ok' => empty($over),        'rows' => $over],
            'assign_vs_sendlog'    => ['ok' => $assign_total === $send_ok, 'assignments' => $assign_total,
                                       'send_ok' => $send_ok, 'assign_failed' => $assign_failed],
            'status_vs_copies'     => ['ok' => empty($bad_status),  'rows' => $bad_status],
            'eff_sum'              => ['ok' => abs($eff_sum) < 1000, 'value' => $eff_sum, 'by_group' => $eff],
        ];

        /* ===================== ЧИСЛА ===================== */

        // статуси створених цією добою лідів
        $statuses = [];
        if (!empty($ids)) {
            $in = implode(',', array_map('intval', $ids));
            foreach ($wpdb->get_results("SELECT status, COUNT(*) c FROM {$t_leads} WHERE id IN ({$in}) GROUP BY status", ARRAY_A) ?: [] as $r) {
                $statuses[$r['status']] = (int)$r['c'];
            }
            ksort($statuses);
        }

        // розподіл по групах за добу
        $by_group = $wpdb->get_results($wpdb->prepare(
            "SELECT g.name, g.mode, g.share_n, g.weight_1,
                    COUNT(DISTINCT CASE WHEN a.status = 'sent' THEN a.lead_id END) leads,
                    SUM(a.status = 'sent') copies,
                    SUM(a.status <> 'sent') failed
               FROM {$t_assign} a INNER JOIN {$t_groups} g ON g.id = a.group_id
              WHERE a.created_at BETWEEN %s AND %s
              GROUP BY g.id, g.name, g.mode, g.share_n, g.weight_1
              ORDER BY leads DESC",
            $from,
            $to
        ), ARRAY_A) ?: [];

        // скільки копій отримав кожен лід цієї доби
        $dist = [];
        if (!empty($ids)) {
            $in = implode(',', array_map('intval', $ids));
            foreach ($wpdb->get_results(
                "SELECT c.copies, COUNT(*) n FROM (
                     SELECT l.id, (SELECT COUNT(*) FROM {$t_assign} a WHERE a.lead_id = l.id AND a.status = 'sent') copies
                       FROM {$t_leads} l WHERE l.id IN ({$in})
                 ) c GROUP BY c.copies ORDER BY c.copies DESC",
                ARRAY_A
            ) ?: [] as $r) {
                $dist[(int)$r['copies']] = (int)$r['n'];
            }
        }

        // AK/HI
        $excluded = [];
        if (!empty($ids)) {
            $in = implode(',', array_map('intval', $ids));
            $excluded = $wpdb->get_results(
                "SELECT status, COUNT(*) c FROM {$t_leads}
                  WHERE id IN ({$in}) AND (from_state IN ('AK','HI') OR to_state IN ('AK','HI'))
                  GROUP BY status",
                ARRAY_A
            ) ?: [];
        }

        // спроби відправки за добу
        $attempts = $wpdb->get_results($wpdb->prepare(
            "SELECT status, http_code, COUNT(*) c FROM {$t_send}
              WHERE attempted_at BETWEEN %s AND %s GROUP BY status, http_code ORDER BY c DESC",
            $from,
            $to
        ), ARRAY_A) ?: [];

        $out['numbers'] = [
            'lead_statuses'  => $statuses,
            'by_group'       => $by_group,
            'copies_dist'    => $dist,
            'partners'       => $partner_rows,
            'excluded_state' => $excluded,
            'attempts'       => $attempts,
            'assign_total'   => $assign_total,
            'limits_day'     => $slug,
        ];

        return $out;
    }

    /** Текстовий рендер денного звіту */
    public static function render(array $r): string
    {
        $o = sprintf("═══ Доба %s ═══  (ліміти дня прогону: %s)\n", $r['date'], $r['numbers']['limits_day']);
        $o .= sprintf("Створено лідів: %d\n\n", $r['leads_created']);

        $o .= "ІНВАРІАНТИ\n";
        $map = [
            'dup_partner_per_lead' => 'дві копії одного ліда одному партнеру',
            'dup_owner_per_lead'   => 'дві копії одного ліда одному власнику',
            'over_daily_limit'     => 'перевищення денного ліміту',
            'assign_vs_sendlog'    => 'копії = успішні відправки',
            'status_vs_copies'     => 'статус ліда відповідає копіям',
            'eff_sum'              => 'сума eff у межах норми',
        ];
        foreach ($map as $k => $title) {
            $inv = $r['invariants'][$k];
            $o  .= sprintf("  [%s] %s", !empty($inv['ok']) ? ' OK ' : 'ЗБІЙ', $title);
            if ($k === 'assign_vs_sendlog') {
                $o .= sprintf(" (доставлено %d, success у send_log %d, відхилених спроб %d)",
                    $inv['assignments'], $inv['send_ok'], $inv['assign_failed']);
            }
            if ($k === 'eff_sum') {
                $o .= sprintf(" (Σ eff = %d)", $inv['value']);
            }
            if (empty($inv['ok']) && !empty($inv['rows'])) {
                $o .= sprintf(" — порушень: %d", count($inv['rows']));
            }
            $o .= "\n";
        }

        $n = $r['numbers'];

        $o .= "\nСТАТУСИ ЛІДІВ ДОБИ\n";
        foreach ($n['lead_statuses'] as $s => $c) {
            $o .= sprintf("  %-22s %d\n", $s, $c);
        }

        $o .= "\nРОЗПОДІЛ ПО ГРУПАХ\n";
        foreach ($n['by_group'] as $g) {
            $o .= sprintf("  %-22s %-8s N=%-2d вага=%-3d лідів=%-4d копій=%-5d відхилено=%d\n",
                $g['name'], $g['mode'], (int)$g['share_n'], (int)$g['weight_1'],
                (int)$g['leads'], (int)$g['copies'], (int)$g['failed']);
        }

        $o .= "\nКОПІЙ НА ЛІД\n";
        foreach ($n['copies_dist'] as $copies => $cnt) {
            $o .= sprintf("  %d копій — %d лідів\n", $copies, $cnt);
        }

        $o .= "\nПАРТНЕРИ\n";
        foreach ($n['partners'] as $p) {
            $o .= sprintf("  %-36s ліміт=%-4d отримав=%-4d залишок=%-4d %s%s\n",
                mb_substr($p['label'], 0, 36), $p['limit'], $p['received'], $p['left'],
                $p['pct'] !== null ? $p['pct'] . '%' : '',
                $p['owner'] !== '' ? '  власник: ' . $p['owner'] : '');
        }

        $o .= "\nСПРОБИ ВІДПРАВКИ\n";
        foreach ($n['attempts'] as $a) {
            $o .= sprintf("  %-14s http=%-5s %d\n", $a['status'], $a['http_code'] ?? '—', (int)$a['c']);
        }

        if (!empty($n['excluded_state'])) {
            $o .= "\nAK/HI\n";
            foreach ($n['excluded_state'] as $e) {
                $o .= sprintf("  %-24s %d\n", $e['status'], (int)$e['c']);
            }
        }

        return $o;
    }
}
