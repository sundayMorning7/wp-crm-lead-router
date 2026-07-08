<?php
/**
 * LR_Complaints — скарги/претензії партнера на конкретний лід.
 *
 * Інбокс-логіка (без резолюцій і білінг-дій): партнер з кабінету подає скаргу на
 * свій лід → запис у таблицю leadrouter_lead_complaints (status new/read) + лист
 * на пошту скарг. Одна скарга на лід (UNIQUE partner_id+lead_id + явна перевірка).
 *
 * Безпека:
 *   - partner_id береться ЛИШЕ з серверної сесії (LR_Partner_Auth::current_partner_id()),
 *     ніколи з GET/POST;
 *   - lead_id із запиту валідується: лід має належати партнеру (є його spend-транзакція),
 *     інакше 403;
 *   - сабміт — через AJAX з nonce; message — sanitize_textarea_field.
 *
 * Час — EST (America/New_York). Префікс таблиць — лише $wpdb->prefix.
 * Усі user-facing рядки і лист — англійською (text domain leadrouter).
 */

defined('ABSPATH') || exit;

if (!class_exists('LR_Complaints')) {

    class LR_Complaints
    {
        /** Nonce-action AJAX-сабміту скарги */
        const NONCE = 'lr_complaint';

        /** AJAX-екшен */
        const AJAX_ACTION = 'lr_submit_complaint';

        /** Мін./макс. довжина повідомлення */
        const MSG_MIN = 5;
        const MSG_MAX = 2000;

        /** Часова зона проекту */
        private const TZ = 'America/New_York';

        /** Базові теми (засів) — англійською. Завжди доступна ще «Other». */
        const SEED_TOPICS = [
            'Inaccurate lead data',
            'Duplicate / never requested',
            'Bad / invalid phone number',
        ];

        /* ============================================================
         * Реєстрація
         * ============================================================ */
        public static function register(): void
        {
            // Сабміт доступний лише залогіненому партнеру (без nopriv)
            add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajax_submit']);
        }

        /** Імʼя таблиці скарг */
        private static function table(): string
        {
            global $wpdb;
            return $wpdb->prefix . 'leadrouter_lead_complaints';
        }

        /* ============================================================
         * Теми претензій (з налаштувань)
         * ============================================================ */
        /**
         * Список тем для модалки/валідації (рядки). Береться з Carbon-поля
         * lr_complaint_topics (complex: повторюваний topic). Порожні — відсіюються.
         * «Other» сюди НЕ входить — додається окремо в UI і валідації.
         *
         * @return string[]
         */
        public static function get_topics(): array
        {
            $rows = [];
            if (function_exists('carbon_get_theme_option')) {
                $rows = carbon_get_theme_option('lr_complaint_topics');
            }
            $out = [];
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $t = trim((string)($r['topic'] ?? ''));
                    if ($t !== '') {
                        $out[] = $t;
                    }
                }
            }
            return $out;
        }

        /** Літерал теми «Other» (єдине джерело тексту) */
        public static function other_topic(): string
        {
            return __('Other', 'leadrouter');
        }

        /* ============================================================
         * Перевірки власності / дублів
         * ============================================================ */
        /**
         * Чи належить лід партнеру: існує його spend-транзакція з цим lead_id.
         * (Дзеркало логіки таблиці лідів кабінету — джерело правди по «своїх» лідах.)
         */
        public static function partner_owns_lead(int $partner_id, int $lead_id): bool
        {
            if ($partner_id <= 0 || $lead_id <= 0) {
                return false;
            }
            global $wpdb;
            $t_tx = $wpdb->prefix . 'leadrouter_billing_transactions';
            $cnt = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$t_tx} WHERE partner_id = %d AND lead_id = %d AND type = 'spend'",
                $partner_id, $lead_id
            ));
            return $cnt > 0;
        }

        /** Чи вже є скарга цього партнера на цей лід */
        public static function has_complaint(int $partner_id, int $lead_id): bool
        {
            if ($partner_id <= 0 || $lead_id <= 0) {
                return false;
            }
            global $wpdb;
            $t = self::table();
            $cnt = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$t} WHERE partner_id = %d AND lead_id = %d",
                $partner_id, $lead_id
            ));
            return $cnt > 0;
        }

        /**
         * Множина lead_id (із переданих), на які цей партнер уже скаржився.
         * Використовує кабінет, щоб показати бейдж «Complaint sent» без N+1.
         *
         * @param int[] $lead_ids
         * @return array<int,true> set lead_id => true
         */
        public static function complained_map(int $partner_id, array $lead_ids): array
        {
            $lead_ids = array_values(array_unique(array_filter(array_map('intval', $lead_ids))));
            if ($partner_id <= 0 || empty($lead_ids)) {
                return [];
            }
            global $wpdb;
            $t = self::table();
            $placeholders = implode(',', array_fill(0, count($lead_ids), '%d'));
            $params = array_merge([$partner_id], $lead_ids);
            $found = $wpdb->get_col($wpdb->prepare(
                "SELECT lead_id FROM {$t} WHERE partner_id = %d AND lead_id IN ({$placeholders})",
                $params
            ));
            $map = [];
            foreach ((array) $found as $lid) {
                $map[(int) $lid] = true;
            }
            return $map;
        }

        /* ============================================================
         * Сабміт скарги (core)
         * ============================================================ */
        /**
         * Створити скаргу від імені поточного партнера (із сесії).
         *
         * @return array{ok:bool,code:string,message:string,status:int}
         *   status — бажаний HTTP-код для AJAX-відповіді.
         */
        public static function submit(int $lead_id, string $topic, string $message): array
        {
            // 1) partner_id — ЛИШЕ із сесії
            $partner_id = class_exists('LR_Partner_Auth') ? LR_Partner_Auth::current_partner_id() : 0;
            if ($partner_id <= 0) {
                return self::err('not_partner', __('Your session has expired. Please log in again.', 'leadrouter'), 403);
            }

            // 2) лід має належати партнеру
            if ($lead_id <= 0 || !self::partner_owns_lead($partner_id, $lead_id)) {
                return self::err('forbidden_lead', __('This lead does not belong to you.', 'leadrouter'), 403);
            }

            // 3) тема — з налаштованого списку або «Other»
            $topic = trim($topic);
            $allowed = self::get_topics();
            $allowed[] = self::other_topic();
            if ($topic === '' || !in_array($topic, $allowed, true)) {
                return self::err('bad_topic', __('Please choose a valid complaint topic.', 'leadrouter'), 400);
            }

            // 4) повідомлення — sanitize + межі довжини
            $message = sanitize_textarea_field($message);
            if (mb_strlen($message) < self::MSG_MIN) {
                return self::err('short_message',
                    sprintf(__('Please describe the issue (at least %d characters).', 'leadrouter'), self::MSG_MIN),
                    400);
            }
            if (mb_strlen($message) > self::MSG_MAX) {
                $message = mb_substr($message, 0, self::MSG_MAX);
            }

            // 5) одна скарга на лід (явна перевірка перед INSERT)
            if (self::has_complaint($partner_id, $lead_id)) {
                return self::err('already', __('A complaint for this lead has already been submitted.', 'leadrouter'), 409);
            }

            // 6) INSERT (status=new, created_at EST). UNIQUE підстрахує від гонок.
            global $wpdb;
            $t = self::table();
            $ok = $wpdb->insert(
                $t,
                [
                    'lead_id'    => $lead_id,
                    'partner_id' => $partner_id,
                    'topic'      => $topic,
                    'message'    => $message,
                    'status'     => 'new',
                    'created_at' => self::now_est(),
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s']
            );

            if ($ok === false) {
                // Можлива гонка: UNIQUE спрацював між перевіркою і вставкою
                if (self::has_complaint($partner_id, $lead_id)) {
                    return self::err('already', __('A complaint for this lead has already been submitted.', 'leadrouter'), 409);
                }
                return self::err('db_error', __('Could not submit the complaint. Please try again.', 'leadrouter'), 500);
            }

            $complaint_id = (int) $wpdb->insert_id;

            // 7) лист адміну скарг (помилка листа НЕ скасовує збереження)
            self::notify_admin($complaint_id, $partner_id, $lead_id, $topic, $message);

            return [
                'ok'      => true,
                'code'    => 'created',
                'message' => __('Your complaint has been submitted. Thank you.', 'leadrouter'),
                'status'  => 200,
            ];
        }

        /* ============================================================
         * AJAX-ендпоінт
         * ============================================================ */
        public static function ajax_submit(): void
        {
            check_ajax_referer(self::NONCE, 'nonce');

            // Право: лише залогінений партнер (partner_id перевіряється в submit())
            $lead_id = isset($_POST['lead_id']) ? absint($_POST['lead_id']) : 0;
            $topic   = isset($_POST['topic']) ? sanitize_text_field(wp_unslash($_POST['topic'])) : '';
            $message = isset($_POST['message']) ? wp_unslash($_POST['message']) : '';

            $res = self::submit($lead_id, $topic, (string) $message);

            if (!empty($res['ok'])) {
                wp_send_json_success(['message' => $res['message']], (int) ($res['status'] ?? 200));
            }
            wp_send_json_error(
                ['message' => $res['message'], 'code' => $res['code']],
                (int) ($res['status'] ?? 400)
            );
        }

        /* ============================================================
         * Лист адміну скарг
         * ============================================================ */
        /**
         * Отримувач: lr_complaints_email → lr_billing_admin_email → admin_email.
         * Лист — wp_mail (HTML). Повертає bool лише для тестів; помилка не критична.
         */
        public static function notify_admin(int $complaint_id, int $partner_id, int $lead_id, string $topic, string $message): bool
        {
            $to = self::complaints_email();
            if ($to === '') {
                return false;
            }

            $partner_name = get_the_title($partner_id) ?: ('#' . $partner_id);
            $lead = self::lead_brief($lead_id);
            $admin_link = admin_url('admin.php?page=leadrouter-complaints');

            $subject = sprintf(
                /* translators: 1: lead id, 2: partner name */
                __('New complaint for lead #%1$d from %2$s', 'leadrouter'),
                $lead_id,
                $partner_name
            );

            $rows = [
                __('Partner', 'leadrouter')   => $partner_name . ' (#' . $partner_id . ')',
                __('Lead', 'leadrouter')      => '#' . $lead_id,
                __('Customer', 'leadrouter')  => (string) ($lead['name'] ?? ''),
                __('Phone', 'leadrouter')     => (string) ($lead['phone'] ?? ''),
                __('Email', 'leadrouter')     => (string) ($lead['email'] ?? ''),
                __('Topic', 'leadrouter')     => $topic,
                __('Submitted (EST)', 'leadrouter') => self::now_est(),
            ];

            $body  = '<p>' . esc_html__('A partner has submitted a new complaint.', 'leadrouter') . '</p>';
            $body .= '<table cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;">';
            foreach ($rows as $label => $val) {
                if (trim((string) $val) === '') {
                    continue;
                }
                $body .= '<tr>'
                    . '<td style="vertical-align:top;color:#666;"><strong>' . esc_html($label) . ':</strong></td>'
                    . '<td style="vertical-align:top;">' . esc_html($val) . '</td>'
                    . '</tr>';
            }
            $body .= '</table>';
            $body .= '<p><strong>' . esc_html__('Message', 'leadrouter') . ':</strong></p>';
            $body .= '<p style="white-space:pre-wrap;">' . nl2br(esc_html($message)) . '</p>';
            $body .= '<p><a href="' . esc_url($admin_link) . '">' . esc_html__('Open complaints inbox', 'leadrouter') . '</a></p>';

            $headers = ['Content-Type: text/html; charset=UTF-8'];
            return (bool) wp_mail($to, $subject, $body, $headers);
        }

        /**
         * Пошта для скарг: налаштування lr_complaints_email → білінг-пошта
         * lr_billing_admin_email → системний admin_email WP.
         */
        public static function complaints_email(): string
        {
            $email = self::opt('lr_complaints_email');
            if ($email === '') {
                $email = self::opt('lr_billing_admin_email');
            }
            if ($email === '') {
                $email = (string) get_option('admin_email');
            }
            return trim($email);
        }

        /* ============================================================
         * Адмін: вибірки списку + позначення прочитаним
         * ============================================================ */
        /** Позначити одну скаргу прочитаною (new → read). cap-перевірка — у виклику. */
        public static function mark_read(int $complaint_id): bool
        {
            if ($complaint_id <= 0) {
                return false;
            }
            global $wpdb;
            $t = self::table();
            $res = $wpdb->update(
                $t,
                ['status' => 'read'],
                ['id' => $complaint_id, 'status' => 'new'],
                ['%s'],
                ['%d', '%s']
            );
            return $res !== false;
        }

        /** Кількість непрочитаних скарг (для лічильника меню) */
        public static function count_new(): int
        {
            global $wpdb;
            $t = self::table();
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status = 'new'");
        }

        /* ============================================================
         * Хелпери
         * ============================================================ */

        /** Короткі дані ліда для листа (name/email/phone) */
        private static function lead_brief(int $lead_id): array
        {
            global $wpdb;
            $t_l = $wpdb->prefix . 'leadrouter_leads';
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT name, email, phone FROM {$t_l} WHERE id = %d LIMIT 1",
                $lead_id
            ), ARRAY_A);
            return is_array($row) ? $row : [];
        }

        /** Читання налаштування плагіна (Carbon theme_option → get_option) */
        private static function opt(string $name): string
        {
            if (function_exists('carbon_get_theme_option')) {
                $val = carbon_get_theme_option($name);
                if ($val !== null && $val !== '') {
                    return (string) $val;
                }
            }
            $val = get_option($name);
            return $val !== false && $val !== null ? (string) $val : '';
        }

        /** Поточний час у EST у форматі MySQL */
        private static function now_est(): string
        {
            return (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('Y-m-d H:i:s');
        }

        /** Уніфікована помилка результату */
        private static function err(string $code, string $message, int $status): array
        {
            return ['ok' => false, 'code' => $code, 'message' => $message, 'status' => $status];
        }
    }
}
