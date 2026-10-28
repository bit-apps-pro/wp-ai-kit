<?php

namespace BitApps\AiKit\Settings;

use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Security\SecretBox;
use BitApps\AiKit\Tools\Scopes;

/**
 * A plugin's AI assistant settings (`<prefix>ai_settings`, small and autoloaded:
 * the admin reads it on every screen) and provider keys (`<prefix>ai_credentials`,
 * encrypted and never autoloaded).
 *
 * `engine`: `auto` uses the WordPress AI Client when the site has a provider set
 * up in Settings → Connectors, else this plugin's own key; `wordpress` and
 * `provider` force one. Keys never leave PHP: the screen sees a last-four hint.
 */
final class AiSettings
{
    public const ENGINES = ['auto', 'wordpress', 'provider'];

    public const PROVIDERS = ['claude', 'openai', 'gemini', 'openai_compatible'];

    /** Suggestions for the model field; any model name the provider accepts can be typed. */
    public const SUGGESTED_MODELS = [
        'claude' => ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-5-5'],
        'openai' => ['gpt-5.4', 'gpt-5.4-mini', 'gpt-4.1'],
        'gemini' => ['gemini-3.5-flash', 'gemini-2.5-pro', 'gemini-2.5-flash'],
        // The service decides: its model name must be typed.
        'openai_compatible' => [],
    ];

    public const DEFAULTS = [
        'enabled'  => false,
        'engine'   => 'auto',
        'provider' => 'claude',
        'model'    => '',
        // openai_compatible only: the API base, up to (not including) /chat/completions.
        'base_url'            => '',
        'access_level'        => Scopes::READ,
        'disabled_tools'      => [],
        'confirm_destructive' => true,
        'allow_personal_data' => false,
        'max_turns'           => 6,
        'daily_messages'      => 100,
        'history_days'        => 30,
    ];

    private const LIMITS = [
        'max_turns'      => [1, 10],
        'daily_messages' => [1, 2000],
        'history_days'   => [1, 365],
    ];

    private SecretBox $box;

    public function __construct(private PluginContext $context)
    {
        $this->box = new SecretBox($context->slug());
    }

    public function optionName(): string
    {
        return $this->context->optionName('ai_settings');
    }

    public function credentialsOptionName(): string
    {
        return $this->context->optionName('ai_credentials');
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        $stored = get_option($this->optionName(), []);

        return array_merge(self::DEFAULTS, \is_array($stored) ? array_intersect_key($stored, self::DEFAULTS) : []);
    }

    /** The model to ask for: the saved one, or the provider's first suggestion. */
    public function model(?string $provider = null): string
    {
        $settings = $this->read();
        $provider ??= (string) $settings['provider'];

        return '' !== $settings['model'] ? (string) $settings['model'] : (self::SUGGESTED_MODELS[$provider][0] ?? '');
    }

    /** The decrypted key for a provider ('' when none). */
    public function apiKey(string $provider): string
    {
        $credentials = get_option($this->credentialsOptionName(), []);

        return \is_array($credentials) && \is_string($credentials[$provider] ?? null) ? $this->box->decrypt($credentials[$provider]) : '';
    }

    /**
     * What a settings screen may see: the settings and, per provider, whether a
     * key is saved and its last four characters.
     *
     * @return array<string, mixed>
     */
    public function publicView(): array
    {
        $keys = [];
        foreach (self::PROVIDERS as $provider) {
            $key = $this->apiKey($provider);
            $keys[$provider] = ['set' => '' !== $key, 'hint' => '' !== $key ? '…' . substr($key, -4) : ''];
        }

        return ['settings' => $this->read(), 'keys' => $keys, 'suggested_models' => self::SUGGESTED_MODELS];
    }

    /**
     * Validate and store. `api_key` (plain) sets the key of the chosen provider;
     * `clear_api_key` removes it. Keys absent from $input keep their values.
     *
     * @param mixed    $input
     * @param string[] $knownTools
     *
     * @return array<string, mixed>
     */
    public function save($input, array $knownTools = []): array
    {
        $input = \is_object($input) ? (array) json_decode((string) wp_json_encode($input), true) : $input;
        $settings = $this->read();
        $before = $settings;

        if (\is_array($input)) {
            foreach (self::DEFAULTS as $key => $default) {
                if (\array_key_exists($key, $input)) {
                    $clean = $this->clean($key, $input[$key], $default, $knownTools);
                    // null: refused (a base URL that cannot be used); the saved value stays.
                    $settings[$key] = $clean ?? $settings[$key];
                }
            }

            // The compatible provider's key belongs to its URL: a new URL needs the key typed again.
            if ($settings['base_url'] !== $before['base_url']) {
                $this->storeKey('openai_compatible', '');
            }

            $provider = (string) $settings['provider'];
            if (!empty($input['clear_api_key'])) {
                $this->storeKey($provider, '');
            } elseif (\is_string($input['api_key'] ?? null) && '' !== trim($input['api_key'])) {
                $this->storeKey($provider, trim($input['api_key']));
            }
        }

        if (!add_option($this->optionName(), $settings, '', 'yes')) {
            update_option($this->optionName(), $settings, true);
        }

        return $settings;
    }

    /**
     * Why a base URL cannot be used, or null: https only, a public host (no local
     * or private address: wp_http_validate_url()), no credentials, query or fragment.
     * Empty is allowed (not set).
     *
     * @param mixed $url
     */
    public static function baseUrlError($url): ?string
    {
        if (!\is_string($url)) {
            return 'The base URL must be text.';
        }
        $url = trim($url);
        if ('' === $url) {
            return null;
        }
        $parts = wp_parse_url($url);
        if (!\is_array($parts) || 'https' !== strtolower((string) ($parts['scheme'] ?? '')) || empty($parts['host'])) {
            return 'Use an https:// address, such as https://api.mistral.ai/v1.';
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return 'Leave sign-in details, ? and # out of the base URL; the API key goes in its own field.';
        }
        if (\function_exists('wp_http_validate_url') && false === wp_http_validate_url($url)) {
            return 'This address is local or private, so the site will not send your data there.';
        }

        return null;
    }

    private static function normalBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        // A pasted full endpoint works too.
        return (string) preg_replace('#/chat/completions$#', '', $url);
    }

    private function storeKey(string $provider, string $key): void
    {
        $credentials = get_option($this->credentialsOptionName(), []);
        $credentials = \is_array($credentials) ? $credentials : [];

        if ('' === $key) {
            unset($credentials[$provider]);
        } else {
            $credentials[$provider] = $this->box->encrypt(mb_substr($key, 0, 500));
        }

        if (!add_option($this->credentialsOptionName(), $credentials, '', 'no')) {
            update_option($this->credentialsOptionName(), $credentials, false);
        }
    }

    /**
     * @param mixed    $value
     * @param mixed    $default
     * @param string[] $knownTools
     *
     * @return mixed
     */
    private function clean(string $key, $value, $default, array $knownTools)
    {
        switch ($key) {
            case 'engine':
                return \in_array($value, self::ENGINES, true) ? $value : $default;

            case 'provider':
                return \in_array($value, self::PROVIDERS, true) ? $value : $default;

            case 'access_level':
                return \in_array($value, Scopes::levels(), true) ? $value : $default;

            case 'base_url':
                // An unusable URL keeps the saved one; the REST save refuses it first with the reason.
                return null === self::baseUrlError($value) ? self::normalBaseUrl((string) $value) : null;

            case 'model':
                return \is_string($value) && preg_match('/^[A-Za-z0-9._:\/-]{0,100}$/', trim($value)) ? trim($value) : $default;

            case 'disabled_tools':
                $names = array_values(array_unique(array_filter((array) $value, 'is_string')));

                return [] === $knownTools ? $names : array_values(array_intersect($names, $knownTools));
        }

        if (isset(self::LIMITS[$key])) {
            [$min, $max] = self::LIMITS[$key];

            return max($min, min($max, (int) $value));
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
