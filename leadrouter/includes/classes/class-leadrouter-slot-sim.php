<?php
/**
 * LeadRouter_Slot_Sim — погодинна симуляція продажу лідів у shared-групі.
 *
 * Це ДРУГИЙ шар пісочниці: LeadRouter_Slot_Planner відповідає на питання
 * «чи сходяться ліміти з N × L» (статичне пакування), а цей клас — на питання
 * «чи справді кожен лід продасться N разів протягом доби», бо в реальності
 * заважають години роботи партнерів і те, що двом компаніям одного власника
 * той самий лід не віддають (див. LeadRouter_Flow::filter_one_partner_per_owner
 * і ::select_partners_shared).
 *
 * Клас навмисно ЧИСТИЙ: жодного виклику WP API, БД, часу чи випадковості —
 * масив на вхід, масив на вихід. Так його можна ганяти окремо від WordPress,
 * а сама пісочниця фізично не може нічого записати.
 *
 * Модель (крок — одна година, 0→23):
 *   1) надходження лідів цієї години: кожному — до N партнерів, які відкриті
 *      зараз, мають залишок ліміту і чий власник ще не має копії цього ліда;
 *      порядок вибору — апроксимація дефіцитного WRR: більший залишок ліміту
 *      першим (tie-break — менший індекс у списку);
 *   2) добори (модель LeadRouter_Flow::shared_group_for_topup): черга лідів,
 *      яким не вистачило копій, найстаріші перші, за тим самим правилом.
 *
 * Кінець доби: усе, що лишилось у черзі, — згорілі копії; залишки лімітів
 * партнерів — недовибрані ліміти.
 */

defined('ABSPATH') || exit;

class LeadRouter_Slot_Sim
{
    /** Стеля на кількість модельованих лідів (щоб важкий сценарій не з'їв PHP) */
    const MAX_LEADS = 3000;

    /**
     * @param array $partners [['id','label','limit','owner','start_h','end_h'], ...]
     * @param int   $n        копій на лід
     * @param int   $l        денний обсяг групи
     * @param array $flow     ['mode'=>'uniform'|'manual','window'=>[8,22],
     *                         'volume_pct'=>100,'per_hour'=>[24 числа]]
     *
     * @return array{leads:array,copies:array,partners:array,hours:array,findings:array,flow:array}
     */
    public static function run(array $partners, int $n, int $l, array $flow): array
    {
        $n = max(1, $n);
        $l = max(0, $l);

        $pool     = self::normalize_partners($partners);
        $per_hour = self::hours_plan($flow, $l);

        $total_leads = array_sum($per_hour);
        $capped      = false;
        if ($total_leads > self::MAX_LEADS) {
            $per_hour    = self::scale_down($per_hour, self::MAX_LEADS);
            $total_leads = array_sum($per_hour);
            $capped      = true;
        }

        /** @var array<int, array{hour:int,owners:array,copies:int,direct:int,topup:int}> */
        $leads = [];
        $queue = [];   // індекси лідів, яким бракує копій (FIFO — найстаріші перші)

        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[$h] = [
                'leads'         => 0,
                'direct'        => 0,
                'topup'         => 0,
                'short'         => 0,
                'owners_open'   => self::owners_open_at($pool, $h),
                'partners_open' => self::partners_open_at($pool, $h),
            ];
        }

        for ($h = 0; $h < 24; $h++) {
            // ── 1. Надходження ──
            $arrivals = (int)$per_hour[$h];
            $hours[$h]['leads'] = $arrivals;

            for ($k = 0; $k < $arrivals; $k++) {
                $lead = ['hour' => $h, 'owners' => [], 'copies' => 0, 'direct' => 0, 'topup' => 0];

                $picked = self::pick($pool, $lead['owners'], $n, $h);
                self::consume($pool, $picked, $lead, $h, 'direct');

                $hours[$h]['direct'] += count($picked);

                $idx = count($leads);
                $leads[$idx] = $lead;

                if ($lead['copies'] < $n) {
                    $hours[$h]['short'] += $n - $lead['copies'];
                    $queue[] = $idx;
                }
            }

            // ── 2. Добори (та сама година, найстаріші ліди перші) ──
            if (empty($queue) || !self::has_free_capacity($pool, $h)) {
                continue;
            }

            $still = [];
            foreach ($queue as $idx) {
                $need = $n - $leads[$idx]['copies'];
                if ($need <= 0) {
                    continue;
                }

                $picked = self::pick($pool, $leads[$idx]['owners'], $need, $h);
                if (!empty($picked)) {
                    self::consume($pool, $picked, $leads[$idx], $h, 'topup');
                    $hours[$h]['topup'] += count($picked);
                }

                if ($leads[$idx]['copies'] < $n) {
                    $still[] = $idx;
                }
            }
            $queue = $still;
        }

        $stats = self::collect($pool, $leads, $hours, $queue, $n, $total_leads);

        $stats['flow'] = [
            'total_leads' => $total_leads,
            'per_hour'    => $per_hour,
            'capped'      => $capped,
        ];

        $stats['findings'] = self::findings(
            $pool,
            $hours,
            $stats,
            $n,
            $per_hour,
            $capped
        );

        return $stats;
    }

    /**
     * Автопідказки до звірки — конкретні числа, а не просто «не сходиться».
     * Рахуються статично (без симуляції), тож підходять і для порожньої форми.
     *
     * @return array<int, array{level:string,code:string,text:string}>
     */
    public static function hints(array $partners, int $n, int $l): array
    {
        $n = max(1, $n);
        $l = max(0, $l);

        $pool = self::normalize_partners($partners);

        $sum = 0;
        $owners = [];
        foreach ($pool as $p) {
            if ($p['limit'] <= 0) {
                continue;
            }
            $sum += $p['limit'];
            $owners[$p['owner']] = true;
        }

        $hints    = [];
        $capacity = $n * $l;
        $even_l   = (int)floor($sum / $n);

        if ($sum > 0 && $sum < $capacity) {
            $hints[] = [
                'level' => 'warning',
                'code'  => 'hint_lack',
                'text'  => sprintf(
                    'Σ лімітів %d, N × L = %d. Бракує %d — додай партнера на %d або постав L = %d.',
                    $sum,
                    $capacity,
                    $capacity - $sum,
                    $capacity - $sum,
                    $even_l
                ),
            ];
        } elseif ($sum > $capacity) {
            $hints[] = [
                'level' => 'warning',
                'code'  => 'hint_excess',
                'text'  => sprintf(
                    'Σ лімітів %d, N × L = %d. Надлишок %d — стільки ліміту нікуди покласти; постав L = %d або зменш ліміти.',
                    $sum,
                    $capacity,
                    $sum - $capacity,
                    $even_l
                ),
            ];
        }

        if ($sum > 0) {
            $hints[] = [
                'level' => 'info',
                'code'  => 'hint_even_l',
                'text'  => sprintf('При цих лімітах рівне L = floor(Σ / N) = floor(%d / %d) = %d.', $sum, $n, $even_l),
            ];
        }

        $owners_cnt = count($owners);
        if ($owners_cnt > 0 && $owners_cnt < $n) {
            $hints[] = [
                'level' => 'error',
                'code'  => 'hint_owners_lt_n',
                'text'  => sprintf(
                    'Різних власників %d при N = %d → жоден лід ніколи не продасться %d разів. Бракує ще %d тегів власника — додай партнерів з новими тегами.',
                    $owners_cnt,
                    $n,
                    $n,
                    $n - $owners_cnt
                ),
            ];
        }

        return $hints;
    }

    /* ===================== ВХІД ===================== */

    /**
     * Нормалізація фейкових партнерів. Порожній власник → сам собі власник
     * ('p{id}') — так само, як у LeadRouter_Slot_Planner::build_blocks().
     */
    private static function normalize_partners(array $partners): array
    {
        $pool = [];

        foreach (array_values($partners) as $i => $p) {
            $id      = (int)($p['id'] ?? ($i + 1));
            $owner   = trim((string)($p['owner'] ?? ''));
            $start_h = isset($p['start_h']) ? (int)$p['start_h'] : 0;
            $end_h   = isset($p['end_h']) ? (int)$p['end_h'] : 24;

            $start_h = max(0, min(24, $start_h));
            $end_h   = max(0, min(24, $end_h));

            $pool[] = [
                'id'        => $id,
                'label'     => (string)($p['label'] ?? ('Партнер #' . $id)),
                'limit'     => max(0, (int)($p['limit'] ?? 0)),
                'owner'     => $owner !== '' ? $owner : ('p' . $id),
                'owner_raw' => $owner,
                'start_h'   => $start_h,
                'end_h'     => $end_h,
                // нічних вікон (кінець ≤ початок) симулятор не моделює —
                // такий партнер вважається закритим на добу
                'valid'     => $end_h > $start_h,
                'left'      => max(0, (int)($p['limit'] ?? 0)),
                'used'      => 0,
                'exhaust_h' => null,
            ];
        }

        return $pool;
    }

    /**
     * Крива потоку → скільки лідів приходить кожної години.
     * uniform: рівномірно по вікну, залишок — у ранні години.
     */
    private static function hours_plan(array $flow, int $l): array
    {
        $per_hour = array_fill(0, 24, 0);
        $mode     = ($flow['mode'] ?? 'uniform') === 'manual' ? 'manual' : 'uniform';

        if ($mode === 'manual') {
            $raw = is_array($flow['per_hour'] ?? null) ? $flow['per_hour'] : [];
            for ($h = 0; $h < 24; $h++) {
                $per_hour[$h] = max(0, (int)($raw[$h] ?? 0));
            }

            return $per_hour;
        }

        $pct    = max(0, (int)($flow['volume_pct'] ?? 100));
        $total  = (int)round($l * $pct / 100);
        if ($total <= 0) {
            return $per_hour;
        }

        $win = is_array($flow['window'] ?? null) ? $flow['window'] : [8, 22];
        $ws  = max(0, min(24, (int)($win[0] ?? 8)));
        $we  = max(0, min(24, (int)($win[1] ?? 22)));
        if ($we <= $ws) {
            // некоректне вікно — вважаємо, що ліди йдуть цілу добу
            $ws = 0;
            $we = 24;
        }

        $count = $we - $ws;
        $base  = intdiv($total, $count);
        $rest  = $total % $count;

        for ($i = 0; $i < $count; $i++) {
            $per_hour[$ws + $i] = $base + ($i < $rest ? 1 : 0);
        }

        return $per_hour;
    }

    /** Пропорційно стиснути потік до стелі MAX_LEADS */
    private static function scale_down(array $per_hour, int $max): array
    {
        $total = array_sum($per_hour);
        if ($total <= $max || $total <= 0) {
            return $per_hour;
        }

        $k = $max / $total;
        foreach ($per_hour as $h => $v) {
            $per_hour[$h] = (int)floor($v * $k);
        }

        return $per_hour;
    }

    /* ===================== КРОК СИМУЛЯЦІЇ ===================== */

    /**
     * До $need партнерів для одного ліда о годині $h.
     * Обмеження: відкритий зараз, є залишок ліміту, власник ще не має копії
     * цього ліда (це і є shared-правило «один партнер на власника»).
     *
     * @return int[] індекси в $pool
     */
    private static function pick(array $pool, array $lead_owners, int $need, int $h): array
    {
        if ($need <= 0) {
            return [];
        }

        $cands = [];
        foreach ($pool as $i => $p) {
            if (!$p['valid'] || $p['left'] <= 0) {
                continue;
            }
            if ($h < $p['start_h'] || $h >= $p['end_h']) {
                continue;
            }
            if (isset($lead_owners[$p['owner']])) {
                continue;
            }
            $cands[] = $i;
        }

        if (empty($cands)) {
            return [];
        }

        // Апроксимація дефіцитного WRR: першим той, хто найбільше відстає від
        // своєї денної норми, тобто має найбільший залишок ліміту.
        usort($cands, static function ($a, $b) use ($pool) {
            if ($pool[$a]['left'] !== $pool[$b]['left']) {
                return $pool[$b]['left'] <=> $pool[$a]['left'];
            }
            return $a <=> $b;
        });

        $picked = [];
        $taken  = $lead_owners;

        foreach ($cands as $i) {
            if (count($picked) >= $need) {
                break;
            }
            $owner = $pool[$i]['owner'];
            if (isset($taken[$owner])) {
                continue; // на один лід — максимум один партнер власника
            }
            $taken[$owner] = true;
            $picked[]      = $i;
        }

        return $picked;
    }

    /** Списати ліміти обраних партнерів і записати копії лідові */
    private static function consume(array &$pool, array $picked, array &$lead, int $h, string $kind): void
    {
        foreach ($picked as $i) {
            $pool[$i]['left']--;
            $pool[$i]['used']++;
            if ($pool[$i]['left'] === 0 && $pool[$i]['exhaust_h'] === null) {
                $pool[$i]['exhaust_h'] = $h;
            }

            $lead['owners'][$pool[$i]['owner']] = true;
            $lead['copies']++;
            $lead[$kind]++;
        }
    }

    /** Чи є о цій годині хоч один відкритий партнер із залишком ліміту */
    private static function has_free_capacity(array $pool, int $h): bool
    {
        foreach ($pool as $p) {
            if ($p['valid'] && $p['left'] > 0 && $h >= $p['start_h'] && $h < $p['end_h']) {
                return true;
            }
        }

        return false;
    }

    /** Скільки РІЗНИХ власників відкрито о цій годині (ліміт 0 не рахуємо) */
    private static function owners_open_at(array $pool, int $h): int
    {
        $owners = [];
        foreach ($pool as $p) {
            if ($p['valid'] && $p['limit'] > 0 && $h >= $p['start_h'] && $h < $p['end_h']) {
                $owners[$p['owner']] = true;
            }
        }

        return count($owners);
    }

    /** Скільки партнерів відкрито о цій годині */
    private static function partners_open_at(array $pool, int $h): int
    {
        $cnt = 0;
        foreach ($pool as $p) {
            if ($p['valid'] && $p['limit'] > 0 && $h >= $p['start_h'] && $h < $p['end_h']) {
                $cnt++;
            }
        }

        return $cnt;
    }

    /* ===================== ВИХІД ===================== */

    /** Підсумки: ліди, копії, партнери, години */
    private static function collect(array $pool, array $leads, array $hours, array $queue, int $n, int $total_leads): array
    {
        $full = 0;
        $zero = 0;
        $dist = [];
        $direct = 0;
        $topup  = 0;

        foreach ($leads as $lead) {
            $direct += $lead['direct'];
            $topup  += $lead['topup'];

            if ($lead['copies'] >= $n) {
                $full++;
            } elseif ($lead['copies'] === 0) {
                $zero++;
            }

            if ($lead['copies'] < $n) {
                $key = $lead['copies'];
                $dist[$key] = ($dist[$key] ?? 0) + 1;
            }
        }
        ksort($dist);

        $burnt = 0;
        foreach ($queue as $idx) {
            $burnt += $n - $leads[$idx]['copies'];
        }

        // скільки копій узагалі потрапило в чергу доборів (дібрані + згорілі)
        $queued = 0;
        foreach ($hours as $row) {
            $queued += $row['short'];
        }

        $plan = $n * $total_leads;
        $sold = $direct + $topup;

        $partners = [];
        foreach ($pool as $p) {
            $partners[] = [
                'id'        => $p['id'],
                'label'     => $p['label'],
                'owner'     => $p['owner'],
                'owner_raw' => $p['owner_raw'],
                'limit'     => $p['limit'],
                'used'      => $p['used'],
                'left'      => $p['left'],
                'pct'       => $p['limit'] > 0 ? round($p['used'] * 100 / $p['limit'], 1) : 0.0,
                'exhaust_h' => $p['exhaust_h'],
                'start_h'   => $p['start_h'],
                'end_h'     => $p['end_h'],
                'valid'     => $p['valid'],
            ];
        }

        return [
            'leads' => [
                'total'   => $total_leads,
                'full'    => $full,
                'partial' => $total_leads - $full - $zero,
                'zero'    => $zero,
                'dist'    => $dist,
            ],
            'copies' => [
                'plan'   => $plan,
                'direct' => $direct,
                'topup'  => $topup,
                'queued' => $queued,
                'sold'   => $sold,
                'burnt'  => $burnt,
                'pct'    => $plan > 0 ? round($sold * 100 / $plan, 1) : 0.0,
            ],
            'partners' => $partners,
            'hours'    => $hours,
            'findings' => [],
        ];
    }

    /**
     * Людські формулювання вузьких місць — найцінніша частина звіту.
     *
     * @return array<int, array{level:string,code:string,text:string}>
     */
    private static function findings(array $pool, array $hours, array $stats, int $n, array $per_hour, bool $capped): array
    {
        $out = [];

        if ($capped) {
            $out[] = [
                'level' => 'warning',
                'code'  => 'leads_capped',
                'text'  => sprintf('Потік обрізано до %d лідів — це стеля симулятора, щоб сторінка лишалась швидкою.', self::MAX_LEADS),
            ];
        }

        // ── нічні вікна ──
        foreach ($pool as $p) {
            if (!$p['valid']) {
                $out[] = [
                    'level' => 'error',
                    'code'  => 'bad_window',
                    'text'  => sprintf(
                        'Партнер «%s»: кінець вікна (%02d:00) не пізніше початку (%02d:00) — вважаємо закритим на добу. Нічних вікон (через опівніч) симулятор не моделює.',
                        $p['label'],
                        $p['end_h'],
                        $p['start_h']
                    ),
                ];
            }
        }

        // ── різних власників менше за N ──
        $owners = [];
        foreach ($pool as $p) {
            if ($p['limit'] > 0 && $p['valid']) {
                $owners[$p['owner']] = true;
            }
        }
        $owners_cnt = count($owners);
        if ($owners_cnt < $n) {
            $out[] = [
                'level' => 'error',
                'code'  => 'owners_lt_n',
                'text'  => sprintf(
                    'Різних власників %d при N = %d → жоден лід ніколи не продасться %d разів: Flow не віддає дві копії одного ліда одному власнику.',
                    $owners_cnt,
                    $n,
                    $n
                ),
            ];
        }

        // ── години з дефіцитом власників (сусідні однакові — одним рядком) ──
        foreach (self::owner_gaps($hours, $n) as $gap) {
            $out[] = [
                'level' => 'warning',
                'code'  => 'hour_owners_lt_n',
                'text'  => sprintf(
                    '%s відкрито різних власників: %d (при N = %d) → лідів цих годин: %d, кожен отримає максимум %d копій з %d.',
                    $gap['from'] === $gap['to']
                        ? sprintf('О %02d:00', $gap['from'])
                        : sprintf('О %02d:00–%02d:00', $gap['from'], $gap['to']),
                    $gap['owners'],
                    $n,
                    $gap['leads'],
                    $gap['owners'],
                    $n
                ),
            ];
        }

        // ── останні години з лідами: щоб не сварити партнера за «простій» уночі ──
        $last_lead_h = -1;
        foreach ($per_hour as $h => $cnt) {
            if ($cnt > 0) {
                $last_lead_h = $h;
            }
        }

        // скільки компаній у кожного власника — потрібно для пояснення недобору
        $owner_size = [];
        $sum_limits = 0;
        foreach ($stats['partners'] as $p) {
            if ($p['limit'] <= 0 || !$p['valid']) {
                continue;
            }
            $owner_size[$p['owner']] = ($owner_size[$p['owner']] ?? 0) + 1;
            $sum_limits += $p['limit'];
        }

        // ── вичерпали ліміт і простоюють: групуємо за годиною, щоб не було
        //    десяти однакових рядків ──
        $exhausted = [];
        $under     = ['window' => [], 'cluster' => [], 'rest' => []];

        foreach ($stats['partners'] as $p) {
            if ($p['limit'] <= 0 || !$p['valid']) {
                continue;
            }

            if ($p['exhaust_h'] !== null && $p['exhaust_h'] < min($p['end_h'] - 1, $last_lead_h)) {
                $exhausted[$p['exhaust_h']][] = $p;
            }

            if ($p['left'] <= 0) {
                continue;
            }

            if ($last_lead_h >= 0 && $p['end_h'] <= $last_lead_h) {
                $under['window'][] = $p;
            } elseif (($owner_size[$p['owner']] ?? 1) > 1) {
                $under['cluster'][] = $p;
            } else {
                $under['rest'][] = $p;
            }
        }

        ksort($exhausted);
        foreach ($exhausted as $h => $list) {
            $out[] = [
                'level' => 'warning',
                'code'  => 'partner_exhausted_early',
                'text'  => count($list) === 1
                    ? sprintf(
                        'Партнер «%s» вичерпує ліміт %d о %02d:00 і решту дня простоює (його вікно — до %02d:00).',
                        $list[0]['label'],
                        $list[0]['limit'],
                        $h,
                        $list[0]['end_h']
                    )
                    : sprintf(
                        '%d партнерів (%s) вичерпують ліміт о %02d:00 і решту дня простоюють.',
                        count($list),
                        self::labels($list),
                        $h
                    ),
            ];
        }

        // ── недовибрані ліміти: вікно / кластер власника / решта ──
        foreach ($under['window'] as $p) {
            $out[] = [
                'level' => 'warning',
                'code'  => 'partner_underused',
                'text'  => sprintf(
                    'Партнер «%s» недовибирає %d з %d — його вікно %02d–%02d закривається раніше, ніж доходить його черга.',
                    $p['label'],
                    $p['left'],
                    $p['limit'],
                    $p['start_h'],
                    $p['end_h']
                ),
            ];
        }

        foreach (self::group_by_owner($under['cluster']) as $owner => $list) {
            $left = 0;
            foreach ($list as $p) {
                $left += $p['left'];
            }
            $out[] = [
                'level' => 'warning',
                'code'  => 'partner_underused_cluster',
                'text'  => sprintf(
                    'Власник «%s» недовибирає %d (компанії: %s) — у нього %d компаній, а одному власнику дістається максимум одна копія ліда.',
                    $owner,
                    $left,
                    self::labels($list),
                    (int)($owner_size[$owner] ?? count($list))
                ),
            ];
        }

        if (!empty($under['rest'])) {
            $left = 0;
            foreach ($under['rest'] as $p) {
                $left += $p['left'];
            }
            $capacity = $n * ($stats['leads']['total'] ?? 0);

            if ($owners_cnt < $n) {
                // корінь недобору не в чергах, а в тому, що копій просто нікому продати
                $text = sprintf(
                    'Недовибрано %d ліміту (%s) — різних власників %d при N = %d, тож більше ніж %d копій одного ліда продати нікому.',
                    $left,
                    self::labels($under['rest']),
                    $owners_cnt,
                    $n,
                    $owners_cnt
                );
            } else {
                $text = $sum_limits > $capacity
                    ? sprintf(
                        'Недовибрано %d ліміту (%s) — лідів на всіх не набирається: Σ лімітів %d при N × лідів = %d.',
                        $left,
                        self::labels($under['rest']),
                        $sum_limits,
                        $capacity
                    )
                    : sprintf(
                        'Недовибрано %d ліміту (%s) — конкуренти встигають раніше, черга до цих партнерів не доходить.',
                        $left,
                        self::labels($under['rest'])
                    );
            }

            $out[] = [
                'level' => 'warning',
                'code'  => 'partner_underused_rest',
                'text'  => $text,
            ];
        }

        // ── підсумок по копіях ──
        $c = $stats['copies'];
        if ($c['plan'] > 0) {
            if ($c['burnt'] > 0) {
                $out[] = [
                    'level' => 'warning',
                    'code'  => 'copies_burnt',
                    'text'  => sprintf(
                        'Згоріло %d копій з %d — фактичний продаж %s%%. У добори пішло %d копій, дібрати вдалось %d.',
                        $c['burnt'],
                        $c['plan'],
                        self::num($c['pct']),
                        $c['queued'],
                        $c['topup']
                    ),
                ];
            } else {
                $out[] = [
                    'level' => 'ok',
                    'code'  => 'copies_all_sold',
                    'text'  => sprintf(
                        'Продано всі %d копій (100%%): одразу %d, доборами %d.',
                        $c['plan'],
                        $c['direct'],
                        $c['topup']
                    ),
                ];
            }
        }

        $lds = $stats['leads'];
        if ($lds['total'] > 0 && $lds['full'] < $lds['total']) {
            $parts = [];
            foreach ($lds['dist'] as $copies => $cnt) {
                $parts[] = sprintf('%d×%d/%d', $cnt, $copies, $n);
            }
            $out[] = [
                'level' => 'warning',
                'code'  => 'leads_partial',
                'text'  => sprintf(
                    'Повністю (%d/%d) продано %d лідів з %d. Решта: %s.',
                    $n,
                    $n,
                    $lds['full'],
                    $lds['total'],
                    implode(', ', $parts)
                ),
            ];
        }

        // помилки вперед, потім попередження, потім зелене
        static $rank = ['error' => 0, 'warning' => 1, 'info' => 2, 'ok' => 3];
        usort($out, static function ($a, $b) use ($rank) {
            return ($rank[$a['level']] ?? 9) <=> ($rank[$b['level']] ?? 9);
        });

        return $out;
    }

    /**
     * Суміжні години з однаковим дефіцитом власників — в один діапазон,
     * щоб звіт не перетворювався на 24 однакові рядки.
     *
     * @return array<int, array{from:int,to:int,owners:int,leads:int}>
     */
    private static function owner_gaps(array $hours, int $n): array
    {
        $gaps = [];
        $cur  = null;

        for ($h = 0; $h < 24; $h++) {
            $row = $hours[$h];
            $bad = $row['leads'] > 0 && $row['owners_open'] < $n;

            if ($bad && $cur !== null && $cur['owners'] === $row['owners_open'] && $cur['to'] === $h - 1) {
                $cur['to']     = $h;
                $cur['leads'] += $row['leads'];
                continue;
            }

            if ($cur !== null) {
                $gaps[] = $cur;
                $cur    = null;
            }

            if ($bad) {
                $cur = ['from' => $h, 'to' => $h, 'owners' => $row['owners_open'], 'leads' => $row['leads']];
            }
        }

        if ($cur !== null) {
            $gaps[] = $cur;
        }

        return $gaps;
    }

    /** Перелік назв партнерів через кому (довгий список — обрізаємо) */
    private static function labels(array $partners, int $max = 6): string
    {
        $names = array_column($partners, 'label');
        if (count($names) <= $max) {
            return implode(', ', $names);
        }

        return implode(', ', array_slice($names, 0, $max)) . sprintf(' і ще %d', count($names) - $max);
    }

    /** Партнери → [власник => [партнери]] */
    private static function group_by_owner(array $partners): array
    {
        $out = [];
        foreach ($partners as $p) {
            $out[$p['owner']][] = $p;
        }

        return $out;
    }

    /** Число без хвостових нулів (90.0 → 90) */
    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }
}
