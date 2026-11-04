<?php

namespace BitApps\AiKit;

use BitApps\AiKit\Abilities\AbilitiesBridge;
use BitApps\AiKit\Ai\Assistant;
use BitApps\AiKit\Ai\AssistantPrivacy;
use BitApps\AiKit\Ai\Attachments\AttachmentStore;
use BitApps\AiKit\Ai\ConversationStore;
use BitApps\AiKit\Ai\UserMetaConversationRepository;
use BitApps\AiKit\Audit\AuditLoggerInterface;
use BitApps\AiKit\Audit\NullAuditLogger;
use BitApps\AiKit\Audit\OptionsAuditLogger;
use BitApps\AiKit\Audit\SearchableAuditLog;
use BitApps\AiKit\Kit\AbilitiesMirror;
use BitApps\AiKit\Kit\AdminState;
use BitApps\AiKit\Kit\Hooks;
use BitApps\AiKit\Kit\Lifecycle;
use BitApps\AiKit\Mcp\Auth\ApiKeyService;
use BitApps\AiKit\Mcp\Auth\BearerResolverInterface;
use BitApps\AiKit\Mcp\OAuth\OAuthEndpoints;
use BitApps\AiKit\Mcp\OAuth\OAuthServer;
use BitApps\AiKit\Mcp\OAuth\OptionsOAuthStorage;
use BitApps\AiKit\Mcp\Server\McpServer;
use BitApps\AiKit\Mcp\Transport\HttpTransport;
use BitApps\AiKit\Security\PiiMasker;
use BitApps\AiKit\Security\RateLimiter;
use BitApps\AiKit\Security\SecretRedactor;
use BitApps\AiKit\Settings\AiSettings;
use BitApps\AiKit\Settings\FeatureFlags;
use BitApps\AiKit\Settings\McpSettings;
use BitApps\AiKit\Storage\TableAuditLogger;
use BitApps\AiKit\Storage\TableConversationRepository;
use BitApps\AiKit\Storage\Tables;
use BitApps\AiKit\Tools\AccessPolicy;
use BitApps\AiKit\Tools\Confirmation\ConfirmationGate;
use BitApps\AiKit\Tools\Scopes;
use BitApps\AiKit\Tools\ToolContext;
use BitApps\AiKit\Tools\ToolGuard;
use BitApps\AiKit\Tools\ToolRegistry;
use BitApps\AiKit\Tools\ToolRunner;

/**
 * One kit per consuming plugin: `Kit::boot(PluginContext::create([...]))`.
 *
 * boot() only registers hooks. Tool providers, transports, the Abilities bridge
 * and the AI engine are created on the first request that needs them, so a site
 * that never uses MCP or the assistant pays nothing beyond this object.
 *
 * Which native WordPress pieces are present is decided by probing (function and
 * class existence), never by version numbers; see Settings\RequirementsReport.
 *
 * This class builds and holds the kit's services. Hook callbacks, purges and what
 * the admin screens read live in Kit\*; their methods stay here as delegates because
 * they are public within 1.x.
 */
final class Kit
{
    /**
     * Options the kit stores, by key under the plugin's prefix; removeSiteData()
     * deletes them. A new option is added here too (KitTest checks every name the
     * kit uses). The tables' version stamp goes with the tables.
     */
    public const OWNED_OPTIONS = ['mcp_settings', 'ai_settings', 'ai_credentials', 'mcp_api_keys', 'mcp_oauth_clients', 'mcp_oauth_grants', 'ai_audit_log'];

    /** Expiry choices a settings screen offers, in days; null = never. */
    public const API_KEY_EXPIRY_DAYS = [7, 30, 90, 365, null];

    /** @var array<string, self> booted kits by plugin slug (one per prefixed copy) */
    private static array $instances = [];

    private ?ToolRegistry $registry = null;

    private ?ToolGuard $guard = null;

    private ?ToolRunner $runner = null;

    private ?HttpTransport $transport = null;

    private ?ApiKeyService $apiKeys = null;

    private ?AbilitiesBridge $abilities = null;

    private ?OAuthServer $oauth = null;

    private ?Tables $tables = null;

    private ?AttachmentStore $attachmentStore = null;

    /** @var BearerResolverInterface[] resolvers added by features such as OAuth */
    private array $extraResolvers = [];

    /** @var null|callable(): ?\BitApps\AiKit\Ai\Engine\EngineInterface test seam used by assistant() when it gets none */
    private $engineFactory;

    private function __construct(private PluginContext $context, private FeatureFlags $features) {}

    /**
     * Boot the kit for a plugin. Idempotent per slug within one copy of the kit.
     *
     * @param array<string, bool> $defaultFeatures feature => on/off defaults (see FeatureFlags)
     */
    public static function boot(PluginContext $context, array $defaultFeatures = []): self
    {
        $slug = $context->slug();

        if (isset(self::$instances[$slug])) {
            return self::$instances[$slug];
        }

        $kit = new self($context, new FeatureFlags($context, $defaultFeatures));
        self::$instances[$slug] = $kit;
        (new Hooks($kit))->register();

        if (\function_exists('do_action')) {
            do_action(Contract::bootedHook($slug), $kit);
        }

        return $kit;
    }

    /** The booted kit for a slug, or null before boot(). */
    public static function for(string $slug): ?self
    {
        return self::$instances[$slug] ?? null;
    }

    /** Test seam: forget booted kits. */
    public static function reset(): void
    {
        self::$instances = [];
    }

    public function context(): PluginContext
    {
        return $this->context;
    }

    public function features(): FeatureFlags
    {
        return $this->features;
    }

    /** The plugin's tools, collected on first use. */
    public function registry(): ToolRegistry
    {
        $this->registry ??= new ToolRegistry($this->context);

        return $this->registry;
    }

    public function guard(): ToolGuard
    {
        $this->guard ??= new ToolGuard($this->context, fn (string $channel) => $this->policyFor($channel));

        return $this->guard;
    }

    /** Executes tool calls for every channel; built on first use. */
    public function runner(): ToolRunner
    {
        if (null === $this->runner) {
            $settings = $this->features->settings(FeatureFlags::MCP_SERVER);
            $this->runner = new ToolRunner(
                $this->context,
                $this->registry(),
                $this->guard(),
                new RateLimiter($this->context->optionPrefix()),
                new SecretRedactor($this->context->slug()),
                $this->piiMasker(),
                $this->auditLogger(),
                new ConfirmationGate($this->context),
                [
                    'calls_per_minute'  => (int) ($settings['calls_per_minute'] ?? 120),
                    'writes_per_minute' => (int) ($settings['writes_per_minute'] ?? 30),
                ]
            );
        }

        return $this->runner;
    }

    /**
     * The access policy the site set for a channel: the assistant reads the AI
     * settings, every other channel (MCP, Abilities API, CLI) the MCP settings.
     */
    public function policyFor(string $channel): AccessPolicy
    {
        $feature = ToolContext::CHANNEL_ASSISTANT === $channel ? FeatureFlags::ASSISTANT : FeatureFlags::MCP_SERVER;

        return AccessPolicy::fromSettings($this->features->settings($feature));
    }

    /**
     * The plugin's audit logger, the options-backed default, or none when the
     * activity log is off — by the plugin (`features.audit`), a setting, a
     * constant or a filter. Off wins over a plugin-supplied logger.
     */
    public function auditLogger(): AuditLoggerInterface
    {
        if (!$this->features->isEnabled(FeatureFlags::AUDIT)) {
            return new NullAuditLogger();
        }

        $logger = $this->context->auditLogger();
        if (null !== $logger) {
            return $logger;
        }

        $options = new OptionsAuditLogger($this->context->optionName('ai_audit_log'));
        if (\in_array(Tables::AUDIT, $this->tables()->wanted(), true)) {
            return new TableAuditLogger($this->tables(), $options);
        }

        return $options;
    }

    /** The kit's own tables (PluginContext `storage => tables`). */
    public function tables(): Tables
    {
        return $this->tables ??= new Tables($this->context);
    }

    /**
     * Create the kit's tables for the parts this plugin ships. Call it from the
     * plugin's activation; the kit also runs it on the first request after an update.
     */
    public function installTables(): bool
    {
        return $this->tables()->install();
    }

    /** Drop the kit's tables and stop the purge. Call it from the plugin's uninstall. */
    public function uninstallTables(): void
    {
        $this->deactivate();
        $this->tables()->uninstall();
    }

    /** Stop the kit's scheduled work. Call it from the plugin's deactivation. */
    public function deactivate(): void
    {
        (new Lifecycle($this))->deactivate();
    }

    /**
     * Remove everything the kit keeps on this site: settings, keys, tokens, chats,
     * attached files, scheduled work and its tables. For the plugin's uninstall;
     * removeSiteData() does the same without a booted kit.
     */
    public function uninstall(bool $dropTables = true): void
    {
        self::removeSiteData($this->context, $dropTables);
    }

    /**
     * Everything the kit keeps on the current site, removed, without a booted kit:
     * see Kit\Lifecycle::removeSiteData(). A plugin that lets users keep their data
     * passes its own setting as $dropTables.
     */
    public static function removeSiteData(PluginContext $context, bool $dropTables = true): void
    {
        Lifecycle::removeSiteData($context, $dropTables);
    }

    /** @internal init */
    public function onStorageInit(): void
    {
        (new Lifecycle($this))->onStorageInit();
    }

    /** @internal the daily purge */
    public function onPurge(): void
    {
        (new Lifecycle($this))->onPurge();
    }

    /** `{option_prefix}aikit_purge`: the cron hook of the daily purge. */
    public function purgeHook(): string
    {
        return $this->context->optionName('aikit_purge');
    }

    /**
     * One page of the audit trail for an activity screen. A logger that cannot
     * search answers with its most recent events, unfiltered.
     *
     * @param array<string, mixed> $filters see SearchableAuditLog::search()
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function auditSearch(array $filters, int $page = 1, int $perPage = 25): array
    {
        $logger = $this->auditLogger();
        if ($logger instanceof SearchableAuditLog) {
            return $logger->search($filters, $page, $perPage);
        }

        $perPage = max(1, min(100, $perPage));
        $rows = $logger->recent($perPage * max(1, $page));

        return ['rows' => \array_slice($rows, ($page - 1) * $perPage, $perPage), 'total' => \count($rows)];
    }

    /**
     * The values an activity screen's filter menus offer (empty lists for a
     * logger that cannot search).
     *
     * @return array<string, array<int, mixed>>
     */
    public function auditFacets(): array
    {
        $logger = $this->auditLogger();

        return $logger instanceof SearchableAuditLog ? $logger->facets() : array_fill_keys(SearchableAuditLog::FILTERS, []);
    }

    /** Empty the audit trail; false when this site's logger cannot. */
    public function clearAudit(): bool
    {
        $logger = $this->auditLogger();

        return $logger instanceof SearchableAuditLog && $logger->clear();
    }

    /** The MCP server for this plugin (transport-independent dispatch). */
    public function mcpServer(): McpServer
    {
        return new McpServer($this->context, $this->runner());
    }

    /** The Streamable HTTP transport; built on first REST request that needs it. */
    public function transport(): HttpTransport
    {
        if (null === $this->transport) {
            $settings = $this->features->settings(FeatureFlags::MCP_SERVER);
            $this->transport = new HttpTransport(
                $this->context,
                $this->mcpServer(),
                $this->bearerResolvers(),
                $this->oauthEnabled() ? fn () => $this->oauth()?->endpoints()->protectedResourceMetadataUrl() : null,
                static fn () => !empty($settings['allow_personal_data'])
            );
        }

        return $this->transport;
    }

    /** Kit-issued API keys for this plugin. */
    public function apiKeys(): ApiKeyService
    {
        $this->apiKeys ??= new ApiKeyService($this->context);

        return $this->apiKeys;
    }

    /**
     * Create an API key for a user, from a settings screen. The access level is
     * capped at the site's MCP level; the plaintext key is returned once.
     *
     * @return array{key: string, record: array<string, mixed>}|\WP_Error
     */
    public function createApiKey(int $userId, string $name, string $level, ?int $expiresInDays, bool $unattended)
    {
        $name = trim(wp_strip_all_tags($name));
        if ('' === $name || mb_strlen($name) > 100) {
            return new \WP_Error('invalid_name', 'Give the key a name of up to 100 characters.');
        }
        if (!\in_array($level, Scopes::levels(), true)) {
            return new \WP_Error('invalid_level', 'Choose read, write or destructive.');
        }
        if (!\in_array($expiresInDays, self::API_KEY_EXPIRY_DAYS, true)) {
            return new \WP_Error('invalid_expiry', 'Choose one of the offered expiry periods.');
        }
        if (!$this->context->canUse($userId)) {
            return new \WP_Error('forbidden', 'This account may not use AI tools on this site.');
        }

        $ceiling = AccessPolicy::fromSettings($this->features->settings(FeatureFlags::MCP_SERVER))->level();

        return $this->apiKeys()->create($userId, $name, [Scopes::withinLevel($level, $ceiling) ? $level : $ceiling], $expiresInDays, $unattended);
    }

    /** Add a bearer resolver (the OAuth server registers itself here). */
    public function addBearerResolver(BearerResolverInterface $resolver): void
    {
        $this->extraResolvers[] = $resolver;
        $this->transport = null;
    }

    /** @return BearerResolverInterface[] */
    public function bearerResolvers(): array
    {
        $resolvers = $this->extraResolvers;
        $oauth = $this->oauth();
        if (null !== $oauth) {
            $resolvers[] = $oauth;
        }
        if ($this->features->isEnabled(FeatureFlags::API_KEYS)) {
            $resolvers[] = $this->apiKeys();
        }

        return $resolvers;
    }

    /** OAuth needs the MCP server on, the feature on, and pretty permalinks for discovery. */
    public function oauthEnabled(): bool
    {
        return $this->features->isEnabled(FeatureFlags::MCP_SERVER)
            && $this->features->isEnabled(FeatureFlags::OAUTH)
            && OAuthEndpoints::prettyPermalinks();
    }

    /** The plugin's authorization server, or null while OAuth is off. */
    public function oauth(): ?OAuthServer
    {
        if (!$this->oauthEnabled()) {
            return null;
        }

        $this->oauth ??= new OAuthServer(
            $this->context,
            rest_url($this->context->restNamespace() . HttpTransport::ROUTE),
            $this->context->oauthStorage() ?? new OptionsOAuthStorage($this->context),
            new RateLimiter($this->context->optionPrefix()),
            fn () => AccessPolicy::fromSettings($this->features->settings(FeatureFlags::MCP_SERVER))->level(),
            fn () => [] !== array_filter($this->registry()->all(), static fn ($tool) => $tool->containsPersonalData()),
            fn () => \count($this->runner()->listTools(new ToolContext(ToolContext::CHANNEL_MCP, (int) get_current_user_id())))
        );

        return $this->oauth;
    }

    /**
     * Connected OAuth clients for the settings screen, with the user's display
     * name; an empty list while OAuth is off.
     *
     * @return array<int, array<string, mixed>>
     */
    public function oauthConnections(): array
    {
        $oauth = $this->oauth();
        if (null === $oauth) {
            return [];
        }

        $rows = [];
        foreach ($oauth->tokens()->connections() as $connection) {
            $user = get_userdata((int) $connection['user_id']);
            $connection['user'] = \is_object($user) ? (string) ($user->display_name ?? '') : '';
            $rows[] = $connection;
        }

        return $rows;
    }

    /** Revoke a connection; with $userId, only if it is that user's own. */
    public function revokeOAuthConnection(string $id, ?int $userId = null): bool
    {
        $oauth = $this->oauth();

        return null !== $oauth && $oauth->tokens()->revoke($id, $userId);
    }

    public function revokeAllOAuthConnections(): int
    {
        $oauth = $this->oauth();

        return $oauth?->tokens()->revokeAll() ?? 0;
    }

    /** @internal init (priority 0) */
    public function onInit(): void
    {
        (new Hooks($this))->onInit();
    }

    /** @internal admin_post(_nopriv)_<slug>_mcp_authorize */
    public function onConsent(): void
    {
        (new Hooks($this))->onConsent();
    }

    /** @internal rest_api_init */
    public function onRestApiInit(): void
    {
        (new Hooks($this))->onRestApiInit();
    }

    /**
     * @internal rest_post_dispatch
     *
     * @param mixed $result
     * @param mixed $server
     * @param mixed $request
     *
     * @return mixed
     */
    public function onRestPostDispatch($result, $server = null, $request = null)
    {
        return (new Hooks($this))->onRestPostDispatch($result, $server, $request);
    }

    /**
     * @internal deleted_user
     *
     * @param mixed $userId
     */
    public function onUserDeleted($userId = 0): void
    {
        (new Hooks($this))->onUserDeleted($userId);
    }

    /**
     * @internal delete_user
     *
     * @param mixed $userId
     */
    public function onUserDeleting($userId = 0): void
    {
        (new Hooks($this))->onUserDeleting($userId);
    }

    /**
     * @internal wp_privacy_personal_data_exporters
     *
     * @param mixed $exporters
     *
     * @return mixed
     */
    public function onPrivacyExporters($exporters)
    {
        return (new Hooks($this))->onPrivacyExporters($exporters);
    }

    /**
     * @internal wp_privacy_personal_data_erasers
     *
     * @param mixed $erasers
     *
     * @return mixed
     */
    public function onPrivacyErasers($erasers)
    {
        return (new Hooks($this))->onPrivacyErasers($erasers);
    }

    public function privacy(): AssistantPrivacy
    {
        return new AssistantPrivacy($this->context, $this->conversations(), $this->attachments());
    }

    /** The plugin's MCP settings store (shared shape across Bit plugins). */
    public function mcpSettings(): McpSettings
    {
        return new McpSettings($this->context);
    }

    /**
     * Validate and save MCP settings from a settings screen; resets cached
     * transports so the next request uses them.
     *
     * @param mixed $input
     *
     * @return array<string, mixed>
     */
    public function saveMcpSettings($input): array
    {
        $saved = $this->mcpSettings()->save($input, array_keys($this->registry()->all()));
        $this->runner = null;
        $this->transport = null;
        $this->abilities = null;
        $this->oauth = null;
        do_action(Contract::settingsSavedHook($this->context->slug()), 'mcp', $saved);

        return $saved;
    }

    /**
     * Everything a settings screen shows: the settings, every tool with its
     * access level and whether the current settings allow it, the endpoint URL
     * and the environment checks.
     *
     * @return array<string, mixed>
     */
    public function adminState(): array
    {
        return (new AdminState($this))->adminState();
    }

    /** The plugin's AI assistant settings and provider keys. */
    public function aiSettings(): AiSettings
    {
        return new AiSettings($this->context);
    }

    /**
     * Validate and save the assistant settings from a settings screen.
     *
     * @param mixed $input
     *
     * @return array<string, mixed>
     */
    public function saveAiSettings($input): array
    {
        $saved = $this->aiSettings()->save($input, array_keys($this->registry()->all()));
        $this->runner = null;
        $this->abilities = null;
        do_action(Contract::settingsSavedHook($this->context->slug()), 'assistant', $saved);

        return $saved;
    }

    /**
     * User meta is shared by every site of a network, so the conversation key
     * carries the site id there: a chat (and its tool results) stays on its site.
     */
    public static function conversationMetaKey(PluginContext $context): string
    {
        $key = $context->optionName('ai_conversation');

        return \function_exists('is_multisite') && is_multisite() ? $key . '_' . get_current_blog_id() : $key;
    }

    /**
     * The assistant's conversations: the plugin's repository (`conversations`
     * service) when it has one, else one conversation per user in user meta.
     */
    public function conversations(): ConversationStore
    {
        $metaKey = self::conversationMetaKey($this->context);
        $repository = $this->context->conversationRepository();
        $days = (int) ($this->aiSettings()->read()['history_days'] ?? 30);
        $maxAge = max(1, $days) * DAY_IN_SECONDS;

        if (null !== $repository) {
            return new ConversationStore($repository, $metaKey . '_active', null, $maxAge);
        }
        if (\in_array(Tables::CONVERSATIONS, $this->tables()->wanted(), true)) {
            return new ConversationStore(new TableConversationRepository($this->tables(), new UserMetaConversationRepository($metaKey)), $metaKey . '_active', null, $maxAge);
        }

        return new ConversationStore(new UserMetaConversationRepository($metaKey), $metaKey . '_active', 'current', $maxAge);
    }

    /** The in-admin assistant. */
    public function assistant(?callable $engineFactory = null): Assistant
    {
        return new Assistant(
            $this->context,
            $this->aiSettings(),
            $this->runner(),
            $this->registry(),
            $this->conversations(),
            new RateLimiter($this->context->optionPrefix()),
            $engineFactory ?? $this->engineFactory,
            $this->attachments()
        );
    }

    /** Files attached to assistant messages; null unless the plugin ships the assistant with attachments. */
    public function attachments(): ?AttachmentStore
    {
        if (!$this->context->provides(PluginContext::FEATURE_ASSISTANT) || !$this->context->provides(PluginContext::FEATURE_ATTACHMENTS)) {
            return null;
        }

        return $this->attachmentStore ??= new AttachmentStore($this->context);
    }

    /** @internal init */
    public function onAttachmentsInit(): void
    {
        (new Lifecycle($this))->onAttachmentsInit();
    }

    /** @internal the hourly purge */
    public function onPurgeAttachments(): void
    {
        (new Lifecycle($this))->onPurgeAttachments();
    }

    /** `{option_prefix}aikit_attachments_purge`: the cron hook of the attachment purge. */
    public function attachmentsPurgeHook(): string
    {
        return $this->context->optionName('aikit_attachments_purge');
    }

    /**
     * Test seam: the engine every assistant() built without one should use (the
     * REST controllers build their own assistants).
     *
     * @param null|callable(): ?\BitApps\AiKit\Ai\Engine\EngineInterface $engineFactory
     */
    public function useEngineFactory(?callable $engineFactory): void
    {
        $this->engineFactory = $engineFactory;
    }

    /**
     * What the admin UI needs before its first request: where the REST routes
     * are, the nonce, who the user is and what they may do, which features this
     * plugin ships and has on, and the plugin's name and brand. The host plugin
     * localizes it under its own script variable; `uiBootstrapHook` may add keys.
     *
     * @return array<string, mixed>
     */
    public function uiBootstrap(): array
    {
        return (new AdminState($this))->uiBootstrap();
    }

    /**
     * What the assistant settings screen shows: settings (keys as hints only),
     * which engine would answer, whether WordPress's own AI Client is set up,
     * and the tools under the assistant's access level.
     *
     * @return array<string, mixed>
     */
    public function assistantState(): array
    {
        return (new AdminState($this))->assistantState();
    }

    /** The Abilities API mirror of this plugin's tools. */
    public function abilities(): AbilitiesBridge
    {
        if (null === $this->abilities) {
            $settings = $this->features->settings(FeatureFlags::MCP_SERVER);
            $this->abilities = new AbilitiesBridge($this->context, $this->registry(), $this->runner(), [
                'expose_on_default_server' => !empty($settings['expose_on_default_server']),
                'show_in_rest'             => !empty($settings['abilities_in_rest']),
            ]);
        }

        return $this->abilities;
    }

    /** Whether the tools are mirrored into the Abilities API: see Kit\AbilitiesMirror. */
    public function mirrorsAbilities(): bool
    {
        return (new AbilitiesMirror($this))->mirrorsAbilities();
    }

    /** @internal wp_abilities_api_categories_init */
    public function onAbilityCategoriesInit(): void
    {
        (new AbilitiesMirror($this))->onAbilityCategoriesInit();
    }

    /** @internal wp_abilities_api_init */
    public function onAbilitiesInit(): void
    {
        (new AbilitiesMirror($this))->onAbilitiesInit();
    }

    /**
     * @internal mcp_adapter_init
     *
     * @param mixed $adapter
     */
    public function onMcpAdapterInit($adapter = null): void
    {
        (new AbilitiesMirror($this))->onMcpAdapterInit($adapter);
    }

    private function piiMasker(): ?PiiMasker
    {
        $classifier = $this->context->piiClassifier();

        return null !== $classifier ? new PiiMasker($classifier) : null;
    }
}
