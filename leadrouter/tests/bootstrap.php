<?php
/**
 * Bootstrap for LeadRouter PHPUnit tests.
 * Stubs all WordPress globals and functions so plugin classes can be loaded
 * and tested without a running WordPress installation.
 */

define('ABSPATH', __DIR__ . '/../');
define('WPINC', 'wp-includes');

// ---------------------------------------------------------------------------
// WP_Error stub
// ---------------------------------------------------------------------------
if (!class_exists('WP_Error')) {
    class WP_Error {
        private array $errors = [];
        private array $error_data = [];

        public function __construct(string $code = '', string $message = '', $data = '') {
            if ($code !== '') {
                $this->errors[$code][] = $message;
                if ($data !== '') {
                    $this->error_data[$code] = $data;
                }
            }
        }

        public function get_error_code(): string {
            $codes = array_keys($this->errors);
            return $codes[0] ?? '';
        }

        public function get_error_message(string $code = ''): string {
            if ($code === '') {
                $code = $this->get_error_code();
            }
            return $this->errors[$code][0] ?? '';
        }

        public function get_error_messages(string $code = ''): array {
            if ($code === '') {
                $all = [];
                foreach ($this->errors as $msgs) {
                    $all = array_merge($all, $msgs);
                }
                return $all;
            }
            return $this->errors[$code] ?? [];
        }

        public function get_error_data(string $code = '') {
            if ($code === '') {
                $code = $this->get_error_code();
            }
            return $this->error_data[$code] ?? null;
        }

        public function add(string $code, string $message, $data = ''): void {
            $this->errors[$code][] = $message;
            if ($data !== '') {
                $this->error_data[$code] = $data;
            }
        }

        public function has_errors(): bool {
            return !empty($this->errors);
        }
    }
}

// ---------------------------------------------------------------------------
// WP_Post stub
// ---------------------------------------------------------------------------
if (!class_exists('WP_Post')) {
    class WP_Post {
        public int    $ID            = 0;
        public string $post_type     = '';
        public string $post_status   = 'publish';
        public string $post_title    = '';

        public function __construct(array $data = []) {
            foreach ($data as $k => $v) {
                $this->$k = $v;
            }
        }
    }
}

// ---------------------------------------------------------------------------
// WP_Query stub (minimal)
// ---------------------------------------------------------------------------
if (!class_exists('WP_Query')) {
    class WP_Query {
        public array $posts = [];
        public function __construct(array $args = []) {}
    }
}

// ---------------------------------------------------------------------------
// WordPress function stubs
// ---------------------------------------------------------------------------

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool {
        return ($thing instanceof WP_Error);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string {
        return strip_tags(trim($str));
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = []): array {
        if (is_array($args)) {
            return array_merge($defaults, $args);
        }
        if (is_object($args)) {
            return array_merge($defaults, (array)$args);
        }
        return $defaults;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, $value, ...$args) {
        return $value;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, ...$args): void {}
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false) {
        if ($key !== '' && isset($GLOBALS['__lr_test_post_meta'][$post_id . '|' . $key])) {
            return $GLOBALS['__lr_test_post_meta'][$post_id . '|' . $key];
        }
        return $single ? '' : [];
    }
}

if (!function_exists('get_post_status')) {
    function get_post_status(int $post_id): string {
        return 'publish';
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title($post): string {
        return '';
    }
}

if (!function_exists('get_post_type')) {
    function get_post_type($post): string {
        return '';
    }
}

if (!function_exists('get_post')) {
    function get_post($post) {
        return null;
    }
}

if (!function_exists('get_posts')) {
    function get_posts(array $args = []): array {
        return [];
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg($args, string $url = ''): string {
        if (is_array($args) && $url !== '') {
            $query = http_build_query($args);
            return $url . (strpos($url, '?') === false ? '?' : '&') . $query;
        }
        return $url;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = []) {
        if (isset($GLOBALS['__lr_test_wp_remote_post']) && is_callable($GLOBALS['__lr_test_wp_remote_post'])) {
            return $GLOBALS['__lr_test_wp_remote_post']($url, $args);
        }
        return new WP_Error('not_implemented', 'wp_remote_post is not available in test env');
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request(string $url, array $args = []) {
        if (isset($GLOBALS['__lr_test_wp_remote_request']) && is_callable($GLOBALS['__lr_test_wp_remote_request'])) {
            return $GLOBALS['__lr_test_wp_remote_request']($url, $args);
        }
        return new WP_Error('not_implemented', 'wp_remote_request is not available in test env');
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        if (isset($GLOBALS['__lr_test_wp_remote_retrieve_response_code']) && is_callable($GLOBALS['__lr_test_wp_remote_retrieve_response_code'])) {
            return $GLOBALS['__lr_test_wp_remote_retrieve_response_code']($response);
        }
        if (is_array($response) && isset($response['response']['code'])) {
            return (int)$response['response']['code'];
        }
        return 0;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string {
        if (isset($GLOBALS['__lr_test_wp_remote_retrieve_body']) && is_callable($GLOBALS['__lr_test_wp_remote_retrieve_body'])) {
            return (string)$GLOBALS['__lr_test_wp_remote_retrieve_body']($response);
        }
        if (is_array($response) && isset($response['body'])) {
            return (string)$response['body'];
        }
        return '';
    }
}

if (!function_exists('wp_remote_retrieve_headers')) {
    function wp_remote_retrieve_headers($response) {
        if (isset($GLOBALS['__lr_test_wp_remote_retrieve_headers']) && is_callable($GLOBALS['__lr_test_wp_remote_retrieve_headers'])) {
            return $GLOBALS['__lr_test_wp_remote_retrieve_headers']($response);
        }
        if (is_array($response) && isset($response['headers']) && is_array($response['headers'])) {
            return $response['headers'];
        }
        return [];
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string {
        return date($type === 'mysql' ? 'Y-m-d H:i:s' : 'U');
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($time = null, bool $create_dir = true, bool $refresh_cache = false): array {
        return ['basedir' => sys_get_temp_dir(), 'baseurl' => 'http://example.com/uploads'];
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool {
        return @mkdir($target, 0755, true) || is_dir($target);
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $string): string {
        return rtrim($string, '/\\') . '/';
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $transient, $value, int $expiration = 0): bool {
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $transient) {
        return false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $transient): bool {
        return true;
    }
}

if (!function_exists('carbon_get_post_meta')) {
    function carbon_get_post_meta(int $post_id, string $name, string $container_type = 'post_meta') {
        if (isset($GLOBALS['__lr_test_carbon_meta'][$post_id . '|' . $name])) {
            return $GLOBALS['__lr_test_carbon_meta'][$post_id . '|' . $name];
        }
        return '';
    }
}

if (!function_exists('http_build_query')) {
    // Already a PHP built-in; just in case.
}

if (!function_exists('lr_dot_flatten')) {
    function lr_dot_flatten(array $arr, string $prefix = ''): array {
        $res = [];
        foreach ($arr as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (is_array($v)) {
                $res += lr_dot_flatten($v, $key);
            } else {
                $res[$key] = $v;
            }
        }
        return $res;
    }
}

if (!function_exists('lr_build_partner_payload')) {
    function lr_build_partner_payload(array $our_payload, array $map_rows): array {
        return $our_payload;
    }
}

// ---------------------------------------------------------------------------
// $wpdb stub — minimal implementation for helpers that reference it at call-time
// ---------------------------------------------------------------------------
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public string $prefix = 'wp_';

        public function prepare(string $sql, ...$args): string
        {
            // Replaces %d/%s/%f with positional args for test environments.
            $i = 0;
            return preg_replace_callback('/%[dsf]/', function () use ($args, &$i) {
                return $args[$i++] ?? '?';
            }, $sql);
        }

        public function get_var($sql)       { return null; }
        public function get_row($sql, $out = OBJECT) { return null; }
        public function get_results($sql, $out = OBJECT) { return []; }
        public function insert(string $table, array $data, $format = null): ?int { return 1; }
        public function update(string $table, array $data, array $where, $format = null, $where_format = null) { return 1; }
        public function delete(string $table, array $where, $where_format = null) { return 1; }
        public function query(string $sql) { return 1; }
    };
}

// ---------------------------------------------------------------------------
// Load plugin source files
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../includes/class-leadrouter-transform.php';
require_once __DIR__ . '/../includes/functions-leadrouter.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/classes/class-leadrouter-sender.php';
require_once __DIR__ . '/../includes/classes/class-leadrouter_sender_light.php';
require_once __DIR__ . '/../includes/classes/class-leadrouter-partners.php';
require_once __DIR__ . '/../includes/classes/class-leadrouter-flow.php';
