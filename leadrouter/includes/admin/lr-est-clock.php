<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Живий рядок з поточним часом у таймзоні плагіна (America/New_York, EST/EDT)
 * на КОЖНІЙ адмін-сторінці LeadRouter. Це той самий час, у якому працює логіка
 * денних квот груп, лімітів партнерів і нічного скидання eff — зручно для аналізу.
 *
 * Реалізовано одним хуком admin_notices (DRY), який рендериться лише на екранах
 * LeadRouter: сторінки admin.php?page=leadrouter*, CPT Групи/Партнери, налаштування/білінг.
 */
add_action('admin_notices', 'lr_render_est_clock');

function lr_render_est_clock(): void
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    // Чи це екран LeadRouter?
    $is_lr = false;
    if ($screen) {
        if (strpos((string) $screen->id, 'leadrouter') !== false) {
            $is_lr = true;
        }
        if (in_array($screen->post_type, ['leadrouter_group', 'leadrouter_partner'], true)) {
            $is_lr = true;
        }
    }
    if (isset($_GET['page']) && strpos((string) sanitize_key($_GET['page']), 'leadrouter') === 0) {
        $is_lr = true;
    }
    if (!$is_lr) {
        return;
    }

    // Поточний час у таймзоні плагіна
    $tz     = new DateTimeZone('America/New_York');
    $now    = new DateTimeImmutable('now', $tz);
    $abbr   = $now->format('T');            // EST або EDT (залежно від сезону)
    $offset = $now->getOffset();            // секунди відносно UTC — для живого лічильника
    $label  = $now->format('D, d M Y H:i:s');

    echo '<div class="notice notice-info lr-est-clock" style="border-left-color:#2271b1;">'
        . '<p style="margin:.5em 0;font-size:13px;">'
        . '🕒 <span>' . esc_html__('Час плагіна', 'leadrouter') . ' (America/New_York, ' . esc_html($abbr) . '):</span> '
        . '<strong id="lr-est-clock-val" style="font-variant-numeric:tabular-nums;">'
        . esc_html($label . ' ' . $abbr)
        . '</strong>'
        . ' <span style="color:#646970;">— ' . esc_html__('час денних квот / скидання eff', 'leadrouter') . '</span>'
        . '</p></div>';

    // Живий лічильник: тикає саме в EST/EDT незалежно від таймзони браузера
    // (використовуємо серверний offset + UTC-методи Date, тож локаль браузера не впливає).
    $abbr_js = esc_js($abbr);
    echo "<script>(function(){"
        . "var off={$offset},abbr='{$abbr_js}',el=document.getElementById('lr-est-clock-val');"
        . "if(!el){return;}"
        . "var D=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],M=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];"
        . "function p(n){return(n<10?'0':'')+n;}"
        . "function t(){var d=new Date(Date.now()+off*1000);"
        . "el.textContent=D[d.getUTCDay()]+', '+p(d.getUTCDate())+' '+M[d.getUTCMonth()]+' '+d.getUTCFullYear()+' '+p(d.getUTCHours())+':'+p(d.getUTCMinutes())+':'+p(d.getUTCSeconds())+' '+abbr;}"
        . "t();setInterval(t,1000);"
        . "})();</script>";
}
