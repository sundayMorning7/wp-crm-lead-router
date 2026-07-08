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

        /**
         * Лист партнеру з доступами до кабінету / новим паролем.
         * Єдиний канал доставки паролю. НЕ копіюється адміну (пароль адмін не бачить).
         */
        public static function account_access(int $partner_id, string $to, string $login, string $password): bool
        {
            $to = trim($to);
            if ($to === '') {
                return false;
            }

            $row = self::billing_row($partner_id);

            [$subject, $body] = self::get_template('account_access');
            $vars = self::build_vars($partner_id, $row, [
                'login'       => $login,
                'password'    => $password,
                'cabinet_url' => class_exists('LR_Partner_Auth')
                    ? LR_Partner_Auth::cabinet_url()
                    : home_url('/partner/'),
            ]);

            return self::send(
                $to,
                self::render_template($subject, $vars),
                self::render_template($body, $vars),
                $partner_id,
                'account_access'
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
                'account_access' => ['lr_email_account_access_subject', 'lr_email_account_access_body'],
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
                'account_access' => [
                    'subject' => __('LeadRouter: cabinet access — {partner_name}', 'leadrouter'),
                    'body'    => '<p>' . __('Partner <strong>{partner_name}</strong>,', 'leadrouter') . '</p>'
                        . '<p>' . __('Access to your personal cabinet has been created for you.', 'leadrouter') . '</p>'
                        . '<p>' . __('Login: <strong>{login}</strong><br>Password: <strong>{password}</strong>', 'leadrouter') . '</p>'
                        . '<p>' . __('Sign in: <a href="{cabinet_url}">{cabinet_url}</a>', 'leadrouter') . '</p>'
                        . '<p>' . __('For security reasons, please do not share these credentials with anyone.', 'leadrouter') . '</p>'
                        . '<p>{site_name} — {date}</p>',
                ],
                'low_balance' => [
                    'subject' => __('LeadRouter: low balance — {partner_name}', 'leadrouter'),
                    'body'    => '<p>' . __('Partner <strong>{partner_name}</strong>,', 'leadrouter') . '</p>'
                        . '<p>' . __('Your balance {balance} is below the threshold {min_balance}. Please top up your balance to avoid a pause in lead delivery.', 'leadrouter') . '</p>'
                        . '<p>{site_name} — {date}</p>',
                ],
                'stopped' => [
                    'subject' => __('LeadRouter: lead delivery paused — {partner_name}', 'leadrouter'),
                    'body'    => '<p>' . __('Partner <strong>{partner_name}</strong>,', 'leadrouter') . '</p>'
                        . '<p>' . __('Lead delivery has been paused due to insufficient balance ({balance}). Please top up your balance to resume.', 'leadrouter') . '</p>'
                        . '<p>{site_name} — {date}</p>',
                ],
                'admin_negative' => [
                    'subject' => __('LeadRouter: partner negative balance {partner_name}', 'leadrouter'),
                    'body'    => '<p>' . __('Partner <strong>{partner_name}</strong> has a negative balance: {balance}.', 'leadrouter') . '</p>'
                        . '<p>' . __('Threshold: {min_balance}, lead price: {lead_price}.', 'leadrouter') . '</p>'
                        . '<p>{site_name} — {date}</p>',
                ],
                'stripe_success' => [
                    'subject' => __('LeadRouter: balance topped up — {partner_name}', 'leadrouter'),
                    'body'    => '<p>' . __('Partner <strong>{partner_name}</strong>,', 'leadrouter') . '</p>'
                        . '<p>' . __('Your balance has been successfully topped up by {amount}. Current balance: {balance}.', 'leadrouter') . '</p>'
                        . '<p>' . __('Thank you for your business!', 'leadrouter') . '</p>'
                        . '<p>{site_name} — {date}</p>',
                ],
                'stripe_declined' => [
                    'subject' => __('LeadRouter: payment declined — {partner_name}', 'leadrouter'),
                    'body'    => '<p>' . __('Partner <strong>{partner_name}</strong>,', 'leadrouter') . '</p>'
                        . '<p>' . __('A charge to your card was declined ({charge_error}). Lead delivery has been paused.', 'leadrouter') . '</p>'
                        . '<p>' . __('Please update your payment method and top up your balance to resume.', 'leadrouter') . '</p>'
                        . '<p>{site_name} — {date}</p>',
                ],
                'stripe_action' => [
                    'subject' => __('LeadRouter: payment confirmation required — {partner_name}', 'leadrouter'),
                    'body'    => '<p>' . __('Partner <strong>{partner_name}</strong>,', 'leadrouter') . '</p>'
                        . '<p>' . __('Your bank requires additional payment confirmation (3D Secure). Lead delivery has been paused until authentication is completed.', 'leadrouter') . '</p>'
                        . '<p>' . __('Please confirm the payment in your personal cabinet or contact support.', 'leadrouter') . '</p>'
                        . '<p>{site_name} — {date}</p>',
                ],
                'stripe_admin_failed' => [
                    'subject' => __('LeadRouter: Stripe charge failed — {partner_name}', 'leadrouter'),
                    'body'    => '<p>' . __('Failed to top up the balance of partner <strong>{partner_name}</strong> via Stripe.', 'leadrouter') . '</p>'
                        . '<p>' . __('Amount: {amount}. Reason: {charge_error}.', 'leadrouter') . '</p>'
                        . '<p>' . __('Stripe customer: {stripe_customer_id}. Partner balance: {balance}.', 'leadrouter') . '</p>'
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
           
           
           /* return (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m-d H:i');
        }*/
        
        
        // Американський формат дати/часу для листів: 07/06/2026 3:45 PM EST.
            // Мітку EST лишаємо літеральною (як і всюди в плагіні), а не через 'T',
            // щоб влітку не показувати EDT.
            return (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('m/d/Y g:i A \E\S\T');
        }
    }
}
