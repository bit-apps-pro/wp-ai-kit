<?php

namespace BitApps\AiKit;

/**
 * Everything about the consuming plugin the kit must not guess.
 *
 * Built once by the plugin at boot and passed to Kit::boot(). The kit never
 * reads a plugin's Config class, option names or capabilities directly: each
 * plugin names them here, so three plugins on one site keep their options,
 * REST namespaces and permissions apart, and the kit stays free of plugin and
 * wp-kit dependencies.
 *
 * Callbacks are plain callables. Authorization is the plugin's: `canUse` says
 * whether a user may use AI/MCP features at all (Bit Form: manage_bitform or
 * manage_options; Bit CRM: Capability::check; Bit PI: manage_options), and
 * `can` answers capability checks for individual tools. Both default to
 * current_user_can() when not given.
 */
final class PluginContext
{
    /** The MCP server: transport, OAuth, API keys, Abilities API and MCP Adapter bridges. */
    public const FEATURE_MCP = 'mcp';

    /** The in-admin AI assistant (chat, conversations, provider engines). */
    public const FEATURE_ASSISTANT = 'assistant';

    /**
     * Services that may be given as a factory (a Closure returning the object),
     * so building them, and loading their classes, waits until the kit needs them.
     */
    private const FACTORY_SERVICES = ['audit', 'conversations', 'oauth_storage'];

    private string $slug;

    private string $name;

    private string $version;

    private string $optionPrefix;

    private string $restNamespace;

    private string $textDomain;

    /** @var callable(int): bool */
    private $canUse;

    /** @var callable(string, mixed ...$args): bool */
    private $can;

    /** @var array<string, string> legacy scope string → canonical scope */
    private array $scopeMap;

    /** @var callable(string, string, array): void  level, message, context */
    private $logger;

    /** @var array<string, string> named admin URLs (settings, consent, …) */
    private array $urls;

    /** @var array<string, mixed> plugin-specific extras read by adapters (storage, audit, pii classifier) */
    private array $services;

    private string $instructions;

    /** @var array<string, bool> which features this plugin ships (FEATURE_*) */
    private array $features;

    /**
     * @param array<string, mixed> $args see create() for the keys
     */
    private function __construct(array $args)
    {
        $this->slug = $args['slug'];
        $this->name = $args['name'];
        $this->version = $args['version'];
        $this->optionPrefix = $args['option_prefix'];
        $this->restNamespace = trim($args['rest_namespace'], '/');
        $this->textDomain = $args['text_domain'];
        $this->canUse = $args['can_use'];
        $this->can = $args['can'];
        $this->scopeMap = $args['scope_map'];
        $this->logger = $args['logger'];
        $this->urls = $args['urls'];
        $this->services = $args['services'];
        $this->instructions = $args['instructions'];
        $this->features = $args['features'];
    }

    /**
     * Required: `slug`, `name`, `version`, `option_prefix`, `rest_namespace`.
     * Optional: `text_domain` (defaults to slug), `can_use` (callable(int $userId): bool),
     * `can` (callable(string $capability, ...$args): bool), `scope_map` (array),
     * `logger` (callable(string $level, string $message, array $context): void),
     * `urls` (array<string,string>), `services` (array<string,mixed>),
     * `instructions` (string: what the MCP server tells clients about the tools),
     * `features` (array<string,bool>: which of FEATURE_MCP / FEATURE_ASSISTANT the plugin
     * ships; both by default. A feature left out registers no hooks and can never be
     * switched on, whatever its settings say).
     *
     * @param array<string, mixed> $args
     */
    public static function create(array $args): self
    {
        foreach (['slug', 'name', 'version', 'option_prefix', 'rest_namespace'] as $key) {
            if (!isset($args[$key]) || !\is_string($args[$key]) || '' === trim($args[$key])) {
                throw new \InvalidArgumentException("PluginContext needs a non-empty string '{$key}'.");
            }
        }

        if ('' === Contract::token($args['slug'])) {
            throw new \InvalidArgumentException('PluginContext slug must contain letters or digits.');
        }

        if (!preg_match('/^[a-z0-9_]+$/', $args['option_prefix'])) {
            throw new \InvalidArgumentException('PluginContext option_prefix must be lowercase [a-z0-9_].');
        }

        $defaults = [
            'text_domain' => $args['slug'],
            'can_use'     => static fn ($userId): bool => function_exists('user_can') ? (bool) user_can((int) $userId, 'manage_options') : false,
            'can'         => static fn (string $capability, ...$extra): bool => function_exists('current_user_can') ? (bool) current_user_can($capability, ...$extra) : false,
            'scope_map'   => [],
            'logger'      => static function (string $level, string $message, array $context = []): void {
                if (\defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[wp-ai-kit:' . $level . '] ' . $message . ($context ? ' ' . json_encode($context) : '')); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }
            },
            'urls'         => [],
            'services'     => [],
            'instructions' => '',
            'features'     => [],
        ];

        $args = array_merge($defaults, $args);

        foreach (['can_use', 'can', 'logger'] as $callable) {
            if (!\is_callable($args[$callable])) {
                throw new \InvalidArgumentException("PluginContext '{$callable}' must be callable.");
            }
        }

        if (!\is_string($args['instructions'])) {
            throw new \InvalidArgumentException("PluginContext 'instructions' must be a string.");
        }

        foreach (['scope_map', 'urls', 'services', 'features'] as $array) {
            if (!\is_array($args[$array])) {
                throw new \InvalidArgumentException("PluginContext '{$array}' must be an array.");
            }
        }

        $args['features'] = [
            self::FEATURE_MCP       => (bool) ($args['features'][self::FEATURE_MCP] ?? true),
            self::FEATURE_ASSISTANT => (bool) ($args['features'][self::FEATURE_ASSISTANT] ?? true),
        ];

        return new self($args);
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function textDomain(): string
    {
        return $this->textDomain;
    }

    /** REST namespace the plugin's MCP and OAuth routes live under, e.g. `bitform/v1`. */
    public function restNamespace(): string
    {
        return $this->restNamespace;
    }

    /** `<prefix><key>` — every option, transient and token prefix the kit stores for this plugin. */
    public function optionName(string $key): string
    {
        return $this->optionPrefix . $key;
    }

    public function optionPrefix(): string
    {
        return $this->optionPrefix;
    }

    /** Whether the user may use the plugin's AI/MCP features at all. */
    public function canUse(int $userId): bool
    {
        return (bool) ($this->canUse)($userId);
    }

    /** Capability check for one tool, in the plugin's own terms. */
    public function can(string $capability, ...$extra): bool
    {
        return (bool) ($this->can)($capability, ...$extra);
    }

    /** Canonical scope for a stored/legacy scope string (identity when unmapped). */
    public function canonicalScope(string $scope): string
    {
        return $this->scopeMap[$scope] ?? $scope;
    }

    /** @return array<string, string> */
    public function scopeMap(): array
    {
        return $this->scopeMap;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        ($this->logger)($level, $message, $context);
    }

    public function url(string $name): ?string
    {
        return $this->urls[$name] ?? null;
    }

    /** What the MCP server tells clients about this plugin's tools (initialize `instructions`). */
    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * A plugin-supplied service by name (e.g. 'storage', 'audit', 'pii_classifier');
     * adapters fall back to the kit's defaults when absent.
     *
     * @return null|mixed
     */
    public function service(string $name)
    {
        $service = $this->services[$name] ?? null;
        if ($service instanceof \Closure && \in_array($name, self::FACTORY_SERVICES, true)) {
            $service = $this->services[$name] = $service();
        }

        return $service;
    }

    /** Whether the plugin ships a feature (FEATURE_MCP, FEATURE_ASSISTANT). */
    public function provides(string $feature): bool
    {
        return $this->features[$feature] ?? false;
    }
}
