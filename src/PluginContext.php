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
     * The kit's own admin REST routes (`<rest_namespace>/ai/...`) for the chat
     * panel and settings screens, so a plugin writes no controllers. Off unless
     * the plugin opts in: a plugin with its own endpoints keeps one surface.
     */
    public const FEATURE_REST_UI = 'rest_ui';

    /*
     * Optional parts a plugin may leave out (all on by default). One left out is
     * gone for good on that plugin: no hooks, no REST routes, no settings to switch
     * it back on, and the admin UI hides it. Settings, constants and filters can
     * still switch a shipped part off at run time.
     */

    /** The activity log: every tool call by the assistant and MCP apps, its screens and REST routes. */
    public const FEATURE_AUDIT = 'audit';

    /** Earlier chats: the history list, open, rename, pin, archive and delete. The current chat stays. */
    public const FEATURE_HISTORY = 'history';

    /** MCP sign-in with OAuth (approval page, connected apps). */
    public const FEATURE_OAUTH = 'oauth';

    /** MCP API keys for scripts and apps that cannot sign in with OAuth. */
    public const FEATURE_API_KEYS = 'api_keys';

    /** Offering the tools as WordPress abilities (Abilities API, WordPress 6.9+). */
    public const FEATURE_ABILITIES = 'abilities';

    /** Listing the tools on the WordPress MCP Adapter plugin's default server. */
    public const FEATURE_MCP_ADAPTER = 'mcp_adapter';

    /**
     * Files attached to assistant messages (images, PDFs, Word and text files),
     * kept a day in a private uploads folder. Off unless the plugin opts in: it
     * accepts uploads, so the plugin decides.
     */
    public const FEATURE_ATTACHMENTS = 'attachments';

    /**
     * Streamed replies (`POST <ns>/ai/assistant/stream`, server-sent events): the
     * text shows while the model writes and each tool as it runs. Off unless the
     * plugin opts in: a proxy that buffers responses holds the stream back, and the
     * UI then waits for the whole reply as without it.
     */
    public const FEATURE_STREAM = 'stream';

    /** Conversations in user meta, the activity log in an option (bounded). */
    public const STORAGE_OPTIONS = 'options';

    /** Conversations and the activity log in tables the kit creates. */
    public const STORAGE_TABLES = 'tables';

    /** Features a plugin may name in `features`, and whether each ships when left out. */
    private const FEATURE_DEFAULTS = [
        self::FEATURE_MCP         => true,
        self::FEATURE_ASSISTANT   => true,
        self::FEATURE_REST_UI     => false,
        self::FEATURE_AUDIT       => true,
        self::FEATURE_HISTORY     => true,
        self::FEATURE_OAUTH       => true,
        self::FEATURE_API_KEYS    => true,
        self::FEATURE_ABILITIES   => true,
        self::FEATURE_MCP_ADAPTER => true,
        self::FEATURE_ATTACHMENTS => false,
        self::FEATURE_STREAM      => false,
    ];

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

    private string $description;

    private string $websiteUrl;

    /** @var list<array{src: string, mimeType?: string, sizes?: string[], theme?: string}> */
    private array $icons;

    /** @var array<string, bool> which features this plugin ships (FEATURE_*) */
    private array $features;

    /** The capability that may change settings, keys and other users' connections. */
    private string $manageCap;

    /** Where conversations and the activity log live: `options` (default) or `tables`. */
    private string $storage;

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
        $this->description = $args['description'];
        $this->websiteUrl = $args['website_url'];
        $this->icons = $args['icons'];
        $this->features = $args['features'];
        $this->manageCap = $args['manage_cap'];
        $this->storage = $args['storage'];
    }

    /**
     * Required: `slug`, `name`, `version`, `option_prefix`, `rest_namespace`.
     * Optional: `text_domain` (defaults to slug), `can_use` (callable(int $userId): bool),
     * `can` (callable(string $capability, ...$args): bool), `scope_map` (array),
     * `logger` (callable(string $level, string $message, array $context): void),
     * `urls` (array<string,string>), `services` (array<string,mixed>),
     * `instructions` (string: what the MCP server tells clients about the tools),
     * `description` (string), `website_url` (http/https URL) and `icons` (list of MCP icons:
     * `src` (https or data:image URI), optional `mimeType`, `sizes` ("48x48" or "any") and
     * `theme` ("light"/"dark")), which describe the server to MCP clients in `serverInfo`;
     * an invalid URL or icon entry is left out rather than failing the boot,
     * `features` (array<string,bool>: which parts the plugin ships — FEATURE_MCP and
     * FEATURE_ASSISTANT, and the optional parts FEATURE_AUDIT, FEATURE_HISTORY,
     * FEATURE_OAUTH, FEATURE_API_KEYS, FEATURE_ABILITIES and FEATURE_MCP_ADAPTER; all on
     * by default. A feature set to false registers no hooks or routes and can never be
     * switched on, whatever its settings say. FEATURE_REST_UI is off by default),
     * `manage_cap` (string capability for changing settings and others' keys and
     * connections; `manage_options` by default — `can_use` still gates every route),
     * `storage` (`options`, the default: one conversation per user in user meta and the last
     * 200 activity events in an option; or `tables`: the kit creates and maintains
     * `{wp_prefix}{option_prefix}aikit_conversations` and `…aikit_audit_log` for the parts the
     * plugin ships, with full chat history and a searchable log. A plugin's own `conversations`
     * or `audit` service still wins).
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
            'description'  => '',
            'website_url'  => '',
            'icons'        => [],
            'features'     => [],
            'manage_cap'   => 'manage_options',
            'storage'      => self::STORAGE_OPTIONS,
        ];

        $args = array_merge($defaults, $args);

        foreach (['can_use', 'can', 'logger'] as $callable) {
            if (!\is_callable($args[$callable])) {
                throw new \InvalidArgumentException("PluginContext '{$callable}' must be callable.");
            }
        }

        foreach (['instructions', 'description', 'website_url', 'manage_cap'] as $string) {
            if (!\is_string($args[$string])) {
                throw new \InvalidArgumentException("PluginContext '{$string}' must be a string.");
            }
        }

        foreach (['scope_map', 'urls', 'services', 'features', 'icons'] as $array) {
            if (!\is_array($args[$array])) {
                throw new \InvalidArgumentException("PluginContext '{$array}' must be an array.");
            }
        }

        $args['website_url'] = self::isWebUrl($args['website_url']) ? $args['website_url'] : '';
        $args['icons'] = self::cleanIcons($args['icons']);

        if (!\in_array($args['storage'], [self::STORAGE_OPTIONS, self::STORAGE_TABLES], true)) {
            throw new \InvalidArgumentException("PluginContext 'storage' must be 'options' or 'tables'.");
        }

        if ('' === trim($args['manage_cap'])) {
            throw new \InvalidArgumentException("PluginContext 'manage_cap' must name a capability.");
        }

        $given = \is_array($args['features'] ?? null) ? $args['features'] : [];
        $features = [];
        foreach (self::FEATURE_DEFAULTS as $feature => $default) {
            $features[$feature] = (bool) ($given[$feature] ?? $default);
        }
        $args['features'] = $features;

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

    /** `options` or `tables` (STORAGE_*). */
    public function storage(): string
    {
        return $this->storage;
    }

    public function optionPrefix(): string
    {
        return $this->optionPrefix;
    }

    /** The capability that may change settings and manage other users' keys and connections. */
    public function manageCapability(): string
    {
        return $this->manageCap;
    }

    /** Whether the user may change settings and manage every user's keys and connections. */
    public function canManage(int $userId): bool
    {
        return $userId > 0 && \function_exists('user_can') && (bool) user_can($userId, $this->manageCap);
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

    /** What the server does, for MCP clients (`serverInfo.description`); empty when not given. */
    public function description(): string
    {
        return $this->description;
    }

    /** The plugin's website (`serverInfo.websiteUrl`); empty when not given or invalid. */
    public function websiteUrl(): string
    {
        return $this->websiteUrl;
    }

    /** @return list<array{src: string, mimeType?: string, sizes?: string[], theme?: string}> `serverInfo.icons` */
    public function icons(): array
    {
        return $this->icons;
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

    /** Whether the plugin ships a feature (any FEATURE_* constant). */
    public function provides(string $feature): bool
    {
        return $this->features[$feature] ?? false;
    }

    private static function isWebUrl(string $url): bool
    {
        return '' !== $url && false !== filter_var($url, FILTER_VALIDATE_URL) && \in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /**
     * Keep the icons in MCP's shape (2025-11-25 `Icon`): an http(s) or `data:image/`
     * source, and only well-formed optional keys.
     *
     * @param array<mixed> $icons
     *
     * @return list<array{src: string, mimeType?: string, sizes?: string[], theme?: string}>
     */
    private static function cleanIcons(array $icons): array
    {
        $clean = [];
        foreach ($icons as $icon) {
            $src = \is_array($icon) && \is_string($icon['src'] ?? null) ? $icon['src'] : '';
            if (!self::isWebUrl($src) && !preg_match('#^data:image/[a-z0-9.+-]+;base64,[A-Za-z0-9+/=]+$#i', $src)) {
                continue;
            }

            $entry = ['src' => $src];
            if (\is_string($icon['mimeType'] ?? null) && preg_match('#^image/[a-z0-9.+-]+$#i', $icon['mimeType'])) {
                $entry['mimeType'] = $icon['mimeType'];
            }
            $sizes = array_values(array_filter((array) ($icon['sizes'] ?? []), static fn ($size) => \is_string($size) && preg_match('/^(\d+x\d+|any)$/', $size)));
            if ([] !== $sizes) {
                $entry['sizes'] = $sizes;
            }
            if (\in_array($icon['theme'] ?? null, ['light', 'dark'], true)) {
                $entry['theme'] = $icon['theme'];
            }
            $clean[] = $entry;
        }

        return $clean;
    }
}
