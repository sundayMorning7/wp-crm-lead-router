<?php

use Carbon_Fields\Container;
use Carbon_Fields\Field;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register custom post types for LeadRouter: Groups and Partners
 */
function leadrouter_register_cpts()
{
    // Groups CPT
    register_post_type('leadrouter_group', array(
        'labels' => array(
            'name' => __('Групи', 'leadrouter'),
            'singular_name' => __('Група', 'leadrouter'),
        ),
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'supports' => array('title'),
        'capability_type' => 'post',
        'menu_icon' => 'dashicons-groups',
    ));

    // Partners CPT
    register_post_type('leadrouter_partner', array(
        'labels' => array(
            'name' => __('Партнери', 'leadrouter'),
            'singular_name' => __('Партнер', 'leadrouter'),
        ),
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'supports' => array('title'),
        'capability_type' => 'post',
        'menu_icon' => 'dashicons-businessman',
    ));
}

add_action('init', 'leadrouter_register_cpts');

/**
 * Carbon Fields meta
 */
function leadrouter_create_custom_fields()
{

    // OPTIONS

    // Підказка зі списком доступних плейсхолдерів для шаблонів листів білінгу
    $lr_email_ph_help = __('Available placeholders: {partner_name} {balance} {min_balance} {lead_price} {email} {site_name} {date}', 'leadrouter');

    // Підказка для Stripe-листів — додаються плейсхолдери поповнення/помилки
    $lr_email_ph_help_stripe = __('Available placeholders: {amount} {charge_error} {stripe_customer_id} {partner_name} {balance} {min_balance} {lead_price} {email} {site_name} {date}', 'leadrouter');

    Container::make('theme_options', __('LeadRouter Settings', 'leadrouter'))
        ->set_page_parent('leadrouter')
        ->set_page_menu_title(__('Налаштування', 'leadrouter'))
        ->set_page_file('leadrouter-settings')
        ->add_tab(__('Основні', 'leadrouter'), array(

/*
            Field::make('number', 'leadrouter_pause_min', __('Pause between sends (MIN, sec)', 'leadrouter'))
                ->set_attribute('min', 0)
                ->set_default_value(5)
                ->set_help_text('Мінімальна затримка між відправками'),

            Field::make('number', 'leadrouter_pause_max', __('Pause between sends (MAX, sec)', 'leadrouter'))
                ->set_attribute('min', 0)
                ->set_default_value(15)
                ->set_help_text('Максимальна затримка між відправками'),*/

/*
            Field::make('select', 'leadrouter_default_group', __('Група за замовчуванням', 'leadrouter'))
                ->add_options(function () {
                    $options = array(
                        '0' => '—',
                    );

                    $groups = get_posts(array(
                        'post_type' => 'leadrouter_group',
                        'posts_per_page' => -1,
                        'post_status' => 'publish',
                        'orderby' => 'title',
                        'order' => 'ASC',
                    ));

                    foreach ($groups as $group) {
                        $options[$group->ID] = $group->post_title;
                    }

                    return $options;
                })
                ->set_default_value('0'),*/

        ))
        ->add_tab(__('Dispatch', 'leadrouter'), array(

            Field::make('text', 'leadrouter_pause_min', __('Pause between sends (MIN, min)', 'leadrouter'))
                ->set_attribute('min', 0)
                ->set_default_value(5)
                ->set_help_text('Мінімальна затримка між відправками в хвилинах' ),

            Field::make('text', 'leadrouter_pause_max', __('Pause between sends (MAX, min)', 'leadrouter'))
                ->set_attribute('min', 0)
                ->set_default_value(15)
                ->set_help_text('Максимальна затримка між відправками в хвилинах'),

                Field::make('select', 'leadrouter_error_group_id', __('Група для помилкових статусів', 'leadrouter'))
                    ->add_options(function () {
                        $options = array(
                            '0' => '—',
                        );

                        $groups = get_posts(array(
                            'post_type' => 'leadrouter_group',
                            'posts_per_page' => -1,
                            'post_status' => 'publish',
                            'orderby' => 'title',
                            'order' => 'ASC',
                        ));

                        foreach ($groups as $group) {
                            $options[$group->ID] = $group->post_title;
                        }

                        return $options;
                    })
                    ->set_default_value('0'),

            // ===== Анти-дубль по телефону/email =====
            Field::make('checkbox', 'leadrouter_duplicate_check_enabled', __('Перевіряти ліди на дублі', 'leadrouter'))
                ->set_help_text('Позначати лід як дубль, якщо телефон або email уже зустрічались за останні N діб. Дубль отримує статус error і відправляється у Групу для помилкових статусів.'),

            // тип text (а не number) — у вбудованій версії Carbon Fields
            // поля 'number' немає, як і в сусідніх leadrouter_pause_*
            Field::make('text', 'leadrouter_duplicate_window_days', __('Вікно пошуку дублів, діб', 'leadrouter'))
                ->set_attribute('type', 'number')
                ->set_attribute('min', 1)
                ->set_default_value(30)
                ->set_help_text('Вікно пошуку дублів у добах (EST). Працює, лише коли увімкнена перевірка дублів.'),

            // ===== Термін давності досилки (await / sent_partial) =====
            Field::make('checkbox', 'leadrouter_retry_no_expiry', __('Досилати ліди безстроково', 'leadrouter'))
                ->set_default_value(true)
                ->set_help_text('Увімкнено — await/sent_partial ліди добираються без обмеження за віком (поточна поведінка). Вимкнено — діє термін із поля нижче; прострочені ліди отримують термінальний статус expired і більше не досилаються.'),

            Field::make('text', 'leadrouter_retry_max_hours', __('Термін досилки, годин', 'leadrouter'))
                ->set_attribute('type', 'number')
                ->set_attribute('min', 0)
                ->set_default_value(0)
                ->set_help_text('Діє, лише коли безстрокову досилку вимкнено. 0 — досилати до кінця доби EST, у яку створено лід; N > 0 — не довше N годин від створення ліда.'),

            Field::make('checkbox', 'leadrouter_shared_topup_enabled', __('Автоматично добирати копії shared-лідів', 'leadrouter'))
                ->set_default_value(true)
                ->set_help_text('Увімкнено — крон сам добирає відсутні копії (2/6 → 6/6). Вимкнено — лід лишається як є, копії добирає менеджер вручну. На первинну доставку (await) не впливає.'),


/*
            Field::make('select', 'leadrouter_dispatch_method', __('Метод відправки', 'leadrouter'))
                ->add_options(array(
                    'manual' => __('Manual', 'leadrouter'),
                    'script' => __('Script', 'leadrouter'),
                    'cron'   => __('Cron', 'leadrouter'),
                ))
                ->set_default_value('script'),

            Field::make('checkbox', 'leadrouter_queue_if_closed', __('Ставити в чергу, якщо партнер закритий', 'leadrouter'))
                ->set_option_value('yes')
                ->set_default_value('yes'),*/

        ))
        // ===== Резервні групи =====
        // Групи, які на панелі балансування показуються ПОЗА ЧЕРГОЮ — окремим
        // рядком над сіткою й незалежно від звичайних фільтрів (немає партнерів,
        // нульові ліміти на сьогодні, група вимкнена). Резерв має бути видно
        // завжди: туди йде те, що не розібрали інші.
        //
        // Зберігаємо ІДЕНТИФІКАТОРИ груп (post_id), а не назви: групу можуть
        // перейменувати, і прив'язка за назвою тихо розсиплеться.
        ->add_tab(__('Резервні групи', 'leadrouter'), array(

            Field::make('set', 'lr_reserve_groups', __('Резервні групи', 'leadrouter'))
                ->add_options(function () {
                    global $wpdb;

                    // Режим і прапорець active — з leadrouter_groups, назву беремо
                    // з самого поста: так список не залежить від синхронізації
                    // колонки name. Групи в кошику не показуємо — вони не беруть
                    // участі в розподілі, тож і в резерв їх ставити нема сенсу.
                    $rows = $wpdb->get_results(
                        "SELECT g.post_id, p.post_title AS name, g.mode, g.active
                           FROM {$wpdb->prefix}leadrouter_groups g
                           JOIN {$wpdb->posts} p
                             ON p.ID = g.post_id
                            AND p.post_type = 'leadrouter_group'
                            AND p.post_status <> 'trash'
                          ORDER BY p.post_title",
                        ARRAY_A
                    ) ?: array();

                    $options = array();
                    foreach ($rows as $r) {
                        $pid = (int) $r['post_id'];
                        if ($pid <= 0) {
                            continue;
                        }

                        // режим і стан — прямо в підписі, щоб не тримати окрему таблицю
                        $options[$pid] = sprintf(
                            '%s — #%d, %s, %s',
                            $r['name'],
                            $pid,
                            (string) $r['mode'],
                            (int) $r['active'] === 1
                                ? __('активна', 'leadrouter')
                                : __('вимкнена', 'leadrouter')
                        );
                    }

                    return $options;
                })
                ->set_help_text('Позначені групи показуються на панелі балансування завжди — окремим рядком над рештою і поза звичайними фільтрами: навіть якщо в групі немає партнерів, у партнерів нульові ліміти на сьогодні або групу вимкнено. Потрібно, щоб було видно, що саме йде в резерв.'),

        ))
        ->add_tab(__('Logs', 'leadrouter'), array(
/*
            Field::make('checkbox', 'leadrouter_log_enabled', __('Увімкнути логування', 'leadrouter'))
                ->set_option_value('yes')
                ->set_default_value('yes'),*/

        ))
        // ===== Листи білінгу — кожен тип в окремій вкладці =====
        ->add_tab(__('Emails: general', 'leadrouter'), array(
            Field::make('text', 'lr_billing_admin_email', __('Admin notification email', 'leadrouter'))
                ->set_help_text(__('Where to send admin emails (negative balance, etc.). If empty, the site admin email is used.', 'leadrouter')),
        ))
        // ===== Скарги партнерів на ліди =====
        ->add_tab(__('Complaints', 'leadrouter'), array(
            Field::make('text', 'lr_complaints_email', __('Complaints notification email', 'leadrouter'))
                ->set_help_text(__('Where to send lead-complaint emails. If empty, the billing admin email is used, then the site admin email.', 'leadrouter')),
            Field::make('complex', 'lr_complaint_topics', __('Complaint topics', 'leadrouter'))
                ->set_collapsed(false)
                ->setup_labels(array(
                    'plural_name'   => __('Topics', 'leadrouter'),
                    'singular_name' => __('Topic', 'leadrouter'),
                ))
                ->add_fields(array(
                    Field::make('text', 'topic', __('Topic', 'leadrouter'))->set_width(100),
                ))
                ->set_default_value(array_map(function ($t) {
                    return array('topic' => $t);
                }, LR_Complaints::SEED_TOPICS))
                ->set_help_text(__('Topics shown to partners in the complaint modal. "Other" is always available in addition to these. If the list is empty, only "Other" is shown.', 'leadrouter')),
        ))
        ->add_tab(__('Email: cabinet access', 'leadrouter'), array(
            Field::make('text', 'lr_email_account_access_subject', __('Subject', 'leadrouter'))
                ->set_help_text(__('Available placeholders: {login} {password} {cabinet_url} {partner_name} {site_name} {date}', 'leadrouter')),
            Field::make('rich_text', 'lr_email_account_access_body', __('Body', 'leadrouter'))
                ->set_help_text(__('Available placeholders: {login} {password} {cabinet_url} {partner_name} {site_name} {date}', 'leadrouter')),
        ))
        ->add_tab(__('Email: low balance', 'leadrouter'), array(
            Field::make('text', 'lr_email_low_balance_subject', __('Subject', 'leadrouter'))
                ->set_help_text($lr_email_ph_help),
            Field::make('rich_text', 'lr_email_low_balance_body', __('Body', 'leadrouter'))
                ->set_help_text($lr_email_ph_help),
        ))
        ->add_tab(__('Email: sending stopped', 'leadrouter'), array(
            Field::make('text', 'lr_email_stopped_subject', __('Subject', 'leadrouter'))
                ->set_help_text($lr_email_ph_help),
            Field::make('rich_text', 'lr_email_stopped_body', __('Body', 'leadrouter'))
                ->set_help_text($lr_email_ph_help),
        ))
        ->add_tab(__('Admin email: negative balance', 'leadrouter'), array(
            Field::make('text', 'lr_email_admin_negative_subject', __('Subject', 'leadrouter'))
                ->set_help_text($lr_email_ph_help),
            Field::make('rich_text', 'lr_email_admin_negative_body', __('Body', 'leadrouter'))
                ->set_help_text($lr_email_ph_help),
        ))
        // ===== Stripe-листи (поповнення) =====
        ->add_tab(__('Email: Stripe success', 'leadrouter'), array(
            Field::make('text', 'lr_email_stripe_success_subject', __('Subject', 'leadrouter'))
                ->set_help_text($lr_email_ph_help_stripe),
            Field::make('rich_text', 'lr_email_stripe_success_body', __('Body', 'leadrouter'))
                ->set_help_text($lr_email_ph_help_stripe),
        ))
        ->add_tab(__('Email: Stripe declined', 'leadrouter'), array(
            Field::make('text', 'lr_email_stripe_declined_subject', __('Subject', 'leadrouter'))
                ->set_help_text($lr_email_ph_help_stripe),
            Field::make('rich_text', 'lr_email_stripe_declined_body', __('Body', 'leadrouter'))
                ->set_help_text($lr_email_ph_help_stripe),
        ))
        ->add_tab(__('Email: Stripe 3DS required', 'leadrouter'), array(
            Field::make('text', 'lr_email_stripe_action_subject', __('Subject', 'leadrouter'))
                ->set_help_text($lr_email_ph_help_stripe),
            Field::make('rich_text', 'lr_email_stripe_action_body', __('Body', 'leadrouter'))
                ->set_help_text($lr_email_ph_help_stripe),
        ))
        ->add_tab(__('Admin email: Stripe charge failed', 'leadrouter'), array(
            Field::make('text', 'lr_email_stripe_admin_failed_subject', __('Subject', 'leadrouter'))
                ->set_help_text($lr_email_ph_help_stripe),
            Field::make('rich_text', 'lr_email_stripe_admin_failed_body', __('Body', 'leadrouter'))
                ->set_help_text($lr_email_ph_help_stripe),
        ))
        // ===== Stripe: ключі та налаштування =====
        ->add_tab(__('Stripe', 'leadrouter'), array(
            Field::make('text', 'lr_stripe_publishable_key', __('Publishable key', 'leadrouter'))
                ->set_help_text(__('Publishable key (pk_...). Safe to use on the frontend.', 'leadrouter')),
            Field::make('text', 'lr_stripe_secret_key', __('Secret key', 'leadrouter'))
                ->set_help_text(__('Secret key (sk_...). Entered manually, stored in the DB. Keep it private.', 'leadrouter')),
            Field::make('text', 'lr_stripe_webhook_secret', __('Webhook signing secret', 'leadrouter'))
                ->set_help_text(__('Webhook signing secret (whsec_...). Used to verify the Stripe-Signature header.', 'leadrouter')),
            Field::make('select', 'lr_stripe_currency', __('Currency', 'leadrouter'))
                ->add_options(array(
                    'usd' => 'USD',
                    'eur' => 'EUR',
                ))
                ->set_default_value('usd')
                ->set_help_text(__('Currency for Stripe operations.', 'leadrouter')),
            Field::make('checkbox', 'lr_stripe_test_mode', __('Test mode', 'leadrouter'))
                ->set_option_value('yes')
                ->set_help_text(__('Informational flag: indicates that Stripe test keys are in use.', 'leadrouter')),
        ));

    // ===== GROUP =====
    // Вкладку «Основні» злито у «Розподіл»: єдиним живим полем був список
    // партнерів групи — тепер він унизу вкладки «Розподіл».
    Container::make('post_meta', __('Налаштування групи', 'leadrouter'))
        ->where('post_type', '=', 'leadrouter_group')
        // ===== Розподіл: режим групи, N/L, коефіцієнт (фаза 0 — поля і синк) =====
        ->add_tab(__('Розподіл', 'leadrouter'), array(

            Field::make('select', 'lr_group_mode', __('Тип групи', 'leadrouter'))
                ->add_options(array(
                    'classic' => __('Класична', 'leadrouter'),
                    'shared'  => __('Shared × N', 'leadrouter'),
                ))
                ->set_default_value('classic')
                ->set_width(50)
                ->set_help_text('<strong>Класична</strong> — кожен лід продається один раз, точно як працювало завжди. <strong>Shared × N</strong> — кожен лід групи продається N різним партнерам одночасно (дешевші, неексклюзивні ліди). Зміна типу набуває чинності з наступної доби (EST).'),

            Field::make('text', 'lr_group_coef', __('Коефіцієнт групи', 'leadrouter'))
                ->set_attribute('type', 'number')
                ->set_attribute('step', '0.1')
                ->set_attribute('min', '0')
                ->set_default_value('1.0')
                ->set_width(50)
                ->set_help_text('Множник пріоритету групи у розподілі потоку між групами. <strong>1.0 — нічого не змінює.</strong> Більше 1.0 — група отримує більшу частку лідів протягом дня; при нестачі потоку добирає свою норму першою. Застосовується одразу, зміна пишеться в журнал.'),

            Field::make('text', 'lr_group_share_n', __('Копій на лід (N)', 'leadrouter'))
                ->set_attribute('type', 'number')
                ->set_attribute('min', '2')
                ->set_default_value('6')
                ->set_width(50)
                ->set_help_text('Скільки разів продається кожен лід цієї групи = скільком різним партнерам він розсилається. Разом з денним обсягом визначає місткість: N × L продажів на день. Діє з наступної доби (EST).')
                ->set_conditional_logic(array(
                    array('field' => 'lr_group_mode', 'value' => 'shared'),
                )),

            Field::make('text', 'lr_group_daily_volume', __('Денний обсяг (L)', 'leadrouter'))
                ->set_attribute('type', 'number')
                ->set_attribute('min', '0')
                ->set_default_value('0')
                ->set_width(50)
                ->set_help_text('Скільки лідів на день має отримати ця група (її вага у черзі між групами). Сума денних лімітів партнерів має дорівнювати N × L — стан звірки в блоці "План слотів" нижче. Діє з наступної доби (EST).')
                ->set_conditional_logic(array(
                    array('field' => 'lr_group_mode', 'value' => 'shared'),
                )),

            Field::make('checkbox', 'lr_group_overflow_on', __('М’яка квота (overflow)', 'leadrouter'))
                ->set_width(50)
                ->set_help_text('Після вичерпання денного обсягу L група <strong>продовжує приймати ліди</strong>, поки в її партнерів лишаються вільні денні ліміти (тверда межа — ємність партнерів). Overflow-ліди не беруть участі у WRR-балансі (eff не змінюється) і позначаються в лозі статусом <code>group_assigned_overflow</code>. Вимкнено — жорстка квота, як завжди. <strong>Діє негайно.</strong>')
                ->set_conditional_logic(array(
                    array('field' => 'lr_group_mode', 'value' => 'shared'),
                )),

            Field::make('text', 'lr_group_overflow_cap', __('Стеля overflow (лідів понад L)', 'leadrouter'))
                ->set_attribute('type', 'number')
                ->set_attribute('min', '0')
                ->set_default_value('0')
                ->set_width(50)
                ->set_help_text('Максимум додаткових лідів понад денний обсяг. <strong>0 або порожньо — без стелі</strong> (до вичерпання лімітів партнерів). Діє негайно.')
                ->set_conditional_logic(array(
                    array('field' => 'lr_group_mode', 'value' => 'shared'),
                    array('field' => 'lr_group_overflow_on', 'value' => true),
                )),

            // План слотів: звірка трьох умов + схема N колонок × L (фаза 3)
            Field::make('html', 'lr_group_slot_plan')
                ->set_html(function () {
                    $group_id = (int)get_the_ID();
                    if ($group_id <= 0 || !class_exists('LeadRouter_Slot_Planner')) {
                        return '';
                    }

                    // Беремо значення з меты — план показує те, що щойно задав менеджер
                    $n = (int)get_post_meta($group_id, '_lr_group_share_n', true);
                    $l = (int)get_post_meta($group_id, '_lr_group_daily_volume', true);
                    if ($n <= 0) {
                        $n = 6;
                    }

                    $partners = LeadRouter_Slot_Planner::partners_for_group($group_id);
                    if (empty($partners)) {
                        return '<p><em>' . esc_html__('У групі ще немає активних партнерів з денним лімітом на сьогодні.', 'leadrouter') . '</em></p>';
                    }

                    $plan = LeadRouter_Slot_Planner::plan($partners, $n, $l);

                    return '<h3 style="margin:12px 0 8px">' . esc_html__('План слотів (на сьогодні, EST)', 'leadrouter') . '</h3>'
                        . LeadRouter_Slot_Planner::render_html($plan);
                })
                ->set_conditional_logic(array(
                    array('field' => 'lr_group_mode', 'value' => 'shared'),
                )),

            // Склад групи: додати/вилучити партнера прямо тут (LR_Group_Partners).
            // Пише мету партнера _leadrouter_partner_group і перераховує ваги.
            Field::make('html', 'leadrouter_group_list_partner')
                ->set_html(function () {
                    $group_id = (int)get_the_ID();
                    if ($group_id <= 0) {
                        return '';
                    }

                    if (class_exists('LR_Group_Partners')) {
                        return LR_Group_Partners::render($group_id);
                    }

                    // запасний варіант — старий список тільки для читання

                    $posts = get_posts(array(
                        'post_type' => 'leadrouter_partner',
                        'posts_per_page' => -1,
                        'post_status' => 'publish',
                        'meta_query' => array(
                            array(
                                // ключ Carbon Fields з підкресленням — без нього
                                // список завжди був порожнім
                                'key' => '_leadrouter_partner_group',
                                'value' => $group_id,
                                'compare' => '=',
                                'type' => 'NUMERIC',
                            ),
                        ),
                        'orderby' => 'title',
                        'order' => 'ASC',
                    ));

                    if (!$posts) {
                        return '<em>' . esc_html__('Партнери відсутні', 'leadrouter') . '</em>';
                    }

                    $html = '<h4>' . esc_html__('Партнери у цій групі', 'leadrouter') . '</h4>';
                    foreach ($posts as $post) {
                        $html .= '<div><a href="' . esc_url(get_edit_post_link($post->ID)) . '">' . esc_html($post->post_title) . '</a></div>';
                    }
                    return $html;
                })
                ->set_width(100),
        ));

    // ===== PARTNER =====
    $lr_partner_container = Container::make('post_meta', __('Налаштування партнера', 'leadrouter'))
        ->where('post_type', '=', 'leadrouter_partner')
        ->add_tab(__('Основні', 'leadrouter'), array(

            Field::make('select', 'leadrouter_partner_group', __('Група', 'leadrouter'))
                ->add_options(function () {
                    $posts = get_posts(array(
                        'post_type' => 'leadrouter_group',
                        'posts_per_page' => -1,
                        'post_status' => 'publish',
                        'orderby' => 'title',
                        'order' => 'ASC',
                    ));
                    $options = [];
                    foreach ($posts as $post) {
                        $options[$post->ID] = $post->post_title;
                    }
                    return $options;
                })
                ->set_width(50)
                ->set_required(true),

            Field::make('radio', 'leadrouter_partner_active', __('Активний?', 'leadrouter'))
                ->set_options(array(
                    '1' => __('Так', 'leadrouter'),
                    '0' => __('Ні', 'leadrouter'),
                ))
                ->set_default_value('1')
                ->set_width(50),

            Field::make('separator', 'leadrouter_partner_sep0', ''),

            // ===== Пріоритет у черзі групи і кластер власника (фаза 0) =====
            Field::make('text', 'lr_partner_coef', __('Коефіцієнт партнера', 'leadrouter'))
                ->set_attribute('type', 'number')
                ->set_attribute('step', '0.1')
                ->set_attribute('min', '0')
                ->set_default_value('1.0')
                ->set_width(50)
                ->set_help_text('Пріоритет партнера у черзі всередині його групи. <strong>1.0 — нейтрально</strong> (частка визначається лише денним лімітом). Більше 1.0 — партнер отримує ліди раніше протягом дня, а при нестачі потоку добирає свій ліміт першим. Не збільшує денний ліміт — лише порядок. Застосовується одразу, зміна пишеться в журнал.'),

            Field::make('text', 'lr_partner_owner', __('Власник', 'leadrouter'))
                ->set_width(50)
                ->set_attribute('placeholder', 'owner_petrov')
                ->set_help_text('Ідентифікатор власника компанії (наприклад, owner_petrov). Партнери з ОДНАКОВИМ значенням ніколи не отримують той самий лід — на один лід максимум одна компанія власника. Порожнє поле = обмеження немає. Діє і в класичних, і в shared-групах. Увага: сумарний денний ліміт компаній одного власника в shared-групі не може перевищувати денний обсяг групи L.'),

            Field::make('separator', 'leadrouter_partner_sep_owner', ''),

            Field::make('radio', 'leadrouter_partner_allow_alaska', __('Дозволяти Alaska?', 'leadrouter'))
                ->set_options(array(
                    '1' => __('Так', 'leadrouter'),
                    '0' => __('Ні', 'leadrouter'),
                ))
                ->set_default_value('0')
                ->set_width(50),

            Field::make('radio', 'leadrouter_partner_allow_hawaii', __('Дозволяти Hawaii?', 'leadrouter'))
                ->set_options(array(
                    '1' => __('Так', 'leadrouter'),
                    '0' => __('Ні', 'leadrouter'),
                ))
                ->set_default_value('0')
                ->set_width(50),

            Field::make('separator', 'leadrouter_partner_sep1', ''),

            // Попередження до всього блоку годин: порожні початок/кінець = закрито
            Field::make('html', 'leadrouter_partner_hours_note')
                ->set_html(
                    '<p style="margin:0;padding:8px 12px;border-left:4px solid #d63638;background:#fcf0f1;">'
                    . '<strong>' . esc_html__('Якщо початок або кінець не заповнені — цього дня партнер лідів НЕ отримує.', 'leadrouter') . '</strong>'
                    . '</p>'
                ),

            // Monday
            Field::make('html', 'leadrouter_partner_mon_label')->set_html('<h3>' . esc_html__('Monday', 'leadrouter') . ':</h3>')->set_width(10),
            Field::make('text', 'leadrouter_partner_mon_limit', __('Ліміт', 'leadrouter'))->set_attribute('type', 'number')->set_width(20),
            Field::make('time', 'leadrouter_partner_mon_start', __('Початок', 'leadrouter'))->set_width(20),
            Field::make('time', 'leadrouter_partner_mon_end', __('Завершення', 'leadrouter'))->set_width(20),
            Field::make('html', 'leadrouter_partner_mon_label_end')->set_html('')->set_width(30),

            // Tuesday
            Field::make('html', 'leadrouter_partner_tue_label')->set_html('<h3>' . esc_html__('Tuesday', 'leadrouter') . ':</h3>')->set_width(10),
            Field::make('text', 'leadrouter_partner_tue_limit', __('Ліміт', 'leadrouter'))->set_attribute('type', 'number')->set_width(20),
            Field::make('time', 'leadrouter_partner_tue_start', __('Початок', 'leadrouter'))->set_width(20),
            Field::make('time', 'leadrouter_partner_tue_end', __('Завершення', 'leadrouter'))->set_width(20),
            Field::make('html', 'leadrouter_partner_tue_label_end')->set_html('')->set_width(30),

            // Wednesday
            Field::make('html', 'leadrouter_partner_wed_label')->set_html('<h3>' . esc_html__('Wednesday', 'leadrouter') . ':</h3>')->set_width(10),
            Field::make('text', 'leadrouter_partner_wed_limit', __('Ліміт', 'leadrouter'))->set_attribute('type', 'number')->set_width(20),
            Field::make('time', 'leadrouter_partner_wed_start', __('Початок', 'leadrouter'))->set_width(20),
            Field::make('time', 'leadrouter_partner_wed_end', __('Завершення', 'leadrouter'))->set_width(20),
            Field::make('html', 'leadrouter_partner_wed_label_end')->set_html('')->set_width(30),

            // Thursday
            Field::make('html', 'leadrouter_partner_thu_label')->set_html('<h3>' . esc_html__('Thursday', 'leadrouter') . ':</h3>')->set_width(10),
            Field::make('text', 'leadrouter_partner_thu_limit', __('Ліміт', 'leadrouter'))->set_attribute('type', 'number')->set_width(20),
            Field::make('time', 'leadrouter_partner_thu_start', __('Початок', 'leadrouter'))->set_width(20),
            Field::make('time', 'leadrouter_partner_thu_end', __('Завершення', 'leadrouter'))->set_width(20),
            Field::make('html', 'leadrouter_partner_thu_label_end')->set_html('')->set_width(30),

            // Friday
            Field::make('html', 'leadrouter_partner_fri_label')->set_html('<h3>' . esc_html__('Friday', 'leadrouter') . ':</h3>')->set_width(10),
            Field::make('text', 'leadrouter_partner_fri_limit', __('Ліміт', 'leadrouter'))->set_attribute('type', 'number')->set_width(20),
            Field::make('time', 'leadrouter_partner_fri_start', __('Початок', 'leadrouter'))->set_width(20),
            Field::make('time', 'leadrouter_partner_fri_end', __('Завершення', 'leadrouter'))->set_width(20),
            Field::make('html', 'leadrouter_partner_fri_label_end')->set_html('')->set_width(30),

            // Saturday
            Field::make('html', 'leadrouter_partner_sat_label')->set_html('<h3>' . esc_html__('Saturday', 'leadrouter') . ':</h3>')->set_width(10),
            Field::make('text', 'leadrouter_partner_sat_limit', __('Ліміт', 'leadrouter'))->set_attribute('type', 'number')->set_width(20),
            Field::make('time', 'leadrouter_partner_sat_start', __('Початок', 'leadrouter'))->set_width(20),
            Field::make('time', 'leadrouter_partner_sat_end', __('Завершення', 'leadrouter'))->set_width(20),
            Field::make('html', 'leadrouter_partner_sat_label_end')->set_html('')->set_width(30),

            // Sunday
            Field::make('html', 'leadrouter_partner_sun_label')->set_html('<h3>' . esc_html__('Sunday', 'leadrouter') . ':</h3>')->set_width(10),
            Field::make('text', 'leadrouter_partner_sun_limit', __('Ліміт', 'leadrouter'))->set_attribute('type', 'number')->set_width(20),
            Field::make('time', 'leadrouter_partner_sun_start', __('Початок', 'leadrouter'))->set_width(20),
            Field::make('time', 'leadrouter_partner_sun_end', __('Завершення', 'leadrouter'))->set_width(20),
            Field::make('html', 'leadrouter_partner_sun_label_end')->set_html('')->set_width(30),
        ));

    // ===== Білінг (вбудована сторінка білінгу партнера) =====
    $lr_partner_container
        ->add_tab(__('Billing', 'leadrouter'), array(
            Field::make('html', 'leadrouter_partner_billing_main')
                ->set_html(function () {
                    if (!class_exists('LR_Partner_Billing_Page')) {
                        return '';
                    }
                    $pid = get_the_ID();
                    return $pid ? LR_Partner_Billing_Page::tab_billing_main_html((int)$pid) : '';
                }),
        ))
        ->add_tab(__('Billing history', 'leadrouter'), array(
            Field::make('html', 'leadrouter_partner_billing_history')
                ->set_html(function () {
                    if (!class_exists('LR_Partner_Billing_Page')) {
                        return '';
                    }
                    $pid = get_the_ID();
                    return $pid ? LR_Partner_Billing_Page::tab_billing_history_html((int)$pid) : '';
                }),
        ));

    $lr_partner_container
        ->add_tab(__('Тех інфо', 'leadrouter'), array(

            Field::make('select', 'leadrouter_partner_type', __('Тип партнера', 'leadrouter'))
                ->set_options(array(
                    'standard' => __('Standard', 'leadrouter'),
                    'custom' => __('API', 'leadrouter'),
                    'email' => __('Email', 'leadrouter'),
                ))
                ->set_default_value('custom')
                ->set_required(true),


            Field::make('text', 'leadrouter_partner_endpoint', __('Endpoint URL', 'leadrouter'))
                ->set_width(50)
                ->set_help_text('Повний URL партнерського API')
                ->set_default_value('https://api.batscrm.com/leads')
                ->set_conditional_logic([
                    [
                        'field' => 'leadrouter_partner_type',
                        'value' => 'custom',
                        'compare' => '=',
                    ],
                ]),

            Field::make('select', 'leadrouter_partner_auth_variant', __('Auth variant', 'leadrouter'))
                ->set_width(25)
                ->add_options([
                    'header' => 'Header (X-API-Key: ...)',
                    'payload' => 'Payload ("apikey" у тілі)',
                    'payload_authkey' => 'Payload ("AuthKey" у тілі)',
                    'payload_xapikey' => 'Payload ("XAPIKEY" у тілі)',
                    'query' => 'Query (?apikey=...)',
                    'none' => 'None',
                ])
                ->set_default_value('payload_authkey')
                ->set_conditional_logic([
                    [
                        'field' => 'leadrouter_partner_type',
                        'value' => 'custom',
                        'compare' => '=',
                    ],
                ]),

            Field::make('text', 'leadrouter_partner_api_key', __('API Key', 'leadrouter'))
                ->set_width(25)
                ->set_conditional_logic([
                    [
                        'field' => 'leadrouter_partner_type',
                        'value' => 'custom',
                        'compare' => '=',
                    ],
                ]),

            Field::make('text', 'leadrouter_partner_api_key_header', __('API Key Header', 'leadrouter'))
                ->set_width(25)
                ->set_default_value('X-API-Key')
                ->set_conditional_logic([
                    [
                        'field' => 'leadrouter_partner_type',
                        'value' => 'custom',
                        'compare' => '=',
                    ],
                ]),

            Field::make('text', 'leadrouter_partner_email', __('Email', 'leadrouter'))
                ->set_width(25),

            Field::make('checkbox', 'leadrouter_partner_require_ok_json', __('Require {"ok": true} in 2xx', 'leadrouter'))
                ->set_option_value('yes')
                ->set_width(25)
                ->set_conditional_logic([
                    [
                        'field' => 'leadrouter_partner_type',
                        'value' => 'custom',
                        'compare' => '=',
                    ],
                ]),

            Field::make('complex', 'leadrouter_partner_email_settings', __('Налаштування email', 'leadrouter'))
                ->set_conditional_logic([
                    [
                        'field' => 'leadrouter_partner_type',
                        'value' => 'email',
                        'compare' => '=',
                    ],
                ])
                ->set_collapsed(false)
                ->set_max(1)
                ->setup_labels([
                    'plural_name' => 'Налаштування листа',
                    'singular_name' => 'Налаштування листа',
                ]) ->add_fields([
                    Field::make('text', 'email_title', 'Заголовок')
                        ->set_width(100)
                        ->set_help_text('
        Використовуйте плейсхолдери у фігурних дужках.<br/>
        Напр.: <code>{first_name}</code>, <code>{last_name}</code>, <code>{phone}</code>, <code>{email}</code>,<br/>
        <code>{origin_city}</code>, <code>{origin_state}</code>, <code>{destination_city}</code>, <code>{destination_state}</code>,<br/>
        <code>{vehicle_year}</code>, <code>{vehicle_brand}</code>, <code>{vehicle_model}</code>,<br/>
        а також вкладені: <code>{Vehicles.0.vehicle_model_year}</code>, <code>{Vehicles.0.vehicle_make}</code> і т.п.<br/><br/>
        Доступні inline-трансформації: <code>{phone|digits}</code>, <code>{ship_date|date_mdy_dash}</code>, <code>{first_name|title}</code>.<br/>
        Старий формат <code>{field}</code> повністю підтримується.
    '),
                    Field::make('textarea', 'email_text', 'Текст')
                        ->set_width(100)
                        ->set_rows(15)
                        ->set_classes('cf-code-editor')
                        ->set_help_text('
        Тіло листа. Підтримуються ті ж плейсхолдери, що й у заголовку:<br/>
        <code>{first_name}</code>, <code>{last_name}</code>, <code>{email}</code>, <code>{phone}</code>,<br/>
        <code>{origin_city}</code>, <code>{origin_state}</code>, <code>{destination_city}</code>, <code>{destination_state}</code>,<br/>
        <code>{vehicle_year}</code>, <code>{vehicle_brand}</code>, <code>{vehicle_model}</code>, <code>{vehicle_condition}</code>,<br/>
        <code>{Vehicles.0.vehicle_model_year}</code>, <code>{Vehicles.0.vehicle_make}</code>, <code>{Vehicles.0.vehicle_model}</code>, <code>{Vehicles.0.vehicle_inop}</code>.<br/>
        Також доступні inline-трансформації: <code>{phone|digits}</code>, <code>{ship_date|date_mdy_dash}</code>, <code>{first_name|upper}</code>, <code>{transport_type|map_transport_type}</code>.<br/>
        Старий формат <code>{field}</code> повністю підтримується.<br/><br/>
        Наприклад:<br/>
        <code>
        New lead from {first_name} {last_name}<br/>
        Phone: {phone}<br/>
        From: {origin_city}, {origin_state}<br/>
        To: {destination_city}, {destination_state}<br/>
        Vehicle: {vehicle_year} {vehicle_brand} {vehicle_model}
        </code>
    '),
                ]),

            Field::make('complex', 'leadrouter_partner_map', __('Basic Map — Наш ключ → Їх ключ', 'leadrouter'))
                ->set_conditional_logic([
                    [
                        'field' => 'leadrouter_partner_type',
                        'value' => 'custom',
                        'compare' => '=',
                    ],
                ])
                ->set_collapsed(false)
                ->setup_labels([
                    'plural_name' => 'Відповідності',
                    'singular_name' => 'Відповідність',
                ])
                ->add_fields([
                    Field::make('text', 'our_key', 'Наш ключ (our_key)')->set_width(30)
                        ->set_help_text('напр. first_name, last_name, email, phone, ship_date, Vehicles.0.vehicle_model_year, origin_city, ...'),
                    Field::make('text', 'their_key', 'Їх ключ (their_key)')->set_width(20)
                        ->set_help_text('напр. fn, ln, em, ph, ps, ty, yr, ma, mo, rc, oc, os, oz, dc, ds, dz, AuthKey'),
                    Field::make('select', 'transform', 'Трансформація')->set_width(25)->add_options([
                        'none'           => 'none',
                        'lower'          => 'lower',
                        'upper'          => 'upper',
                        'title'          => 'title',
                        'digits'         => 'digits (телефон/zip)',
                        'int'            => 'int',
                        'float2'         => 'float(2)',
                        'date_Ymd'       => 'date:Y-m-d (ps)',
                        'date_Ymd_slash' => 'date:Y/m/d (ps)',         // прод 03.08: Door to Door
                        'date_mdy'       => 'date:m/d/Y (ps)',
                        'date_mdy_dash'  => 'date → MM-DD-YYYY',       // ⬅️ нове
                        'split_name_fn'  => 'split_name:fn (з name)',
                        'split_name_ln'  => 'split_name:ln (з name)',
                        'map_running'    => 'map:Running→operable, NonRunning→inoperable',
                        'inop_binary'    => 'condition→vehicle_inop (Running→0, інше→1)',
                        'inop_binary_reverse' => 'condition→vehicle_inop (Running→1, інше→0)', // ⬅️ нове
                        'inop_binary_to_bool' => 'condition→vehicle_inop (Running→false, інше→true)', // ⬅️ нове
                        'inop_binary_to_bool_reverse' => 'condition→vehicle_inop (Running→true, інше→false)', // ⬅️ нове
                        'phone_us_dashed'=> 'phone → 111-111-1111',    // ⬅️ нове
                        'map_transport_type' => 'transport_type: 1 → Open, 0 → Closed', // ⬅️ нове
                        'map_transport_type_open_enclosed' => 'transport_type: 1 → Open, 0 → Enclosed', // прод 03.08
                        'map_transport_type_reverse' => 'transport_type: 0 → 1, 1 → 0' // ⬅️ нове
                    ])->set_default_value('none'),
                    Field::make('text', 'default_value', 'Default value')->set_width(25)
                        ->set_help_text('Підставляється, якщо значення порожнє'),
                ])
                ->set_default_value(lr_partner_default_map())
                ->set_help_text('
        <b>Наш ключ</b> — з нашого стандартного payload (BATS).<br/>
        <b>Їх ключ</b> — якому партнерському ключу він відповідає (fn, ln, em, ph, ps...).<br/>
        Оберіть пресет для автозаповнення мапінгу та технічних полів партнера.<br/><br/>
            <label for="lr-map-preset-select" style="display:block; margin-bottom:6px;"><b>Пресет інтеграції:</b></label>
            <select id="lr-map-preset-select" class="js-lr-map-preset-select" style="min-width:220px; margin-right:8px;">
                <option value="bats">BATS</option>
                <option value="msgplane">MsgPlane</option>
                <option value="imove360">iMove360</option>
                <option value="carlink">CarlinkCRM</option>
                <option value="capscrm">CapsCRM</option>
                <option value="onespacecrm">OnespaceCRM</option>
                <option value="mile_autotransport">Mile-Autotransport</option>
                <option value="door_to_door">Door to Door Transport</option>
            </select>
            <button type="button" class="button button-primary js-lr-apply-map-preset">Застосувати пресет</button>
    ')

            // Додаткові поля
            /*
        Field::make('textarea', 'leadrouter_partner_template_json', __('JSON Template', 'leadrouter'))
            ->set_rows(10)->set_conditional_logic(array(
                array(
                    'field' => 'leadrouter_partner_type',
                    'value' => 'standard',
                    'compare' => '=',
                ),
            )),

        Field::make('textarea', 'leadrouter_partner_extra_headers', __('Extra Headers (JSON)', 'leadrouter'))
            ->set_rows(3)->set_conditional_logic(array(
                array(
                    'field' => 'leadrouter_partner_type',
                    'value' => 'standard',
                    'compare' => '=',
                ),
            )),

        Field::make('textarea', 'leadrouter_partner_query_params', __('Query Params (JSON)', 'leadrouter'))
            ->set_rows(3)->set_conditional_logic(array(
                array(
                    'field' => 'leadrouter_partner_type',
                    'value' => 'standard',
                    'compare' => '=',
                ),
            )),

        Field::make('textarea', 'leadrouter_partner_constants', __('Constants (JSON)', 'leadrouter'))
            ->set_rows(3)->set_conditional_logic(array(
                array(
                    'field' => 'leadrouter_partner_type',
                    'value' => 'standard',
                    'compare' => '=',
                ),
            )),*/

        ));
}
// add_action('carbon_fields_register_fields', 'leadrouter_create_custom_fields');

// Десь у your-plugin.php (один раз усьому плагіну):
// add_action('after_setup_theme', function() {
//     \Carbon_Fields\Carbon_Fields::boot();
// });


function lr_partner_default_map(): array {
    return [
        ['our_key' => 'first_name',                  'their_key' => 'first_name',                  'transform' => 'none',            'default_value' => ''],
        ['our_key' => 'last_name',                   'their_key' => 'last_name',                   'transform' => 'none',            'default_value' => ''],
        ['our_key' => 'email',                       'their_key' => 'email',                       'transform' => 'lower',           'default_value' => ''],
        ['our_key' => 'phone',                       'their_key' => 'phone',                       'transform' => 'phone_us_dashed', 'default_value' => ''],
        ['our_key' => 'ship_date',                   'their_key' => 'ship_date',                   'transform' => 'date_mdy',        'default_value' => ''],
        ['our_key' => 'comment_from_shipper',        'their_key' => 'comment_from_shipper',        'transform' => 'none',            'default_value' => ''],
        ['our_key' => 'transport_type',              'their_key' => 'transport_type',              'transform' => 'int',             'default_value' => '1'],
        ['our_key' => 'Vehicles.0.vehicle_type',     'their_key' => 'Vehicles.0.vehicle_type',     'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_model_year','their_key' => 'Vehicles.0.vehicle_model_year','transform' => 'int',           'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_make',     'their_key' => 'Vehicles.0.vehicle_make',     'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_model',    'their_key' => 'Vehicles.0.vehicle_model',    'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_inop',     'their_key' => 'Vehicles.0.vehicle_inop',     'transform' => 'inop_binary',     'default_value' => '0'],
        ['our_key' => 'origin_city',                 'their_key' => 'origin_city',                 'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'origin_state',                'their_key' => 'origin_state',                'transform' => 'upper',           'default_value' => ''],
        ['our_key' => 'origin_postal_code',          'their_key' => 'origin_postal_code',          'transform' => 'digits',          'default_value' => ''],
        ['our_key' => 'destination_city',            'their_key' => 'destination_city',            'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'destination_state',           'their_key' => 'destination_state',           'transform' => 'upper',           'default_value' => ''],
        ['our_key' => 'destination_postal_code',     'their_key' => 'destination_postal_code',     'transform' => 'digits',          'default_value' => ''],
        ['our_key' => 'destination_country',         'their_key' => 'destination_country',         'transform' => 'none',            'default_value' => 'USA'],
        ['our_key' => 'origin_country',              'their_key' => 'origin_country',              'transform' => 'none',            'default_value' => 'USA'],
    ];
}

function lr_partner_msgplane_default_map(): array {
    return [
        ['our_key' => 'first_name',                  'their_key' => 'first_name',                'transform' => 'none',        'default_value' => ''],
        ['our_key' => 'last_name',                   'their_key' => 'last_name',                 'transform' => 'none',        'default_value' => ''],
        ['our_key' => 'email',                       'their_key' => 'email',                     'transform' => 'lower',       'default_value' => ''],
        ['our_key' => 'phone',                       'their_key' => 'phone',                     'transform' => 'digits',      'default_value' => ''],
        ['our_key' => 'ship_date',                   'their_key' => 'ship_date',                 'transform' => 'date_mdy',    'default_value' => ''],
        ['our_key' => 'comment_from_shipper',        'their_key' => 'comment_from_shipper',      'transform' => 'none',        'default_value' => ''],
        ['our_key' => 'transport_type',              'their_key' => 'transport_type',            'transform' => 'int',         'default_value' => '1'],

        ['our_key' => 'Vehicles.0.vehicle_type',      'their_key' => 'Vehicles.0.vehicle_type',      'transform' => 'title',       'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_model_year','their_key' => 'Vehicles.0.vehicle_model_year','transform' => 'int',         'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_make',      'their_key' => 'Vehicles.0.vehicle_make',      'transform' => 'title',       'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_model',     'their_key' => 'Vehicles.0.vehicle_model',     'transform' => 'title',       'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_inop',      'their_key' => 'Vehicles.0.vehicle_inop',      'transform' => 'inop_binary', 'default_value' => '0'],

        ['our_key' => 'origin_city',                 'their_key' => 'origin_city',               'transform' => 'title',       'default_value' => ''],
        ['our_key' => 'origin_state',                'their_key' => 'origin_state',              'transform' => 'upper',       'default_value' => ''],
        ['our_key' => 'origin_postal_code',          'their_key' => 'origin_postal_code',        'transform' => 'digits',      'default_value' => ''],

        ['our_key' => 'destination_city',            'their_key' => 'destination_city',          'transform' => 'title',       'default_value' => ''],
        ['our_key' => 'destination_state',           'their_key' => 'destination_state',         'transform' => 'upper',       'default_value' => ''],
        ['our_key' => 'destination_postal_code',     'their_key' => 'destination_postal_code',   'transform' => 'digits',      'default_value' => ''],

        ['our_key' => 'origin_country',              'their_key' => 'origin_country',            'transform' => 'none',        'default_value' => 'USA'],
        ['our_key' => 'destination_country',         'their_key' => 'destination_country',       'transform' => 'none',        'default_value' => 'USA'],
    ];
}

function lr_partner_imove360_default_map(): array {
    return [
        ['our_key' => 'first_name',                  'their_key' => 'first_name',           'transform' => 'title',               'default_value' => ''],
        ['our_key' => 'last_name',                   'their_key' => 'last_name',            'transform' => 'title',               'default_value' => ''],
        ['our_key' => 'email',                       'their_key' => 'email',                'transform' => 'lower',               'default_value' => ''],
        ['our_key' => 'phone',                       'their_key' => 'phone',                'transform' => 'phone_us_dashed',     'default_value' => ''],
        ['our_key' => 'ship_date',                   'their_key' => 'estimated_ship_date',  'transform' => 'date_mdy',            'default_value' => ''],
        ['our_key' => 'comment_from_shipper',        'their_key' => 'notes_from_customer',  'transform' => 'none',                'default_value' => ''],
        ['our_key' => 'transport_type',              'their_key' => 'ship_via',             'transform' => 'map_transport_type',  'default_value' => 'Open'],

        ['our_key' => 'Vehicles.0.vehicle_model_year','their_key' => 'Vehicles.0.year',       'transform' => 'int',                 'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_make',      'their_key' => 'Vehicles.0.make',       'transform' => 'title',               'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_model',     'their_key' => 'Vehicles.0.model',      'transform' => 'title',               'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_inop',      'their_key' => 'Vehicles.0.is_operable','transform' => 'inop_binary_reverse', 'default_value' => '1'],

        ['our_key' => 'origin_city',                 'their_key' => 'pickup_city',          'transform' => 'upper',               'default_value' => ''],
        ['our_key' => 'origin_state',                'their_key' => 'pickup_state',         'transform' => 'upper',               'default_value' => ''],
        ['our_key' => 'origin_postal_code',          'their_key' => 'pickup_zip',           'transform' => 'digits',              'default_value' => ''],
        ['our_key' => 'destination_city',            'their_key' => 'delivery_city',        'transform' => 'title',               'default_value' => ''],
        ['our_key' => 'destination_state',           'their_key' => 'delivery_state',       'transform' => 'upper',               'default_value' => ''],
        ['our_key' => 'destination_postal_code',     'their_key' => 'delivery_zip',         'transform' => 'digits',              'default_value' => ''],

        ['our_key' => 'webhook_token',               'their_key' => 'webhook_token',        'transform' => 'none',                'default_value' => 'Your token'],
        ['our_key' => 'broker_id',                   'their_key' => 'broker_id',            'transform' => 'none',                'default_value' => '123456'],
        ['our_key' => 'text_consent',                'their_key' => 'text_consent',         'transform' => 'none',                'default_value' => 'Yes'],
        ['our_key' => 'source',                      'their_key' => 'source',               'transform' => 'none',                'default_value' => 'highpriorityleads'],
        ['our_key' => 'company_name',                'their_key' => 'company_name',         'transform' => 'none',                'default_value' => '-'],
    ];
}

function lr_partner_capscrm_default_map(): array {
    return [
        ['our_key' => 'full_name',                   'their_key' => 'customer_name',         'transform' => 'none',               'default_value' => ''],
        ['our_key' => 'email',                       'their_key' => 'customer_email',        'transform' => 'lower',              'default_value' => ''],
        ['our_key' => 'phone',                       'their_key' => 'customer_phone',        'transform' => 'digits',             'default_value' => ''],
        ['our_key' => 'ship_date',                   'their_key' => 'ship_date',             'transform' => 'date_Ymd',           'default_value' => ''],
        ['our_key' => 'transport_type',              'their_key' => 'transport_type',        'transform' => 'map_transport_type', 'default_value' => 'Open'],
        ['our_key' => 'Vehicles.0.vehicle_type',     'their_key' => 'type',                  'transform' => 'title',              'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_model_year','their_key' => 'model_year',           'transform' => 'int',                'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_make',     'their_key' => 'make',                  'transform' => 'title',              'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_model',    'their_key' => 'model',                 'transform' => 'title',              'default_value' => ''],
        ['our_key' => 'origin_city',                 'their_key' => 'origin_city',           'transform' => 'title',              'default_value' => ''],
        ['our_key' => 'origin_state',                'their_key' => 'origin_state',          'transform' => 'upper',              'default_value' => ''],
        ['our_key' => 'origin_postal_code',          'their_key' => 'origin_postal_code',    'transform' => 'digits',             'default_value' => ''],
        ['our_key' => 'destination_city',            'their_key' => 'destination_city',      'transform' => 'title',              'default_value' => ''],
        ['our_key' => 'destination_state',           'their_key' => 'destination_state',     'transform' => 'upper',              'default_value' => ''],
        ['our_key' => 'destination_postal_code',     'their_key' => 'destination_postal_code','transform' => 'digits',             'default_value' => ''],
        ['our_key' => 'source',                      'their_key' => 'source',                'transform' => 'none',               'default_value' => 'highpriorityleads'],
    ];
}

function lr_partner_onespacecrm_default_map(): array {
    return [
        ['our_key' => 'first_name',                  'their_key' => 'first_name',                  'transform' => 'none',            'default_value' => ''],
        ['our_key' => 'last_name',                   'their_key' => 'last_name',                   'transform' => 'none',            'default_value' => ''],
        ['our_key' => 'email',                       'their_key' => 'email',                       'transform' => 'lower',           'default_value' => ''],
        ['our_key' => 'phone',                       'their_key' => 'phone',                       'transform' => 'phone_us_dashed', 'default_value' => ''],
        ['our_key' => 'ship_date',                   'their_key' => 'ship_date',                   'transform' => 'date_mdy',        'default_value' => ''],
        ['our_key' => 'comment_from_shipper',        'their_key' => 'comment_from_shipper',        'transform' => 'none',            'default_value' => ''],
        ['our_key' => 'transport_type',              'their_key' => 'transport_type',              'transform' => 'int',             'default_value' => '1'],
        ['our_key' => 'Vehicles.0.vehicle_type',     'their_key' => 'Vehicles.0.vehicle_type',     'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_model_year','their_key' => 'Vehicles.0.vehicle_model_year','transform' => 'int',           'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_make',     'their_key' => 'Vehicles.0.vehicle_make',     'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_model',    'their_key' => 'Vehicles.0.vehicle_model',    'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_inop',     'their_key' => 'Vehicles.0.vehicle_inop',     'transform' => 'inop_binary',     'default_value' => '0'],
        ['our_key' => 'origin_city',                 'their_key' => 'origin_city',                 'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'origin_state',                'their_key' => 'origin_state',                'transform' => 'upper',           'default_value' => ''],
        ['our_key' => 'origin_postal_code',          'their_key' => 'origin_postal_code',          'transform' => 'digits',          'default_value' => ''],
        ['our_key' => 'destination_city',            'their_key' => 'destination_city',            'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'destination_state',           'their_key' => 'destination_state',           'transform' => 'upper',           'default_value' => ''],
        ['our_key' => 'destination_postal_code',     'their_key' => 'destination_postal_code',     'transform' => 'digits',          'default_value' => ''],
        ['our_key' => 'origin_country',              'their_key' => 'origin_country',              'transform' => 'none',            'default_value' => 'USA'],
        ['our_key' => 'destination_country',         'their_key' => 'destination_country',         'transform' => 'none',            'default_value' => 'USA'],
    ];
}

function lr_partner_mile_autotransport_default_map(): array {
    return [
        ['our_key' => 'first_name',                  'their_key' => 'first_name',                  'transform' => 'none',            'default_value' => ''],
        ['our_key' => 'last_name',                   'their_key' => 'last_name',                   'transform' => 'none',            'default_value' => ''],
        ['our_key' => 'email',                       'their_key' => 'email',                       'transform' => 'lower',           'default_value' => ''],
        ['our_key' => 'phone',                       'their_key' => 'phone',                       'transform' => 'phone_us_dashed', 'default_value' => ''],
        ['our_key' => 'ship_date',                   'their_key' => 'ship_date',                   'transform' => 'date_mdy',        'default_value' => ''],
        ['our_key' => 'comment_from_shipper',        'their_key' => 'comment_from_shipper',        'transform' => 'none',            'default_value' => ''],
        ['our_key' => 'transport_type',              'their_key' => 'transport_type',              'transform' => 'int',             'default_value' => '1'],
        ['our_key' => 'Vehicles.0.vehicle_type',     'their_key' => 'ty',                          'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_model_year','their_key' => 'Vehicles.0.vehicle_model_year','transform' => 'int',           'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_make',     'their_key' => 'Vehicles.0.vehicle_make',     'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_model',    'their_key' => 'Vehicles.0.vehicle_model',    'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_inop',     'their_key' => 'Vehicles.0.vehicle_inop',     'transform' => 'inop_binary',     'default_value' => '0'],
        ['our_key' => 'origin_city',                 'their_key' => 'origin_city',                 'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'origin_state',                'their_key' => 'origin_state',                'transform' => 'upper',           'default_value' => ''],
        ['our_key' => 'origin_postal_code',          'their_key' => 'origin_postal_code',          'transform' => 'digits',          'default_value' => ''],
        ['our_key' => 'destination_city',            'their_key' => 'destination_city',            'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'destination_state',           'their_key' => 'destination_state',           'transform' => 'upper',           'default_value' => ''],
        ['our_key' => 'destination_postal_code',     'their_key' => 'destination_postal_code',     'transform' => 'digits',          'default_value' => ''],
        ['our_key' => 'origin_country',              'their_key' => 'origin_country',              'transform' => 'none',            'default_value' => 'USA'],
        ['our_key' => 'destination_country',         'their_key' => 'destination_country',         'transform' => 'none',            'default_value' => 'USA'],
    ];
}

function lr_partner_carlink_default_map(): array {
    return [
        // Auth / provider key in body
        ['our_key' => 'AuthKey',                    'their_key' => 'lpKey',               'transform' => 'none',      'default_value' => 'Your lead provider key'],

        // Customer
        ['our_key' => 'first_name',                 'their_key' => 'firstName',           'transform' => 'title',     'default_value' => ''],
        ['our_key' => 'last_name',                  'their_key' => 'lastName',            'transform' => 'title',     'default_value' => ''],
        ['our_key' => 'email',                      'their_key' => 'email',               'transform' => 'lower',     'default_value' => ''],
        ['our_key' => 'phone',                      'their_key' => 'phone',               'transform' => 'digits',    'default_value' => ''],
        // ['our_key' => 'phone_other',                'their_key' => 'PhoneOther',          'transform' => 'digits',    'default_value' => ''],
        // ['our_key' => 'billing_address',            'their_key' => 'BillingAddress',      'transform' => 'none',      'default_value' => ''],

        // Origin
        ['our_key' => 'origin_city',                'their_key' => 'originCity',          'transform' => 'title',     'default_value' => ''],
        ['our_key' => 'origin_state',               'their_key' => 'originState',         'transform' => 'upper',     'default_value' => ''],
        ['our_key' => 'origin_postal_code',         'their_key' => 'originZip',           'transform' => 'digits',    'default_value' => ''],
        ['our_key' => 'origin_country',             'their_key' => 'originCountry',       'transform' => 'none',      'default_value' => 'USA'],

        // Destination
        ['our_key' => 'destination_city',           'their_key' => 'destinationCity',     'transform' => 'title',     'default_value' => ''],
        ['our_key' => 'destination_state',          'their_key' => 'destinationState',    'transform' => 'upper',     'default_value' => ''],
        ['our_key' => 'destination_postal_code',    'their_key' => 'destinationZip',      'transform' => 'digits',    'default_value' => ''],
        ['our_key' => 'destination_country',        'their_key' => 'destinationCountry',  'transform' => 'none',      'default_value' => 'USA'],

        // Shipping details
        ['our_key' => 'ship_date',                  'their_key' => 'estShipDate',         'transform' => 'date_Ymd',  'default_value' => ''],
        ['our_key' => 'transport_type',             'their_key' => 'shipVia',             'transform' => 'map_transport_type_reverse', 'default_value' => '0'],
        ['our_key' => 'comment_from_shipper',       'their_key' => 'noteFromShipper',     'transform' => 'none',      'default_value' => ''],

        // Vehicle (first vehicle fields)
        ['our_key' => 'Vehicles.0.vehicle_model_year','their_key' => 'Vehicles.0.year',    'transform' => 'int',       'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_make',     'their_key' => 'Vehicles.0.make',    'transform' => 'title',     'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_model',    'their_key' => 'Vehicles.0.model',   'transform' => 'title',     'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_type',     'their_key' => 'Vehicles.0.type',    'transform' => 'title',     'default_value' => 'Car'],
        ['our_key' => 'Vehicles.0.vehicle_inop',     'their_key' => 'Vehicles.0.isInoperable','transform' => 'inop_binary_to_bool', 'default_value' => ''],
    ];
}

/* Пресет Door to Door Transport — перенесено з прод-правки 03.08.2026 (mavspanel) */
function lr_partner_door_to_door_transport_default_map(): array {
    return [
        ['our_key' => 'auth_key',                    'their_key' => 'auth_key',                 'transform' => 'none',            'default_value' => ''],

        ['our_key' => 'first_name',                  'their_key' => 'first_name',               'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'last_name',                   'their_key' => 'last_name',                'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'email',                       'their_key' => 'email',                    'transform' => 'lower',           'default_value' => ''],
        ['our_key' => 'phone',                       'their_key' => 'phone',                    'transform' => 'digits',          'default_value' => ''],
        ['our_key' => 'comment_from_shipper',        'their_key' => 'comment_from_shipper',     'transform' => 'none',            'default_value' => ''],

        ['our_key' => 'origin_city',                 'their_key' => 'origin_city',              'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'origin_state',                'their_key' => 'origin_state',             'transform' => 'upper',           'default_value' => ''],
        ['our_key' => 'origin_postal_code',          'their_key' => 'origin_postal_code',       'transform' => 'digits',          'default_value' => ''],
        ['our_key' => 'origin_country',              'their_key' => 'origin_country',           'transform' => 'none',            'default_value' => ''],

        ['our_key' => 'destination_city',            'their_key' => 'destination_city',         'transform' => 'title',           'default_value' => ''],
        ['our_key' => 'destination_state',           'their_key' => 'destination_state',        'transform' => 'upper',           'default_value' => ''],
        ['our_key' => 'destination_postal_code',     'their_key' => 'destination_postal_code',  'transform' => 'digits',          'default_value' => ''],
        ['our_key' => 'destination_country',         'their_key' => 'destination_country',      'transform' => 'none',            'default_value' => ''],

        ['our_key' => 'ship_date',                   'their_key' => 'ship_date',                'transform' => 'date_Ymd_slash',        'default_value' => ''],
        ['our_key' => 'transport_type',              'their_key' => 'transport_type',           'transform' => 'map_transport_type_open_enclosed', 'default_value' => 'Open'],

        // Vehicles array (first vehicle)
        ['our_key' => 'Vehicles.0.vehicle_inop',     'their_key' => 'vehicles.0.vehicle_inop',  'transform' => 'inop_binary_to_bool', 'default_value' => 'false'],
        ['our_key' => 'Vehicles.0.vehicle_make',     'their_key' => 'vehicles.0.vehicle_make',  'transform' => 'title',             'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_model',    'their_key' => 'vehicles.0.vehicle_model', 'transform' => 'title',             'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_model_year','their_key' => 'vehicles.0.vehicle_model_year','transform' => 'int','default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_type',     'their_key' => 'vehicles.0.vehicle_type',  'transform' => 'title',             'default_value' => ''],
    ];
}
