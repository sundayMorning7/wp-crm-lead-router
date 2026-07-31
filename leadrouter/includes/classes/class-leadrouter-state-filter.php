<?php
if (!defined('ABSPATH')) exit;

/**
 * LeadRouter_State_Filter — централізована логіка фільтрації AK/HI.
 *
 * Єдине місце де визначається:
 *  - чи є лід «виключеним» (AK або HI у будь-якому напрямку)
 *  - чи партнер приймає такий лід (за його мета-налаштуваннями)
 *
 * Виключені штати: Alaska (AK) та Hawaii (HI).
 *
 * Мета-ключі партнера:
 *  - leadrouter_partner_allow_alaska  ('1' = приймає AK)
 *  - leadrouter_partner_allow_hawaii  ('1' = приймає HI)
 */
class LeadRouter_State_Filter
{
    /** @var string[] Штати, що вимагають явного дозволу партнера */
    private const EXCLUDED_STATES = ['AK', 'HI'];

    /**
     * Повертає true, якщо лід потрапляє до виключеного штату (from або to).
     */
    public static function is_excluded_state(string $from_state, string $to_state): bool
    {
        $from = strtoupper(trim($from_state));
        $to   = strtoupper(trim($to_state));
        return in_array($from, self::EXCLUDED_STATES, true)
            || in_array($to, self::EXCLUDED_STATES, true);
    }

    /**
     * Повертає true, якщо партнер може прийняти лід з урахуванням штатів.
     *
     * Якщо лід не є виключеним — завжди true.
     * Якщо лід AK — перевіряємо leadrouter_partner_allow_alaska.
     * Якщо лід HI — перевіряємо leadrouter_partner_allow_hawaii.
     */
    public static function partner_allows(int $partner_post_id, string $from_state, string $to_state): bool
    {
        $from = strtoupper(trim($from_state));
        $to   = strtoupper(trim($to_state));

        $need_ak = ($from === 'AK' || $to === 'AK');
        $need_hi = ($from === 'HI' || $to === 'HI');

        if (!$need_ak && !$need_hi) {
            return true;
        }

        if ($need_ak) {
            $allow_ak = get_post_meta($partner_post_id, 'leadrouter_partner_allow_alaska', true);
            if (!self::is_truthy($allow_ak)) {
                return false;
            }
        }

        if ($need_hi) {
            $allow_hi = get_post_meta($partner_post_id, 'leadrouter_partner_allow_hawaii', true);
            if (!self::is_truthy($allow_hi)) {
                return false;
            }
        }

        return true;
    }

    /** Допоміжний: '1', 1, true → true; усе інше → false */
    private static function is_truthy($value): bool
    {
        return ($value === '1' || $value === 1 || $value === true);
    }
}
