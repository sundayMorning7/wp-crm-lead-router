<?php
/**
 * LR_Billing_Mailer — відправка email-сповіщень білінгу партнерів.
 *
 * Інкапсулює рендеринг шаблонів і wp_mail, щоб крон (LR_Billing_Cron) лише
 * викликав потрібний тип листа. Шаблони (subject/body) беруться з налаштувань
 * плагіна (Carbon Fields options), а за відсутності — з дефолтних шаблонів.
 *
 * Плейсхолдери в шаблонах (через {placeholder}):
 *   {partner_name} {balance} {min_balance} {lead_price} {email} {site_name} {date}
 *
 * Логіка рендерингу — копія підходу з LeadRouter_Sender_Light (flatten + preg_replace_callback),
 * оригінал не чіпаємо.
 *
 * Кожна відправка логується в audit_log (action=email_sent, status=ok/failed),
 * а невдала — додатково в billing_errors (email_failed).
 */

defined('ABSPATH') || exit;

if (!class_exists('LR_Billing_Mailer')) {

    class LR_Billing_Mailer
    {
        /* ============================================================
         * Публічні типи листів
         * ============================================================ */

        /** Лист партнеру про низький баланс */
        public static function low_balance(int $partner_id, array $partner_row): bool
        {
            // Гейт: вимкнено листи про низький баланс
            if ((int)($partner_row['disable_low_balance_email'] ?? 0) === 1) {
                return false;
            }
            $to = trim((string)($partner_row['email'] ?? ''));
            if ($to === '') {
                return false;
            }

            [$subject, $body] = self::get_template('low_balance');
            $vars = self::build_vars($partner_id, $partner_row);

            return self::send(
                $to,
                self::render_template($subject, $vars),
                self::render_template($body, $vars),
                $partner_id,
                'low_balance'
            );
        }

        /** Лист партнеру про зупинку відправки */
        public static function stopped(int $partner_id, array $partner_row): bool
        {
            $to = trim((string)($partner_row['email'] ?? ''));
            if ($to === '') {
                return false;
            }

            [$subject, $body] = self::get_template('stopped');
            $vars = self::build_vars($partner_id, $partner_row);

            return self::send(
                $to,
                self::render_template($subject, $vars),
                self::render_template($body, $vars),
                $partner_id,
                'stopped'
            );
        }

        /** Лист адміну про від'ємний баланс партнера */
        public static function admin_negative(int $partner_id, array $partner_row): bool
        {
            $to = self::admin_email();
            if ($to === '') {
                return false;
            }

            [$subject, $body] = self::get_template('admin_negative');
            $vars = self::build_vars($partner_id, $partner_row);

            return self::send(
                $to,
                self::render_template($subject, $vars),
                self::render_template($body, $vars),
                $partner_id,
                'admin_negative'
            );
        }

        /* ============================================================
         * Stripe-листи (поповнення балансу)
         * ============================================================ */

        /** Лист партнеру про успішне поповнення через Stripe (чек) */
        public static function stripe_charge_success(int $partner_id, array $data): bool
        {
            $row = self::billing_row($partner_id);
            $to  = trim((string)($row['email'] ?? ''));
            if ($to === '') {
                return false;
            }

            [$subject, $body] = self::get_template('stripe_success');
            $vars = self::build_vars($partner_id, $row, [
                'amount' => self::format_money($data['amount'] ?? 0, $row['currency'] ?? 'USD'),
            ]);

            return self::send(
                $to,
                self::render_template($subject, $vars),
                self::render_template($body, $vars),
                $partner_id,
                'stripe_success'
            );
        }

        /** Лист партнеру: карту відхилено, відправку зупинено */
        public static function stripe_charge_declined(int $partner_id, array $data): bool
        {
            $row = self::billing_row($partner_id);
            $to  = trim((string)($row['email'] ?? ''));
            if ($to === '') {
                return false;
            }

            [$subject, $body] = self::get_template('stripe_declined');
            $vars = self::build_vars($partner_id, $row, [
                'charge_error' => (string)($data['reason'] ?? ($data['message'] ?? '')),
            ]);

            return self::send(
                $to,
                self::render_template($subject, $vars),
                self::render_template($body, $vars),
                $partner_id,
                'stripe_declined'
            );
        }

        /** Лист партнеру: потрібне 3DS-підтвердження, відправку зупинено */
        public static function stripe_charge_action(int $partner_id, array $data): bool
        {
            $row = self::billing_row($partner_id);
            $to  = trim((string)($row['email'] ?? ''));
            if ($to === '') {
                return false;
            }

            [$subject, $body] = self::get_template('stripe_action');
            $vars = self::build_vars($partner_id, $row, [
                'charge_error' => (string)($data['reason'] ?? 'authentication_required'),
            ]);

            return self::send(
                $to,
                self::render_template($subject, $vars),
                self::render_template($body, $vars),
                $partner_id,
                'stripe_action'
            );
        }

        /** Лист адміну: Stripe auto-charge не вдався */
        public static function stripe_admin_failed(int $partner_id, array $data): bool
        {
            $to = self::admin_email();
            if ($to === '') {
                return false;
            }

            $row = self::billing_row($partner_id);

            [$subject, $body] = self::get_template('stripe_admin_failed');
            $vars = self::build_vars($partner_id, $row, [
                'amount'       => self::format_money($data['amount'] ?? 0, $row['currency'] ?? 'USD'),
                'charge_error' => (string)($data['reason'] ?? ($data['message'] ?? '')),
            ]);

            return self::send(
                $to,
                self::render_template($subject, $vars),
                self::render_template($body, $vars),
                $partner_id,
                'stripe_admin_failed'
            );
        }

        /** Повний білінг-рядок партнера (для плейсхолдерів Stripe-листів) */
        private static function billing_row(int $partner_id): array
        {
            global $wpdb;
            $t = $wpdb->prefix . 'leadrouter_partner_billing';
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$t} WHERE partner_id = %d LIMIT 1", $partner_id),
                ARRAY_A
            );
            return is_array($row) ? $row : [];
        }

        /** Форматування суми з валютою (напр. "50.00 USD") */
        private static function format_money($amount, string $currency = 'USD'): string
        {
            return number_format((float)$amount, 2) . ' ' . strtoupper($currency);
        }

        /* ============================================================
         * Рендеринг шаблонів (копія підходу з Sender_Light)
         * ============================================================ */

        /** Заміна {placeholder} на значення з $vars */
        private static function render_template(string $template, array $vars): string
        {
            $flat = self::flatten($vars);
            return preg_replace_callback('/\{([a-zA-Z0-9_.]+)\}/', function ($m) use ($flat) {
                $key = $m[1];
                return array_key_exists($key, $flat) ? (string)$flat[$key] : '';
            }, $template);
        }

        /**
         * Плоска карта значень для шаблонів (копія flatten_for_templates із Sender_Light).
         * Підтримує вкладені масиви та нумеровані списки (key.0.subkey).
         */
        private static function flatten(array $data, string $prefix = ''): array
        {
            $out = [];

            foreach ($data as $key => $value) {
                $key  = (string)$key;
                $full = ($prefix === '') ? $key : ($prefix . '.' . $key);

                if (is_array($value)) {
                    $is_list = array_keys($value) === range(0, count($value) - 1);

                    if ($is_list) {
                        foreach ($value as $idx => $item) {
                            $sub_prefix = $full . '.' . $idx;
                            if (is_array($item)) {
                                $out += self::flatten($item, $sub_prefix);
                            } else {
                                $out[$sub_prefix] = $item;
                            }
                        }
                    } else {
                        $out += self::flatten($value, $full);
                    }
                } else {
                    $out[$full] = $value;
                    // Для верхнього рівня дублюємо ключ без префікса
                    if ($prefix === '') {
                        $out[$key] = $value;
                    }
                }
            }

            return $out;
        }

        /* ============================================================
         * Відправка (обгортка над wp_mail) + логування
         * ============================================================ */
        private static function send(string $to, string $subject, string $body, int $partner_id = 0, string $type = ''): bool
        {
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            $ok = (bool)wp_mail($to, $subject, $body, $headers);

            // Дублюємо клієнтські листи окремою копією на адмінську пошту.
            // Окремий wp_mail (а не Bcc) — щоб копія не злипалась і чітко
            // позначалась префіксом [ADMIN COPY]. Адмінські типи листів
            // (admin_negative / stripe_admin_failed) і так ідуть адміну — їх не копіюємо.
            $client_types = ['low_balance', 'stopped', 'stripe_success', 'stripe_declined', 'stripe_action'];
            if (in_array($type, $client_types, true)) {
                $admin = self::admin_email();
                if ($admin !== '' && strcasecmp($admin, $to) !== 0) {
                    wp_mail($admin, '[ADMIN COPY] ' . $subject, $body, $headers);
                }
            }

            // Лог у audit_log
            LR_Billing::log_audit([
                'partner_id'   => $partner_id,
                'actor_type'   => 'system',
                'action'       => 'email_sent',
                'entity_type'  => 'partner_billing',
                'context_json' => [
                    'type'    => $type,
                    'to'      => $to,
                    'status'  => $ok ? 'ok' : 'failed',
                    'subject' => $subject,
                ],
            ]);

            // Невдача → окремий запис у billing_errors
            if (!$ok) {
                LR_Billing::log_error(
                    $partner_id,
                    'email_failed',
                    'warning',
                    'wp_mail() повернув false при відправці листа',
                    ['type' => $type, 'to' => $to, 'source' => 'billing_mailer']
                );
            }

            return $ok;
        }

        /* ============================================================
         * Шаблони і налаштування
         * ============================================================ */

        /**
         * Повертає [subject, body] для типу листа.
         * Спершу з налаштувань плагіна, інакше — дефолтний шаблон.
         */
        private static function get_template(string $type): array
        {
            $map = [
                'low_balance'    => ['lr_email_low_balance_subject', 'lr_email_low_balance_body'],
                'stopped'        => ['lr_email_stopped_subject', 'lr_email_stopped_body'],
                'admin_negative' => ['lr_email_admin_negative_subject', 'lr_email_admin_negative_body'],
                'stripe_success'      => ['lr_email_stripe_success_subject', 'lr_email_stripe_success_body'],
                'stripe_declined'     => ['lr_email_stripe_declined_subject', 'lr_email_stripe_declined_body'],
                'stripe_action'       => ['lr_email_stripe_action_subject', 'lr_email_stripe_action_body'],
                'stripe_admin_failed' => ['lr_email_stripe_admin_failed_subject', 'lr_email_stripe_admin_failed_body'],
            ];

            if (!isset($map[$type])) {
                return ['', ''];
            }

            [$subj_key, $body_key] = $map[$type];

            $subject = self::opt($subj_key);
            $body    = self::opt($body_key);

            $defaults = self::default_templates();
            if ($subject === '') {
                $subject = $defaults[$type]['subject'];
            }
            if ($body === '') {
                $body = $defaults[$type]['body'];
            }

            return [$subject, $body];
        }

        /** Дефолтні шаблони (HTML) — на випадок порожніх налаштувань */
        private static function default_templates(): array
        {
            return [
                'low_balance' => [
                    'subject' => __('LeadRouter: низький баланс — {partner_name}', 'leadrouter'),
                    'body'    => '<p>' . __('Партнер <strong>{partner_name}</strong>,', 'leadrouter') . '</p>'
                        . '<p>' . __('Ваш баланс {balance} нижчий за поріг {min_balance}. Поповніть баланс, щоб уникнути зупинки відправки лідів.', 'leadrouter') . '</p>'
                        . '<p>{site_name} — {date}</p>',
                ],
                'stopped' => [
                    'subject' => __('LeadRouter: відправку лідів зупинено — {partner_name}', 'leadrouter'),
                    'body'    => '<p>' . __('Партнер <strong>{partner_name}</strong>,', 'leadrouter') . '</p>'
                        . '<p>' . __('Відправку лідів зупинено через недостатній баланс ({balance}). Поповніть баланс для відновлення.', 'leadrouter') . '</p>'
                        . '<p>{site_name} — {date}</p>',
                ],
                'admin_negative' => [
                    'subject' => __('LeadRouter: від\'ємний баланс партнера {partner_name}', 'leadrouter'),
                    'body'    => '<p>' . __('У партнера <strong>{partner_name}</strong> від\'ємний баланс: {balance}.', 'leadrouter') . '</p>'
                        . '<p>' . __('Поріг: {min_balance}, ціна ліда: {lead_price}.', 'leadrouter') . '</p>'
                        . '<p>{site_name} — {date}</p>',
                ],
                'stripe_success' => [
                    'subject' => __('LeadRouter: баланс поповнено — {partner_name}', 'leadrouter'),
                    'body'    => '<p>' . __('Партнер <strong>{partner_name}</strong>,', 'leadrouter') . '</p>'
                        . '<p>' . __('Ваш баланс успішно поповнено на {amount}. Поточний баланс: {balance}.', 'leadrouter') . '</p>'
                        . '<p>' . __('Дякуємо за співпрацю!', 'leadrouter') . '</p>'
                        . '<p>{site_name} — {date}</p>',
                ],
                'stripe_declined' => [
                    'subject' => __('LeadRouter: оплату відхилено — {partner_name}', 'leadrouter'),
                    'body'    => '<p>' . __('Партнер <strong>{partner_name}</strong>,', 'leadrouter') . '</p>'
                        . '<p>' . __('Списання з вашої картки відхилено ({charge_error}). Відправку лідів призупинено.', 'leadrouter') . '</p>'
                        . '<p>' . __('Будь ласка, оновіть платіжний метод і поповніть баланс для відновлення.', 'leadrouter') . '</p>'
                        . '<p>{site_name} — {date}</p>',
                ],
                'stripe_action' => [
                    'subject' => __('LeadRouter: потрібне підтвердження оплати — {partner_name}', 'leadrouter'),
                    'body'    => '<p>' . __('Партнер <strong>{partner_name}</strong>,', 'leadrouter') . '</p>'
                        . '<p>' . __('Банк вимагає додаткове підтвердження платежу (3D Secure). Відправку лідів призупинено до завершення автентифікації.', 'leadrouter') . '</p>'
                        . '<p>' . __('Будь ласка, підтвердьте оплату в особистому кабінеті або зверніться до підтримки.', 'leadrouter') . '</p>'
                        . '<p>{site_name} — {date}</p>',
                ],
                'stripe_admin_failed' => [
                    'subject' => __('LeadRouter: Stripe charge не вдався — {partner_name}', 'leadrouter'),
                    'body'    => '<p>' . __('Не вдалося поповнити баланс партнера <strong>{partner_name}</strong> через Stripe.', 'leadrouter') . '</p>'
                        . '<p>' . __('Сума: {amount}. Причина: {charge_error}.', 'leadrouter') . '</p>'
                        . '<p>' . __('Stripe customer: {stripe_customer_id}. Баланс партнера: {balance}.', 'leadrouter') . '</p>'
                        . '<p>{site_name} — {date}</p>',
                ],
            ];
        }

        /**
         * Набір плейсхолдерів для одного листа.
         * $extra — додаткові значення від caller (напр. Stripe: amount, charge_error),
         * що перезаписують дефолти.
         */
        private static function build_vars(int $partner_id, array $row, array $extra = []): array
        {
            $currency = (string)($row['currency'] ?? 'USD');

            // {partner_name}: спершу беремо partner_display_name з білінг-профілю,
            // якщо порожній — fallback на заголовок поста партнера (поточна поведінка).
            // Якщо $row не містить колонки (напр. частковий SELECT крона) — дочитуємо з БД.
            $display_name = trim((string)($row['partner_display_name'] ?? ''));
            if ($display_name === '' && !array_key_exists('partner_display_name', $row)) {
                $billing = self::billing_row($partner_id);
                $display_name = trim((string)($billing['partner_display_name'] ?? ''));
            }
            if ($display_name === '') {
                $display_name = get_the_title($partner_id) ?: ('#' . $partner_id);
            }

            $vars = [
                'partner_name' => $display_name,
                'balance'      => number_format((float)($row['balance'] ?? 0), 2) . ' ' . $currency,
                'min_balance'  => number_format((float)($row['min_balance'] ?? 0), 2) . ' ' . $currency,
                // {lead_price} — актуальна ціна ліда на сьогодні по EST (weekday/saturday/sunday)
                'lead_price'   => number_format(LR_Billing::get_lead_price_for_today($partner_id), 2) . ' ' . $currency,
                'email'        => (string)($row['email'] ?? ''),
                'site_name'    => get_bloginfo('name'),
                'date'         => self::now_est(),
                // Stripe-плейсхолдери: порожні за замовчуванням, заповнює caller через $extra
                'amount'             => '',
                'charge_error'       => '',
                'stripe_customer_id' => (string)($row['stripe_customer_id'] ?? ''),
            ];
            return array_merge($vars, $extra);
        }

        /** Email адміна: налаштування плагіна → fallback на загальний email сайту */
        private static function admin_email(): string
        {
            $admin = self::opt('lr_billing_admin_email');
            if ($admin === '') {
                $admin = (string)get_option('admin_email');
            }
            return trim($admin);
        }

        /**
         * Читання налаштування плагіна.
         * Carbon Fields зберігає theme_options з префіксом «_», тож читаємо через
         * carbon_get_theme_option(), а як фолбек — звичайний get_option().
         */
        private static function opt(string $name): string
        {
            if (function_exists('carbon_get_theme_option')) {
                $val = carbon_get_theme_option($name);
                if ($val !== null && $val !== '') {
                    return (string)$val;
                }
            }
            $val = get_option($name);
            return $val !== false && $val !== null ? (string)$val : '';
        }

        /** Поточна дата/час в EST */
        private static function now_est(): string
        {
            return (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m-d H:i');
        }
    }
}
