<?php

/**
 * Plain-PHP test bootstrap with the WordPress functions the kit calls.
 *
 * As in bitapps/wp-kit, the stubs are deliberately small and record into
 * AiKitTestState so a test can assert what the kit asked WordPress to do.
 * Anything needing real WordPress behaviour belongs in a consuming plugin's
 * suite or the wp-env job, not here.
 */

use BitApps\AiKit\Kit;

if (!\in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    exit;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!\defined('ABSPATH')) {
    \define('ABSPATH', __DIR__ . '/Fixtures/wordpress/');
}
if (!\defined('WP_DEBUG')) {
    \define('WP_DEBUG', false);
}
if (!\defined('DAY_IN_SECONDS')) {
    \define('DAY_IN_SECONDS', 86400);
}
if (!\defined('HOUR_IN_SECONDS')) {
    \define('HOUR_IN_SECONDS', 3600);
}
if (!\defined('MINUTE_IN_SECONDS')) {
    \define('MINUTE_IN_SECONDS', 60);
}

final class AiKitTestState
{
    /** @var array<string, mixed> */
    public static array $options = [];

    /** @var array<string, bool> option name => autoloaded, for options written with an explicit flag */
    public static array $autoload = [];

    /** @var array<string, array{value: mixed, expires: int}> */
    public static array $transients = [];

    /** @var array<string, array<int, array{callback: callable, priority: int, args: int}>> */
    public static array $hooks = [];

    /** @var array<string, int> */
    public static array $didActions = [];

    /** @var array<int, array<string, bool>> userId => capability => granted */
    public static array $capabilities = [];

    public static int $currentUser = 0;

    public static bool $objectCache = false;

    /** @var array<string, array<string, mixed>> group => key => value */
    public static array $cache = [];

    /** @var string[] */
    public static array $logs = [];

    /** @var array<string, array<string, mixed>> url => wp_remote_* response */
    public static array $httpResponses = [];

    /** @var array<int, array<string, mixed>> */
    public static array $httpRequests = [];

    /** @var array<int, array<string, mixed>> userId => key => value */
    public static array $userMeta = [];

    /** @var array<string, array{timestamp: int, recurrence: string}> hook => event */
    public static array $cron = [];

    public static function reset(): void
    {
        self::$options = [];
        self::$autoload = [];
        self::$transients = [];
        self::$hooks = [];
        self::$didActions = [];
        self::$capabilities = [];
        self::$currentUser = 0;
        self::$objectCache = false;
        self::$cache = [];
        self::$logs = [];
        self::$httpResponses = [];
        self::$httpRequests = [];
        self::$userMeta = [];
        self::$cron = [];
        AiKitRest::$routes = [];
        if (class_exists('AiKitAbilities', false)) {
            AiKitAbilities::reset();
        }
        Kit::reset();
    }
}

function resetAiKitTestState(): void
{
    AiKitTestState::reset();
}

// --- cron ------------------------------------------------------------------

function wp_next_scheduled($hook)
{
    return AiKitTestState::$cron[$hook]['timestamp'] ?? false;
}

function wp_schedule_event($timestamp, $recurrence, $hook)
{
    AiKitTestState::$cron[$hook] = ['timestamp' => (int) $timestamp, 'recurrence' => (string) $recurrence];

    return true;
}

function wp_clear_scheduled_hook($hook)
{
    $had = isset(AiKitTestState::$cron[$hook]) ? 1 : 0;
    unset(AiKitTestState::$cron[$hook]);

    return $had;
}

// --- hooks -----------------------------------------------------------------

function add_filter($tag, $callback, $priority = 10, $acceptedArgs = 1)
{
    AiKitTestState::$hooks[$tag][] = ['callback' => $callback, 'priority' => $priority, 'args' => $acceptedArgs];
    usort(AiKitTestState::$hooks[$tag], static fn ($a, $b) => $a['priority'] <=> $b['priority']);

    return true;
}

function add_action($tag, $callback, $priority = 10, $acceptedArgs = 1)
{
    return add_filter($tag, $callback, $priority, $acceptedArgs);
}

function has_action($tag, $callback = false)
{
    if (!isset(AiKitTestState::$hooks[$tag])) {
        return false;
    }
    if (false === $callback) {
        return true;
    }
    foreach (AiKitTestState::$hooks[$tag] as $hook) {
        if ($hook['callback'] == $callback) {
            return $hook['priority'];
        }
    }

    return false;
}

function has_filter($tag, $callback = false)
{
    return has_action($tag, $callback);
}

function apply_filters($tag, $value, ...$args)
{
    foreach (AiKitTestState::$hooks[$tag] ?? [] as $hook) {
        $params = \array_slice(array_merge([$value], $args), 0, max(1, (int) $hook['args']));
        $value = \call_user_func_array($hook['callback'], $params);
    }

    return $value;
}

function do_action($tag, ...$args)
{
    AiKitTestState::$didActions[$tag] = (AiKitTestState::$didActions[$tag] ?? 0) + 1;
    foreach (AiKitTestState::$hooks[$tag] ?? [] as $hook) {
        \call_user_func_array($hook['callback'], \array_slice($args, 0, max(0, (int) $hook['args'])));
    }
}

function did_action($tag)
{
    return AiKitTestState::$didActions[$tag] ?? 0;
}

function doing_action($tag = null)
{
    return false;
}

function remove_filter($tag, $callback, $priority = 10)
{
    if (!isset(AiKitTestState::$hooks[$tag])) {
        return false;
    }
    AiKitTestState::$hooks[$tag] = array_values(array_filter(
        AiKitTestState::$hooks[$tag],
        static fn ($hook) => !($hook['callback'] == $callback && $hook['priority'] === $priority)
    ));

    return true;
}

function remove_action($tag, $callback, $priority = 10)
{
    return remove_filter($tag, $callback, $priority);
}

// --- options / transients / cache -----------------------------------------

function get_option($name, $default = false)
{
    return \array_key_exists($name, AiKitTestState::$options) ? AiKitTestState::$options[$name] : $default;
}

function update_option($name, $value, $autoload = null)
{
    AiKitTestState::$options[$name] = $value;
    if (null !== $autoload) {
        AiKitTestState::$autoload[$name] = true === $autoload || 'yes' === $autoload || 'on' === $autoload;
    }

    return true;
}

function add_option($name, $value = '', $deprecated = '', $autoload = 'yes')
{
    if (\array_key_exists($name, AiKitTestState::$options)) {
        return false;
    }
    AiKitTestState::$options[$name] = $value;
    AiKitTestState::$autoload[$name] = 'no' !== $autoload && false !== $autoload && 'off' !== $autoload;

    return true;
}

function delete_option($name)
{
    unset(AiKitTestState::$options[$name]);

    return true;
}

function get_transient($name)
{
    if (!isset(AiKitTestState::$transients[$name])) {
        return false;
    }
    $entry = AiKitTestState::$transients[$name];
    if ($entry['expires'] && $entry['expires'] < time()) {
        unset(AiKitTestState::$transients[$name]);

        return false;
    }

    return $entry['value'];
}

function set_transient($name, $value, $expiration = 0)
{
    AiKitTestState::$transients[$name] = ['value' => $value, 'expires' => $expiration ? time() + (int) $expiration : 0];

    return true;
}

function delete_transient($name)
{
    $existed = isset(AiKitTestState::$transients[$name]);
    unset(AiKitTestState::$transients[$name]);

    return $existed;
}

function wp_using_ext_object_cache($using = null)
{
    return AiKitTestState::$objectCache;
}

function wp_cache_add($key, $data, $group = '', $expire = 0)
{
    if (isset(AiKitTestState::$cache[$group][$key])) {
        return false;
    }
    AiKitTestState::$cache[$group][$key] = $data;

    return true;
}

function wp_cache_incr($key, $offset = 1, $group = '')
{
    if (!isset(AiKitTestState::$cache[$group][$key])) {
        return false;
    }
    AiKitTestState::$cache[$group][$key] += $offset;

    return AiKitTestState::$cache[$group][$key];
}

// --- users / capabilities -------------------------------------------------

function get_current_user_id()
{
    return AiKitTestState::$currentUser;
}

function wp_set_current_user($id)
{
    AiKitTestState::$currentUser = (int) $id;

    return $id;
}

function is_user_logged_in()
{
    return AiKitTestState::$currentUser > 0;
}

function user_can($user, $capability, ...$args)
{
    $id = \is_object($user) ? (int) $user->ID : (int) $user;

    return (bool) (AiKitTestState::$capabilities[$id][$capability] ?? false);
}

function current_user_can($capability, ...$args)
{
    return user_can(AiKitTestState::$currentUser, $capability, ...$args);
}

// --- misc ------------------------------------------------------------------

function wp_json_encode($data, $options = 0, $depth = 512)
{
    return json_encode($data, $options, $depth);
}

function wp_parse_args($args, $defaults = [])
{
    if (\is_object($args)) {
        $args = get_object_vars($args);
    } elseif (!\is_array($args)) {
        parse_str((string) $args, $args);
    }

    return array_merge($defaults, $args);
}

function wp_salt($scheme = 'auth')
{
    return 'test-salt-' . $scheme . '-0123456789abcdef0123456789abcdef';
}

function wp_generate_password($length = 12, $special = true, $extra = false)
{
    return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
}

function current_time($type, $gmt = 0)
{
    return 'timestamp' === $type ? time() : gmdate('Y-m-d H:i:s');
}

function __($text, $domain = 'default')
{
    return $text;
}

function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_html__($text, $domain = 'default')
{
    return esc_html($text);
}

function sanitize_text_field($str)
{
    return trim(strip_tags((string) $str));
}

function sanitize_key($key)
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

function _doing_it_wrong($function, $message, $version)
{
    AiKitTestState::$logs[] = "doing_it_wrong: {$function}: {$message}";
}

function wp_strip_all_tags($text, $removeBreaks = false)
{
    return trim(strip_tags((string) $text));
}

function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_url($url)
{
    return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
}

function esc_url_raw($url)
{
    return (string) $url;
}

function untrailingslashit($value)
{
    return rtrim((string) $value, '/\\');
}

function wp_unslash($value)
{
    return $value;
}

function admin_url($path = '', $scheme = 'admin')
{
    return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
}

function wp_login_url($redirect = '', $forceReauth = false)
{
    return 'https://example.test/wp-login.php' . ('' !== $redirect ? '?redirect_to=' . rawurlencode($redirect) : '');
}

function wp_create_nonce($action = -1)
{
    return substr(hash('sha256', AiKitTestState::$currentUser . '|' . $action), 0, 10);
}

function wp_verify_nonce($nonce, $action = -1)
{
    return \is_string($nonce) && hash_equals(wp_create_nonce($action), $nonce) ? 1 : false;
}

function wp_safe_remote_get($url, $args = [])
{
    AiKitTestState::$httpRequests[] = ['url' => $url, 'args' => $args];
    $response = AiKitTestState::$httpResponses[$url] ?? null;

    return null !== $response ? $response : new WP_Error('http_request_failed', 'No stubbed response.');
}

function wp_remote_retrieve_response_code($response)
{
    return \is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
}

function wp_remote_retrieve_body($response)
{
    return \is_array($response) ? (string) ($response['body'] ?? '') : '';
}

function get_user_meta($userId, $key = '', $single = false)
{
    $value = AiKitTestState::$userMeta[(int) $userId][$key] ?? null;

    return null === $value ? ($single ? '' : []) : $value;
}

function update_user_meta($userId, $key, $value, $previous = '')
{
    AiKitTestState::$userMeta[(int) $userId][$key] = $value;

    return true;
}

function delete_user_meta($userId, $key, $value = '')
{
    unset(AiKitTestState::$userMeta[(int) $userId][$key]);

    return true;
}

function get_user_locale($user = 0)
{
    return 'en_US';
}

function get_bloginfo($show = '', $filter = 'raw')
{
    return 'name' === $show ? 'Example Site' : '';
}

function wp_safe_remote_post($url, $args = [])
{
    AiKitTestState::$httpRequests[] = ['url' => $url, 'args' => $args];
    $response = AiKitTestState::$httpResponses[$url] ?? null;

    return null !== $response ? $response : new WP_Error('http_request_failed', 'No stubbed response.');
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public array $errors = [];

        public array $error_data = [];

        public function __construct($code = '', $message = '', $data = '')
        {
            if ('' !== $code) {
                $this->errors[$code][] = $message;
                if ('' !== $data) {
                    $this->error_data[$code] = $data;
                }
            }
        }

        public function get_error_code()
        {
            $codes = array_keys($this->errors);

            return $codes[0] ?? '';
        }

        public function get_error_message($code = '')
        {
            $code = $code ?: $this->get_error_code();

            return $this->errors[$code][0] ?? '';
        }

        public function get_error_data($code = '')
        {
            $code = $code ?: $this->get_error_code();

            return $this->error_data[$code] ?? null;
        }
    }
}

// --- REST ------------------------------------------------------------------

final class AiKitRest
{
    /** @var array<string, array<int, array<string, mixed>>> route => endpoints */
    public static array $routes = [];
}

function register_rest_route($namespace, $route, $args = [], $override = false)
{
    AiKitRest::$routes['/' . trim($namespace, '/') . $route] = isset($args['methods']) ? [$args] : $args;

    return true;
}

function rest_url($path = '', $scheme = 'rest')
{
    return 'https://example.test/wp-json/' . ltrim((string) $path, '/');
}

function home_url($path = '', $scheme = null)
{
    return 'https://example.test' . ($path ? '/' . ltrim($path, '/') : '');
}

function site_url($path = '', $scheme = null)
{
    return home_url($path, $scheme);
}

function get_userdata($userId)
{
    return $userId > 0 && $userId < 900 ? (object) ['ID' => (int) $userId, 'display_name' => 'User ' . $userId] : false;
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string, mixed> */
        public array $query = [];

        /** @var array<string, mixed> */
        public array $bodyParams = [];

        /** @var array<string, mixed> */
        public array $urlParams = [];
        private array $headers = [];

        private string $body = '';

        public function __construct(private string $method = 'POST', private string $route = '') {}

        public function set_header($key, $value): void
        {
            $this->headers[strtolower(str_replace('-', '_', $key))] = $value;
        }

        public function get_header($key)
        {
            return $this->headers[strtolower(str_replace('-', '_', $key))] ?? null;
        }

        public function set_body($body): void
        {
            $this->body = (string) $body;
        }

        public function get_body()
        {
            return $this->body;
        }

        public function get_route()
        {
            return $this->route;
        }

        public function get_method()
        {
            return $this->method;
        }

        public function get_query_params()
        {
            return $this->query;
        }

        public function get_body_params()
        {
            return $this->bodyParams;
        }

        public function get_json_params()
        {
            $decoded = json_decode($this->body, true);

            return \is_array($decoded) ? $decoded : null;
        }

        public function set_url_params(array $params): void
        {
            $this->urlParams = $params;
        }

        public function get_url_params()
        {
            return $this->urlParams;
        }

        public function get_params()
        {
            return array_merge($this->urlParams, $this->query, $this->bodyParams, $this->get_json_params() ?? []);
        }

        public function get_param($key)
        {
            return $this->get_params()[$key] ?? null;
        }

        public function set_param($key, $value): void
        {
            $this->bodyParams[$key] = $value;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public array $headers = [];

        public function __construct(public $data = null, public int $status = 200) {}

        public function header($key, $value, $replace = true): void
        {
            $this->headers[$key] = $value;
        }

        public function get_headers()
        {
            return $this->headers;
        }

        public function get_status()
        {
            return $this->status;
        }

        public function set_status($status): void
        {
            $this->status = (int) $status;
        }

        public function get_data()
        {
            return $this->data;
        }
    }
}

// --- Abilities API (core 6.9+) ---------------------------------------------

final class AiKitAbilities
{
    /** @var array<string, array<string, mixed>> */
    public static array $categories = [];

    /** @var array<string, array<string, mixed>> */
    public static array $abilities = [];

    public static function reset(): void
    {
        self::$categories = [];
        self::$abilities = [];
    }
}

function wp_register_ability_category($slug, $args)
{
    AiKitAbilities::$categories[$slug] = $args;

    return (object) ['slug' => $slug];
}

function wp_register_ability($name, $args)
{
    if (!preg_match('/^[a-z0-9-]+\/[a-z0-9-]+$/', $name) || isset(AiKitAbilities::$abilities[$name])) {
        return null;
    }
    AiKitAbilities::$abilities[$name] = $args;

    return (object) ['name' => $name];
}

function wp_has_ability($name)
{
    return isset(AiKitAbilities::$abilities[$name]);
}

function wp_has_ability_category($slug)
{
    return isset(AiKitAbilities::$categories[$slug]);
}

class WP_Abilities_Registry
{
    public static function get_instance()
    {
        return new self();
    }

    public function register($name, $args)
    {
        return wp_register_ability($name, $args);
    }
}

class WP_Ability_Categories_Registry
{
    public static function get_instance()
    {
        return new self();
    }

    public function register($slug, $args)
    {
        return wp_register_ability_category($slug, $args);
    }
}

function wp_is_using_https()
{
    return true;
}

function wp_is_application_passwords_available()
{
    return true;
}
