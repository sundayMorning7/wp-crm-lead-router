<?php
/**
 * LR_Slot_Simulator — пісочниця «що буде, якщо» для shared-групи
 * (LeadRouter → Симулятор слотів).
 *
 * Дозволяє менеджеру ДО налаштування реальної групи побачити, чи справді
 * кожен лід продасться N разів: партнери вводяться руками, з фейковими
 * лімітами й годинами, і живуть лише у формі відкритої вкладки.
 *
 * АБСОЛЮТНА ВИМОГА — READ-ONLY. Сторінка нічого не пише: ані мети, ані
 * постів, ані таблиць, ані транзієнтів, ані опцій. Перезавантаження сторінки
 * скидає сценарій — це навмисно, а не баг.
 *
 * Два шари рахунку:
 *   A — LeadRouter_Slot_Planner::plan()/render_html(): рівно той самий код, що
 *       малює план на сторінці реальної групи (щоб пісочниця не почала брехати
 *       відносно продакшена);
 *   B — LeadRouter_Slot_Sim::run(): погодинна симуляція з вікнами роботи,
 *       доборами і згорілими копіями.
 *
 * Рахує PHP: JS лише збирає сценарій із форми і підмінює два контейнери.
 */

defined('ABSPATH') || exit;

if (!class_exists('LR_Slot_Simulator')) {

    class LR_Slot_Simulator
    {
        /** Slug сторінки в меню LeadRouter */
        const PAGE_SLUG = 'leadrouter-slot-sim';

        /** Nonce AJAX-перерахунку */
        const NONCE = 'lr_slot_sim';

        /** Стелі на розмір сценарію — щоб вставлений руками JSON не поклав PHP */
        const MAX_PARTNERS = 200;
        const MAX_N        = 50;
        const MAX_L        = 5000;

        /* ============================================================
         * Реєстрація
         * ============================================================ */

        /** Викликається з LeadRouter_Admin::register_menus() (на admin_menu) */
        public static function register_menu(): void
        {
            add_submenu_page(
                'leadrouter',
                __('Симулятор слотів', 'leadrouter'),
                __('Симулятор слотів', 'leadrouter'),
                'manage_options',
                self::PAGE_SLUG,
                [__CLASS__, 'render']
            );
        }

        /** Викликається з LeadRouter_Admin::register_ajax() (на init) */
        public static function register(): void
        {
            add_action('wp_ajax_lr_slot_sim', [__CLASS__, 'ajax_recalc']);
            add_action('wp_ajax_lr_slot_sim_load', [__CLASS__, 'ajax_load_group']);
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        }

        /** Ассети — лише на сторінці симулятора */
        public static function enqueue_assets($hook): void
        {
            if (empty($_GET['page']) || $_GET['page'] !== self::PAGE_SLUG) {
                return;
            }

            $css = 'assets/css/lr-slot-simulator.css';
            $js  = 'assets/js/slot-simulator.js';

            wp_enqueue_style('lr-slot-simulator', LEADROUTER_PLUGIN_URL . $css, [], self::asset_version($css));
            wp_enqueue_script('lr-slot-simulator', LEADROUTER_PLUGIN_URL . $js, [], self::asset_version($js), true);

            wp_localize_script('lr-slot-simulator', 'LRSlotSim', [
                'ajaxUrl'  => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce(self::NONCE),
                'debounce' => 250,
                'limits'   => [
                    'partners' => self::MAX_PARTNERS,
                    'n'        => self::MAX_N,
                    'l'        => self::MAX_L,
                ],
                'i18n'     => [
                    'error'       => __('Помилка перерахунку', 'leadrouter'),
                    'network'     => __('Помилка мережі — перерахунок не виконано', 'leadrouter'),
                    'calc'        => __('Рахую…', 'leadrouter'),
                    'newPartner'  => __('Партнер', 'leadrouter'),
                    'tooMany'     => __('Більше %d партнерів симулятор не рахує.', 'leadrouter'),
                    'bulkPrompt'  => __('Новий ліміт для всіх партнерів:', 'leadrouter'),
                    'pickGroup'   => __('Спершу оберіть групу.', 'leadrouter'),
                    'loading'     => __('Читаю групу…', 'leadrouter'),
                    'loaded'      => __('Підвантажено з групи «%s» (%s). Далі правки живуть лише тут і на групу не впливають.', 'leadrouter'),
                    'noPartners'  => __('У групі немає активних партнерів з лімітом на цей день.', 'leadrouter'),
                ],
            ]);
        }

        /** mtime файла як версія ассета (правка сама скидає кеш браузера) */
        private static function asset_version(string $rel_path): string
        {
            $full = LEADROUTER_PLUGIN_DIR . $rel_path;

            return file_exists($full) ? (string)filemtime($full) : LEADROUTER_VERSION;
        }

        /* ============================================================
         * Сторінка
         * ============================================================ */

        public static function render(): void
        {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('Недостатньо прав для цієї сторінки.', 'leadrouter'), 403);
            }

            $sc = self::default_scenario();

            echo '<div class="wrap lr-sim">';
            echo '<h1>' . esc_html__('Симулятор слотів', 'leadrouter') . '</h1>';

            echo '<p class="lr-sim-note">'
                . esc_html__('Пісочниця: партнери тут фейкові й існують лише у цій вкладці. Нічого не зберігається — перезавантаження сторінки скидає сценарій. Реальні групи, партнери й ліди не змінюються.', 'leadrouter')
                . '</p>';

            echo '<div class="lr-sim-cards">';

            /* ── 1. Параметри групи ── */
            echo '<div class="lr-sim-card lr-sim-card-params">';
            echo self::head_html(
                __('Крок 1', 'leadrouter'),
                __('Параметри групи', 'leadrouter'),
                __('Скільки копій на лід, скільки лідів на добу і як вони розкладені в часі.', 'leadrouter')
            );

            /* Стартова точка з реальної групи: читаємо N, L, склад, ліміти й
               години на обраний день тижня. Тільки читання — далі сценарій
               живе у формі й на групу не впливає. */
            echo '<div class="lr-sim-load">';
            echo '<label>' . esc_html__('Підвантажити з реальної групи', 'leadrouter') . '</label>';
            echo '<div class="lr-sim-load-row">';

            echo '<select id="lr-sim-group"><option value="">' . esc_html__('— оберіть групу —', 'leadrouter') . '</option>';
            foreach (self::groups_for_select() as $gid => $title) {
                printf('<option value="%d">%s</option>', (int)$gid, esc_html($title));
            }
            echo '</select>';

            $today_dow = (int)(new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('N');
            echo '<select id="lr-sim-dow">';
            foreach (self::day_names() as $dow => $name) {
                printf(
                    '<option value="%d"%s>%s</option>',
                    (int)$dow,
                    (int)$dow === $today_dow ? ' selected' : '',
                    esc_html($name . ((int)$dow === $today_dow ? ' ' . __('(сьогодні, EST)', 'leadrouter') : ''))
                );
            }
            echo '</select>';

            echo '<button type="button" class="button" id="lr-sim-load">' . esc_html__('Підвантажити', 'leadrouter') . '</button>';
            echo '</div>';
            echo '<div class="lr-sim-load-notes" id="lr-sim-load-notes"></div>';
            echo '</div>';

            echo '<div class="lr-sim-params">';

            printf(
                '<label>%s<input type="number" id="lr-sim-n" min="1" max="%d" step="1" value="%d"></label>',
                esc_html__('N — копій на лід', 'leadrouter'),
                self::MAX_N,
                (int)$sc['n']
            );
            printf(
                '<label>%s<input type="number" id="lr-sim-l" min="0" max="%d" step="1" value="%d"></label>',
                esc_html__('L — денний обсяг', 'leadrouter'),
                self::MAX_L,
                (int)$sc['l']
            );
            printf(
                '<label>%s<span class="lr-sim-win">'
                . '<input type="number" id="lr-sim-win-start" min="0" max="24" step="1" value="%d">'
                . '<span>–</span>'
                . '<input type="number" id="lr-sim-win-end" min="0" max="24" step="1" value="%d">'
                . '</span></label>',
                esc_html__('Вікно надходження лідів', 'leadrouter'),
                (int)$sc['flow']['window'][0],
                (int)$sc['flow']['window'][1]
            );
            printf(
                '<label>%s<span class="lr-sim-vol">'
                . '<input type="range" id="lr-sim-volume" min="0" max="150" step="5" value="%d">'
                . '<output id="lr-sim-volume-out">%d%%</output>'
                . '</span></label>',
                esc_html__('Фактичний обсяг від L', 'leadrouter'),
                (int)$sc['flow']['volume_pct'],
                (int)$sc['flow']['volume_pct']
            );

            echo '</div>';

            echo '<p class="lr-sim-manual-toggle"><label><input type="checkbox" id="lr-sim-manual"> '
                . esc_html__('ручний розподіл лідів по годинах', 'leadrouter') . '</label></p>';

            echo '<div class="lr-sim-manual" id="lr-sim-manual-box" hidden>';
            for ($h = 0; $h < 24; $h++) {
                printf(
                    '<label class="lr-sim-hour"><span>%02d</span><input type="number" class="lr-f-hour" data-hour="%d" min="0" max="%d" step="1" value="%d"></label>',
                    $h,
                    $h,
                    self::MAX_L,
                    (int)($sc['flow']['per_hour'][$h] ?? 0)
                );
            }
            echo '</div>';
            echo '</div>';

            /* ── 2. Фейкові партнери ── */
            echo '<div class="lr-sim-card lr-sim-card-partners">';
            echo self::head_html(
                __('Крок 2', 'leadrouter'),
                __('Фейкові партнери', 'leadrouter'),
                __('Ліміт, тег власника і вікно роботи. Партнери існують лише в цій вкладці.', 'leadrouter')
            );

            echo '<table class="widefat striped lr-sim-table"><thead><tr>'
                . '<th>' . esc_html__('Назва', 'leadrouter') . '</th>'
                . '<th class="lr-c-num">' . esc_html__('Ліміт', 'leadrouter') . '</th>'
                . '<th>' . esc_html__('Власник', 'leadrouter') . '</th>'
                . '<th class="lr-c-num">' . esc_html__('Початок', 'leadrouter') . '</th>'
                . '<th class="lr-c-num">' . esc_html__('Кінець', 'leadrouter') . '</th>'
                . '<th class="lr-c-act"></th>'
                . '</tr></thead><tbody id="lr-sim-rows">';

            foreach ($sc['partners'] as $p) {
                echo self::row_html($p); // phpcs:ignore — уже екранований усередині
            }

            echo '</tbody></table>';

            echo '<p class="lr-sim-actions">';
            echo '<button type="button" class="button" id="lr-sim-add">' . esc_html__('+ партнер', 'leadrouter') . '</button> ';
            echo '<button type="button" class="button" id="lr-sim-add5">' . esc_html__('+ 5 партнерів по 30', 'leadrouter') . '</button> ';
            echo '<span class="lr-sim-bulk">'
                . '<input type="number" id="lr-sim-bulk-limit" min="0" max="' . self::MAX_L . '" step="1" value="30">'
                . '<button type="button" class="button" id="lr-sim-bulk">' . esc_html__('поставити ліміт усім', 'leadrouter') . '</button>'
                . '</span>';
            echo '</p>';

            echo '<p class="lr-sim-hint">'
                . esc_html__('Порожній власник = партнер сам собі власник. Однаковий тег у кількох партнерів = кластер: на один лід власник отримує максимум одну копію. Нічні вікна (кінець ≤ початок) симулятор не моделює — такий партнер вважається закритим на добу.', 'leadrouter')
                . '</p>';
            echo '</div>';

            echo '</div>'; // .lr-sim-cards

            /* ── 3. Схема слотів: шар A, статичне пакування ── */
            echo '<div class="lr-sim-card lr-sim-card-result">';
            echo self::head_html(
                __('Крок 3 · статичне пакування', 'leadrouter'),
                __('Схема слотів', 'leadrouter'),
                __('Той самий план, що на сторінці реальної групи: N колонок × L. Годин роботи не враховує — лише чи сходяться ліміти.', 'leadrouter'),
                '<span class="lr-sim-busy" id="lr-sim-busy" hidden>' . esc_html__('рахую…', 'leadrouter') . '</span>'
            );
            echo '<div class="lr-sim-body" id="lr-sim-plan">' . self::plan_html($sc) . '</div>';
            echo '</div>';

            /* ── 4. Погодинна симуляція: шар B, саме він відповідає «6 з 6 чи ні» ── */
            echo '<div class="lr-sim-card lr-sim-card-sim">';
            echo self::head_html(
                __('Крок 4 · доба по годинах', 'leadrouter'),
                __('Погодинна симуляція доби', 'leadrouter'),
                __('Вікна роботи, черга доборів і згорілі копії — відповідь на «чи справді кожен лід продасться N разів».', 'leadrouter')
            );
            echo '<div class="lr-sim-body" id="lr-sim-result">' . self::sim_html($sc) . '</div>';
            echo '</div>';

            // шаблон рядка — щоб JS не дублював розмітку
            echo '<template id="lr-sim-row-tpl">' . self::row_html(self::default_partner(0)) . '</template>';

            echo '</div>'; // .wrap
        }

        /**
         * Шапка блоку в стилістиці панелі балансування:
         * overline (дрібний верхній надпис) + великий заголовок + підзаголовок.
         */
        private static function head_html(string $overline, string $title, string $sub, string $aside = ''): string
        {
            return '<div class="lr-sim-head">'
                . '<div class="lr-sim-head-titles">'
                . '<div class="lr-sim-overline">' . esc_html($overline) . '</div>'
                . '<h2 class="lr-sim-title">' . esc_html($title) . '</h2>'
                . '<div class="lr-sim-sub">' . esc_html($sub) . '</div>'
                . '</div>'
                . ($aside !== '' ? '<div class="lr-sim-head-aside">' . $aside . '</div>' : '')
                . '</div>';
        }

        /** Один рядок таблиці партнерів (та сама розмітка для PHP і для JS) */
        private static function row_html(array $p): string
        {
            return '<tr class="lr-sim-row">'
                . '<td><input type="text" class="lr-f-label" maxlength="60" value="' . esc_attr((string)$p['label']) . '"></td>'
                . '<td class="lr-c-num"><input type="number" class="lr-f-limit" min="0" max="' . self::MAX_L . '" step="1" value="' . (int)$p['limit'] . '"></td>'
                . '<td><input type="text" class="lr-f-owner" maxlength="64" placeholder="' . esc_attr__('сам собі власник', 'leadrouter') . '" value="' . esc_attr((string)$p['owner']) . '"></td>'
                . '<td class="lr-c-num"><input type="number" class="lr-f-start" min="0" max="24" step="1" value="' . (int)$p['start_h'] . '"></td>'
                . '<td class="lr-c-num"><input type="number" class="lr-f-end" min="0" max="24" step="1" value="' . (int)$p['end_h'] . '"></td>'
                . '<td class="lr-c-act">'
                . '<button type="button" class="button button-small lr-sim-dup" title="' . esc_attr__('Дублювати (з тим самим власником)', 'leadrouter') . '">⧉</button> '
                . '<button type="button" class="button button-small lr-sim-del" title="' . esc_attr__('Видалити', 'leadrouter') . '">×</button>'
                . '</td>'
                . '</tr>';
        }

        /* ============================================================
         * AJAX-перерахунок
         * ============================================================ */

        public static function ajax_recalc(): void
        {
            check_ajax_referer(self::NONCE, 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Недостатньо прав', 'leadrouter')], 403);
            }

            $raw = json_decode((string)wp_unslash($_POST['scenario'] ?? ''), true);
            if (!is_array($raw)) {
                wp_send_json_error(['message' => __('Некоректний сценарій', 'leadrouter')], 400);
            }

            $sc = self::sanitize_scenario($raw);

            wp_send_json_success([
                'planHtml' => self::plan_html($sc),
                'simHtml'  => self::sim_html($sc),
            ]);
        }

        /* ============================================================
         * Стартова точка з реальної групи (ТІЛЬКИ ЧИТАННЯ)
         * ============================================================ */

        /**
         * Склад реальної групи → сценарій пісочниці. Жодного запису: беремо
         * N/L з мети групи, партнерів — наявним partners_for_group(), години —
         * з мети `_leadrouter_partner_{day}_start/end`.
         */
        public static function ajax_load_group(): void
        {
            check_ajax_referer(self::NONCE, 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Недостатньо прав', 'leadrouter')], 403);
            }

            $gid = (int)($_POST['group'] ?? 0);
            $dow = (int)($_POST['dow'] ?? 0);
            if ($dow < 1 || $dow > 7) {
                $dow = (int)(new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('N');
            }

            if ($gid <= 0 || get_post_type($gid) !== 'leadrouter_group') {
                wp_send_json_error(['message' => __('Групу не знайдено', 'leadrouter')], 400);
            }

            $n = (int)get_post_meta($gid, '_lr_group_share_n', true);
            $l = (int)get_post_meta($gid, '_lr_group_daily_volume', true);

            [$partners, $notes] = self::partners_from_group($gid, $dow);

            $days = self::day_names();

            wp_send_json_success([
                'n'        => $n > 0 ? min(self::MAX_N, $n) : 6,
                'l'        => min(self::MAX_L, max(0, $l)),
                'partners' => $partners,
                'notes'    => $notes,
                'group'    => get_the_title($gid) ?: ('Group #' . $gid),
                'day'      => $days[$dow] ?? '',
            ]);
        }

        /**
         * Партнери групи на день тижня: ліміт і власник — наявним кодом плану,
         * години — з мети партнера.
         *
         * @return array{0: array, 1: array} [партнери, примітки]
         */
        private static function partners_from_group(int $gid, int $dow): array
        {
            $slug = self::day_slug($dow);
            $rows = LeadRouter_Slot_Planner::partners_for_group($gid, $dow);

            $partners = [];
            $notes    = [];
            $rounded  = [];

            foreach (array_slice($rows, 0, self::MAX_PARTNERS) as $i => $r) {
                $pid   = (int)$r['id'];
                $label = (string)$r['label'];

                $raw_from = get_post_meta($pid, "_leadrouter_partner_{$slug}_start", true);
                $raw_to   = get_post_meta($pid, "_leadrouter_partner_{$slug}_end", true);

                $from = self::parse_hour($raw_from);
                $to   = self::parse_hour($raw_to);

                if ($from === null || $to === null) {
                    // так само, як у бою: немає початку або кінця — день закритий
                    $partners[] = [
                        'label'   => $label,
                        'limit'   => (int)$r['limit'],
                        'owner'   => (string)$r['owner'],
                        'start_h' => 0,
                        'end_h'   => 0,
                    ];
                    $notes[] = sprintf(
                        __('«%s»: години на цей день не задані — у бою партнер цього дня закритий, тут теж.', 'leadrouter'),
                        $label
                    );
                    continue;
                }

                // «00:00» як кінець дня читаємо як 24:00, інакше вийшло б порожнє вікно
                if ($to === 0 && $from > 0) {
                    $to = 24;
                }

                if ($to <= $from) {
                    $notes[] = sprintf(
                        __('«%s»: вікно %s–%s переходить через північ. Симулятор нічних вікон не моделює — рахуватиме партнера закритим.', 'leadrouter'),
                        $label,
                        (string)$raw_from,
                        (string)$raw_to
                    );
                }

                if (self::has_minutes($raw_from) || self::has_minutes($raw_to)) {
                    $rounded[] = sprintf('%s (%s–%s → %02d–%02d)', $label, (string)$raw_from, (string)$raw_to, $from, $to);
                }

                $partners[] = [
                    'label'   => $label,
                    'limit'   => (int)$r['limit'],
                    'owner'   => (string)$r['owner'],
                    'start_h' => $from,
                    'end_h'   => $to,
                ];
            }

            if (count($rows) > self::MAX_PARTNERS) {
                $notes[] = sprintf(
                    __('У групі %1$d партнерів — узято перших %2$d (стеля симулятора).', 'leadrouter'),
                    count($rows),
                    self::MAX_PARTNERS
                );
            }

            if (!empty($rounded)) {
                $notes[] = sprintf(
                    __('Симулятор працює цілими годинами, тож хвилини округлено до найближчої: %s.', 'leadrouter'),
                    implode('; ', array_slice($rounded, 0, 6)) . (count($rounded) > 6 ? ' …' : '')
                );
            }

            return [$partners, $notes];
        }

        /** «HH:MM» → ціла година (округлення до найближчої); null для порожніх і битих */
        private static function parse_hour($raw): ?int
        {
            $raw = trim((string)$raw);
            if ($raw === '' || !preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $raw, $m)) {
                return null;
            }

            $h = (int)$m[1];
            $i = (int)$m[2];
            if ($h > 24 || $i > 59) {
                return null;
            }

            return max(0, min(24, $i >= 30 ? $h + 1 : $h));
        }

        /** Чи є у значенні часу ненульові хвилини */
        private static function has_minutes($raw): bool
        {
            return (bool)preg_match('/^\d{1,2}:(?!00)\d{2}/', trim((string)$raw));
        }

        /** Групи для селекта: опубліковані, shared — позначені */
        private static function groups_for_select(): array
        {
            $ids = get_posts([
                'post_type'      => 'leadrouter_group',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]);

            $out = [];
            foreach ($ids as $gid) {
                $gid   = (int)$gid;
                $title = get_the_title($gid) ?: ('Group #' . $gid);
                if (get_post_meta($gid, '_lr_group_mode', true) === 'shared') {
                    $title .= ' · shared';
                }
                $out[$gid] = $title;
            }

            return $out;
        }

        /** Дні тижня 1..7 (як ISO-8601 і як day_slug у LeadRouter_Partners) */
        private static function day_names(): array
        {
            return [
                1 => __('Понеділок', 'leadrouter'),
                2 => __('Вівторок', 'leadrouter'),
                3 => __('Середа', 'leadrouter'),
                4 => __('Четвер', 'leadrouter'),
                5 => __('П’ятниця', 'leadrouter'),
                6 => __('Субота', 'leadrouter'),
                7 => __('Неділя', 'leadrouter'),
            ];
        }

        private static function day_slug(int $dow): string
        {
            static $map = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];

            return $map[$dow] ?? 'mon';
        }

        /**
         * Санітизація сценарію: кожне число зі стелею, кожен рядок — через
         * sanitize_text_field. Стелі потрібні не лише від дурних значень, а й
         * від перебору в LeadRouter_Slot_Planner::exact_split().
         */
        private static function sanitize_scenario(array $raw): array
        {
            $n = min(self::MAX_N, max(1, (int)($raw['n'] ?? 6)));
            $l = min(self::MAX_L, max(0, (int)($raw['l'] ?? 0)));

            $partners = [];
            $list = is_array($raw['partners'] ?? null) ? array_slice($raw['partners'], 0, self::MAX_PARTNERS) : [];

            foreach (array_values($list) as $i => $p) {
                if (!is_array($p)) {
                    continue;
                }

                $label = sanitize_text_field((string)($p['label'] ?? ''));
                $label = function_exists('mb_substr') ? mb_substr($label, 0, 60, 'UTF-8') : substr($label, 0, 60);
                if ($label === '') {
                    $label = sprintf(__('Партнер %d', 'leadrouter'), $i + 1);
                }

                $owner = sanitize_text_field((string)($p['owner'] ?? ''));
                // нормалізуємо так само, як продакшен на збереженні партнера,
                // щоб «Acme» і «acme» були одним кластером
                $owner = class_exists('LR_Shared_Sync')
                    ? LR_Shared_Sync::normalize_owner($owner)
                    : trim($owner);

                $start = min(24, max(0, (int)($p['start_h'] ?? 0)));
                $end   = min(24, max(0, (int)($p['end_h'] ?? 24)));

                $partners[] = [
                    'id'      => $i + 1, // лише для стабільного кольору у схемі
                    'label'   => $label,
                    'limit'   => min(self::MAX_L, max(0, (int)($p['limit'] ?? 0))),
                    'owner'   => $owner,
                    'start_h' => $start,
                    'end_h'   => $end,
                ];
            }

            $raw_flow = is_array($raw['flow'] ?? null) ? $raw['flow'] : [];
            $win      = is_array($raw_flow['window'] ?? null) ? $raw_flow['window'] : [8, 22];

            $per_hour = [];
            $raw_hours = is_array($raw_flow['per_hour'] ?? null) ? $raw_flow['per_hour'] : [];
            for ($h = 0; $h < 24; $h++) {
                $per_hour[$h] = min(self::MAX_L, max(0, (int)($raw_hours[$h] ?? 0)));
            }

            return [
                'n'        => $n,
                'l'        => $l,
                'partners' => $partners,
                'flow'     => [
                    'mode'       => ($raw_flow['mode'] ?? '') === 'manual' ? 'manual' : 'uniform',
                    'window'     => [min(24, max(0, (int)($win[0] ?? 8))), min(24, max(0, (int)($win[1] ?? 22)))],
                    'volume_pct' => min(150, max(0, (int)($raw_flow['volume_pct'] ?? 100))),
                    'per_hour'   => $per_hour,
                ],
            ];
        }

        /* ============================================================
         * Рендер результату
         * ============================================================ */

        /** Шар A — той самий планувальник, що на сторінці реальної групи */
        private static function plan_html(array $sc): string
        {
            if (empty($sc['partners'])) {
                return '<p class="lr-sim-empty"><em>'
                    . esc_html__('Додайте хоча б одного партнера, щоб побачити схему слотів.', 'leadrouter')
                    . '</em></p>';
            }

            $plan  = LeadRouter_Slot_Planner::plan($sc['partners'], (int)$sc['n'], (int)$sc['l']);
            $hints = LeadRouter_Slot_Sim::hints($sc['partners'], (int)$sc['n'], (int)$sc['l']);

            return LeadRouter_Slot_Planner::render_html($plan) . self::hints_html($hints);
        }

        /** Автопідказки — конкретні числа поруч зі звіркою */
        private static function hints_html(array $hints): string
        {
            if (empty($hints)) {
                return '';
            }

            $html = '<div class="lr-sim-hints"><strong>' . esc_html__('Підказки', 'leadrouter') . '</strong><ul>';
            foreach ($hints as $h) {
                $html .= '<li class="lr-lv-' . esc_attr($h['level']) . '">' . esc_html($h['text']) . '</li>';
            }
            $html .= '</ul></div>';

            return $html;
        }

        /** Шар B — погодинна симуляція */
        private static function sim_html(array $sc): string
        {
            if (empty($sc['partners'])) {
                return '<p class="lr-sim-empty"><em>'
                    . esc_html__('Симуляція запуститься, щойно з’явиться хоча б один партнер.', 'leadrouter')
                    . '</em></p>';
            }

            $r = LeadRouter_Slot_Sim::run($sc['partners'], (int)$sc['n'], (int)$sc['l'], $sc['flow']);
            $n = (int)$sc['n'];

            $html = '';

            // ── метрики ──
            $lds = $r['leads'];
            $c   = $r['copies'];

            $dist = [];
            foreach ($lds['dist'] as $copies => $cnt) {
                $dist[] = sprintf('%d×%d/%d', (int)$cnt, (int)$copies, $n);
            }

            $html .= '<div class="lr-sim-metrics">';
            $html .= self::metric(
                __('Ліди', 'leadrouter'),
                (string)(int)$lds['total'],
                sprintf(
                    /* translators: 1: full, 2: partial, 3: zero */
                    __('%1$d продано повністю · %2$d частково · %3$d без копій', 'leadrouter'),
                    (int)$lds['full'],
                    (int)$lds['partial'],
                    (int)$lds['zero']
                ) . (empty($dist) ? '' : ' (' . implode(', ', $dist) . ')')
            );
            $html .= self::metric(
                __('Копії', 'leadrouter'),
                sprintf('%d / %d', (int)$c['sold'], (int)$c['plan']),
                sprintf(
                    __('одразу %1$d · доборами %2$d · у добори пішло %3$d · згоріло %4$d', 'leadrouter'),
                    (int)$c['direct'],
                    (int)$c['topup'],
                    (int)$c['queued'],
                    (int)$c['burnt']
                )
            );
            $html .= self::metric(
                __('Фактичний продаж', 'leadrouter'),
                self::num((float)$c['pct']) . '%',
                sprintf(__('план — %d копій (N × лідів)', 'leadrouter'), (int)$c['plan']),
                $c['burnt'] > 0 ? 'warn' : 'ok'
            );
            $html .= '</div>';

            // ── findings ──
            if (!empty($r['findings'])) {
                $html .= '<div class="lr-sim-findings">';
                foreach ($r['findings'] as $f) {
                    $html .= '<p class="lr-lv-' . esc_attr($f['level']) . '">' . esc_html($f['text']) . '</p>';
                }
                $html .= '</div>';
            }

            // ── партнери ──
            $html .= '<div class="lr-sim-section">';
            $html .= '<h3 class="lr-sim-subhead">' . esc_html__('Партнери за добу', 'leadrouter') . '</h3>';
            $html .= '<table class="widefat striped lr-sim-report"><thead><tr>'
                . '<th>' . esc_html__('Партнер', 'leadrouter') . '</th>'
                . '<th>' . esc_html__('Власник', 'leadrouter') . '</th>'
                . '<th>' . esc_html__('Вікно', 'leadrouter') . '</th>'
                . '<th class="lr-c-num">' . esc_html__('Ліміт', 'leadrouter') . '</th>'
                . '<th class="lr-c-num">' . esc_html__('Вибрано', 'leadrouter') . '</th>'
                . '<th class="lr-c-num">' . esc_html__('Залишок', 'leadrouter') . '</th>'
                . '<th class="lr-c-num">%</th>'
                . '<th>' . esc_html__('Вичерпав ліміт', 'leadrouter') . '</th>'
                . '</tr></thead><tbody>';

            foreach ($r['partners'] as $p) {
                $html .= '<tr>'
                    . '<td>' . esc_html($p['label']) . '</td>'
                    . '<td>' . ($p['owner_raw'] !== ''
                        ? esc_html($p['owner_raw'])
                        : '<span class="lr-sim-dim">' . esc_html__('сам собі', 'leadrouter') . '</span>') . '</td>'
                    . '<td>' . ($p['valid']
                        ? esc_html(sprintf('%02d–%02d', $p['start_h'], $p['end_h']))
                        : '<span class="lr-lv-error">' . esc_html__('закритий (кінець ≤ початок)', 'leadrouter') . '</span>') . '</td>'
                    . '<td class="lr-c-num">' . (int)$p['limit'] . '</td>'
                    . '<td class="lr-c-num">' . (int)$p['used'] . '</td>'
                    . '<td class="lr-c-num' . ($p['left'] > 0 ? ' lr-lv-warning' : '') . '">' . (int)$p['left'] . '</td>'
                    . '<td class="lr-c-num">' . esc_html(self::num((float)$p['pct'])) . '</td>'
                    . '<td>' . ($p['exhaust_h'] === null
                        ? '<span class="lr-sim-dim">' . esc_html__('не вичерпав', 'leadrouter') . '</span>'
                        : esc_html(sprintf('%02d:00', $p['exhaust_h']))) . '</td>'
                    . '</tr>';
            }
            $html .= '</tbody></table></div>';

            // ── тайм-лайн ──
            $html .= self::timeline_html($r, $n);

            $html .= '<p class="lr-sim-hint">'
                . esc_html__('Модель: крок — година, порядок вибору партнерів — апроксимація дефіцитного WRR (першим той, у кого більший залишок ліміту). Реальний Flow враховує ще коефіцієнти й фактичну статистику дня, тож цифри — оцінка, а не прогноз до одиниці.', 'leadrouter')
                . '</p>';

            return $html;
        }

        /** Погодинний тайм-лайн: скільки прийшло, скільки продано, чи вистачало власників */
        private static function timeline_html(array $r, int $n): string
        {
            $max = 1;
            foreach ($r['hours'] as $row) {
                $max = max($max, (int)$row['leads'] * $n);
            }

            $html = '<div class="lr-sim-section">';
            $html .= '<h3 class="lr-sim-subhead">' . esc_html__('По годинах', 'leadrouter') . '</h3>';
            $html .= '<table class="widefat striped lr-sim-hours"><thead><tr>'
                . '<th>' . esc_html__('Година', 'leadrouter') . '</th>'
                . '<th class="lr-c-num">' . esc_html__('Прийшло лідів', 'leadrouter') . '</th>'
                . '<th class="lr-c-num">' . esc_html__('Продано одразу', 'leadrouter') . '</th>'
                . '<th class="lr-c-num">' . esc_html__('Добори', 'leadrouter') . '</th>'
                . '<th class="lr-c-num">' . esc_html__('Недобір', 'leadrouter') . '</th>'
                . '<th class="lr-c-num">' . esc_html__('Відкрито власників', 'leadrouter') . '</th>'
                . '<th></th>'
                . '</tr></thead><tbody>';

            foreach ($r['hours'] as $h => $row) {
                // порожні години (ні лідів, ні відкритих партнерів) не показуємо
                if ((int)$row['leads'] === 0 && (int)$row['partners_open'] === 0) {
                    continue;
                }

                $sold  = (int)$row['direct'] + (int)$row['topup'];
                $width = (int)round($sold * 100 / $max);
                $lack  = (int)$row['owners_open'] < $n;

                $html .= '<tr>'
                    . '<td>' . esc_html(sprintf('%02d:00', $h)) . '</td>'
                    . '<td class="lr-c-num">' . (int)$row['leads'] . '</td>'
                    . '<td class="lr-c-num">' . (int)$row['direct'] . '</td>'
                    . '<td class="lr-c-num">' . (int)$row['topup'] . '</td>'
                    . '<td class="lr-c-num' . ((int)$row['short'] > 0 ? ' lr-lv-warning' : '') . '">' . (int)$row['short'] . '</td>'
                    . '<td class="lr-c-num' . ($lack ? ' lr-lv-error' : '') . '">' . (int)$row['owners_open'] . '</td>'
                    . '<td class="lr-c-bar"><span style="width:' . max(0, min(100, $width)) . '%"></span></td>'
                    . '</tr>';
            }

            $html .= '</tbody></table></div>';

            return $html;
        }

        /** Картка метрики */
        private static function metric(string $title, string $value, string $sub, string $tone = ''): string
        {
            return '<div class="lr-sim-metric' . ($tone !== '' ? ' lr-tone-' . esc_attr($tone) : '') . '">'
                . '<span class="lr-sim-metric-title">' . esc_html($title) . '</span>'
                . '<span class="lr-sim-metric-value">' . esc_html($value) . '</span>'
                . '<span class="lr-sim-metric-sub">' . esc_html($sub) . '</span>'
                . '</div>';
        }

        /** Число без хвостових нулів (90.0 → 90) */
        private static function num(float $v): string
        {
            return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
        }

        /* ============================================================
         * Дефолтний сценарій
         * ============================================================ */

        /** Партнер за замовчуванням: ліміт 30, вікно 08:00–22:00, власник порожній */
        private static function default_partner(int $i): array
        {
            return [
                'label'   => sprintf(__('Партнер %d', 'leadrouter'), $i + 1),
                'limit'   => 30,
                'owner'   => '',
                'start_h' => 8,
                'end_h'   => 22,
            ];
        }

        /**
         * Стартовий сценарій: 6 партнерів по 40 при N = 6, L = 40 — тобто
         * конфігурація, яка сходиться (Σ = N × L, власників рівно N), щоб було
         * від чого відштовхуватись.
         */
        private static function default_scenario(): array
        {
            $partners = [];
            for ($i = 0; $i < 6; $i++) {
                $p = self::default_partner($i);
                $p['limit'] = 40;
                $p['id']    = $i + 1;
                $partners[] = $p;
            }

            return [
                'n'        => 6,
                'l'        => 40,
                'partners' => $partners,
                'flow'     => [
                    'mode'       => 'uniform',
                    'window'     => [8, 22],
                    'volume_pct' => 100,
                    'per_hour'   => array_fill(0, 24, 0),
                ],
            ];
        }
    }
}
