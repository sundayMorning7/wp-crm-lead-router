<?php
/**
 * LR_DNS_Fix — обхід зламаного системного DNS-резолвера на хостингу.
 *
 * Симптом (Namecheap, серпень 2026): gethostbyname()/curl не резолвлять частину
 * доменів (api.batscrm.com, api.stripe.com), хоча прямі DNS-запити
 * (dns_get_record, DoH) працюють і конект до IP проходить.
 *
 * Рішення: на кожен wp_remote_* запит резолвимо хост самі (dns_get_record,
 * fallback — DoH на 1.1.1.1 / 8.8.8.8 напряму по IP) і підставляємо результат
 * у curl через CURLOPT_RESOLVE. Якщо резолв не вдався — нічого не чіпаємо,
 * запит іде звичайним шляхом. TLS/SNI/сертифікати працюють як завжди:
 * CURLOPT_RESOLVE підміняє лише крок DNS, а не хост.
 */

defined('ABSPATH') || exit;

class LR_DNS_Fix
{
    /** Кеш позитивного резолва, сек (у батс/страйпа TTL=10, але IP стабільний) */
    const CACHE_OK   = 120;
    /** Кеш негативного резолва, сек — щоб не довбати DNS на кожен запит */
    const CACHE_FAIL = 60;

    public static function init(): void
    {
        add_action('http_api_curl', [__CLASS__, 'pin_resolved_ip'], 10, 3);
    }

    /**
     * Хук http_api_curl: спрацьовує для всіх wp_remote_* через curl-транспорт.
     *
     * @param resource|\CurlHandle $handle
     * @param array                $parsed_args
     * @param string               $url
     */
    public static function pin_resolved_ip($handle, $parsed_args, $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost') {
            return; // IP-литерали і localhost резолвити не треба
        }

        $ip = self::resolve($host);
        if (!$ip) {
            return; // не вийшло — хай curl пробує сам, як раніше
        }

        curl_setopt($handle, CURLOPT_RESOLVE, [
            "{$host}:443:{$ip}",
            "{$host}:80:{$ip}",
        ]);
    }

    /** Повертає IPv4 хоста або null. Результат кешується у transient. */
    public static function resolve(string $host): ?string
    {
        $key    = 'lr_dnsfix_' . md5($host);
        $cached = get_transient($key);
        if ($cached !== false) {
            return $cached === 'fail' ? null : $cached;
        }

        $ip = self::via_dns_get_record($host);
        if (!$ip) {
            $ip = self::via_doh("https://1.1.1.1/dns-query?name={$host}&type=A");
        }
        if (!$ip) {
            $ip = self::via_doh("https://8.8.8.8/resolve?name={$host}&type=A");
        }

        set_transient($key, $ip ?: 'fail', $ip ? self::CACHE_OK : self::CACHE_FAIL);
        return $ip;
    }

    private static function via_dns_get_record(string $host): ?string
    {
        $recs = @dns_get_record($host, DNS_A);
        if (is_array($recs)) {
            foreach ($recs as $r) {
                if (!empty($r['ip']) && filter_var($r['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $r['ip'];
                }
            }
        }
        return null;
    }

    /**
     * DoH напряму по IP резолвера (1.1.1.1 / 8.8.8.8) — системний DNS не потрібен.
     * Сирий curl, а не wp_remote_*: щоб не зациклитись на власному хуку.
     */
    private static function via_doh(string $doh_url): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($doh_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => ['Accept: application/dns-json'],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        if (!$body) {
            return null;
        }
        $json = json_decode($body, true);
        foreach (($json['Answer'] ?? []) as $a) {
            // type 1 = A-запис
            if (($a['type'] ?? 0) === 1 && filter_var($a['data'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $a['data'];
            }
        }
        return null;
    }
}
