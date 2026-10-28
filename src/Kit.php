<?php

namespace BitApps\AiKit;

use BitApps\AiKit\Abilities\AbilitiesBridge;
use BitApps\AiKit\Ai\Assistant;
use BitApps\AiKit\Ai\Attachments\AttachmentStore;
use BitApps\AiKit\Ai\ConversationRepositoryInterface;
use BitApps\AiKit\Ai\ConversationStore;
use BitApps\AiKit\Ai\Engine\CoreAiClientEngine;
use BitApps\AiKit\Ai\Engine\EngineInterface;
use BitApps\AiKit\Ai\Engine\SupportsAttachments;
use BitApps\AiKit\Ai\UserMetaConversationRepository;
use BitApps\AiKit\Audit\AuditLoggerInterface;
use BitApps\AiKit\Audit\NullAuditLogger;
use BitApps\AiKit\Audit\OptionsAuditLogger;
use BitApps\AiKit\Audit\SearchableAuditLog;
use BitApps\AiKit\Mcp\Auth\ApiKeyService;
use BitApps\AiKit\Mcp\Auth\BearerResolverInterface;
use BitApps\AiKit\Mcp\OAuth\OAuthEndpoints;
use BitApps\AiKit\Mcp\OAuth\OAuthServer;
use BitApps\AiKit\Mcp\OAuth\OAuthStorageInterface;
use BitApps\AiKit\Mcp\OAuth\OptionsOAuthStorage;
use BitApps\AiKit\Mcp\OAuth\TokenService;
use BitApps\AiKit\Mcp\Server\McpServer;
use BitApps\AiKit\Mcp\Transport\HttpTransport;
use BitApps\AiKit\Mcp\Transport\WpMcpAdapterBridge;
use BitApps\AiKit\Rest\RestRoutes;
use BitApps\AiKit\Security\PiiMasker;
use BitApps\AiKit\Security\RateLimiter;
use BitApps\AiKit\Security\SecretRedactor;
use BitApps\AiKit\Settings\AiSettings;
use BitApps\AiKit\Settings\FeatureFlags;
use BitApps\AiKit\Settings\McpSettings;
use BitApps\AiKit\Settings\RequirementsReport;
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
 */
final class Kit
{
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
        $kit->registerHooks();

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

        $service = $this->context->service('audit');
        if ($service instanceof AuditLoggerInterface) {
            return $service;
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
        if (\function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook($this->purgeHook());
        }
    }

    /** init: keep the tables current and the daily purge scheduled (storage `tables` only). */
    public function onStorageInit(): void
    {
        $this->tables()->maybeInstall();
        if (\function_exists('wp_next_scheduled') && !wp_next_scheduled($this->purgeHook())) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', $this->purgeHook());
        }
    }

    /** The daily purge: activity older than its retention, conversations untouched for longer than the history keeps them. */
    public function onPurge(): void
    {
        $logger = $this->auditLogger();
        if ($logger instanceof TableAuditLogger) {
            $logger->purgeOlderThan((int) ($this->mcpSettings()->read()['audit_retention_days'] ?? 90));
        }
        if (\in_array(Tables::CONVERSATIONS, $this->tables()->wanted(), true)) {
            $days = max(1, (int) ($this->aiSettings()->read()['history_days'] ?? 30));
            $this->conversations()->repository()->purgeOlderThan($days * DAY_IN_SECONDS);
        }
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

        if (null === $this->oauth) {
            $storage = $this->context->service('oauth_storage');
            $this->oauth = new OAuthServer(
                $this->context,
                rest_url($this->context->restNamespace() . HttpTransport::ROUTE),
                $storage instanceof OAuthStorageInterface ? $storage : new OptionsOAuthStorage($this->context),
                new RateLimiter($this->context->optionPrefix()),
                fn () => AccessPolicy::fromSettings($this->features->settings(FeatureFlags::MCP_SERVER))->level(),
                fn () => [] !== array_filter($this->registry()->all(), static fn ($tool) => $tool->containsPersonalData()),
                fn () => \count($this->runner()->listTools(new ToolContext(ToolContext::CHANNEL_MCP, (int) get_current_user_id())))
            );
        }

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

    /**
     * init (priority 0): answer our own two discovery documents by exact path,
     * before WordPress routing — other MCP plugins register greedy
     * `/.well-known/` rewrite rules that would otherwise swallow them.
     */
    public function onInit(): void
    {
        $uri = isset($_SERVER['REQUEST_URI']) && \is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (!str_contains($uri, '/.well-known/oauth-')) {
            return;
        }

        $oauth = $this->oauth();
        $document = $oauth?->wellKnownDocument((string) parse_url($uri, PHP_URL_PATH));
        if (null === $document) {
            return;
        }

        status_header(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('Access-Control-Allow-Origin: *');
        echo wp_json_encode($document);

        exit;
    }

    /** admin_post(_nopriv)_<slug>_mcp_authorize: the consent screen. */
    public function onConsent(): void
    {
        $oauth = $this->oauth();
        if (null === $oauth) {
            wp_die('Connecting AI apps is switched off on this site.', '', ['response' => 404]);
        }

        $method = isset($_SERVER['REQUEST_METHOD']) && \is_string($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        // phpcs:ignore WordPress.Security.NonceVerification -- the GET is a cross-site OAuth redirect; the POST is nonce-checked by ConsentScreen.
        $outcome = $oauth->consentScreen()->respond($method, wp_unslash($_GET), wp_unslash($_POST), (int) get_current_user_id());

        if ('redirect' === $outcome['type']) {
            // The target is a login URL or a redirect URI already matched against the client's registration.
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
            wp_redirect($outcome['location']);

            exit;
        }

        status_header($outcome['status'] ?? 200);
        foreach ($oauth->consentScreen()::headers() as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $outcome['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped by ConsentScreen

        exit;
    }

    /**
     * rest_api_init: register the MCP route only when the site switched the
     * server on, so a disabled server has no endpoint at all.
     */
    public function onRestApiInit(): void
    {
        $namespace = $this->context->restNamespace();

        // The admin UI's routes: controllers are built when a request reaches one.
        if ($this->context->provides(PluginContext::FEATURE_REST_UI)) {
            RestRoutes::register($namespace, fn () => $this, $this->context);
        }

        if (!$this->context->provides(PluginContext::FEATURE_MCP) || !$this->features->isEnabled(FeatureFlags::MCP_SERVER)) {
            return;
        }

        // Routes only: the server, its tools and the OAuth stores are built when a request reaches them.
        HttpTransport::registerLazily($namespace, fn () => $this->transport());
        add_filter('rest_post_dispatch', [$this, 'onRestPostDispatch'], 10, 3);

        if ($this->oauthEnabled()) {
            OAuthServer::registerLazily($namespace, fn () => $this->oauth());
        }
    }

    /**
     * rest_post_dispatch: the WWW-Authenticate challenge on the MCP endpoint's
     * 401s. Every other response passes through without building the transport.
     *
     * @param mixed $result
     * @param mixed $server
     * @param mixed $request
     *
     * @return mixed
     */
    public function onRestPostDispatch($result, $server = null, $request = null)
    {
        if (!$result instanceof \WP_REST_Response || 401 !== $result->get_status() || !$request instanceof \WP_REST_Request
            || $request->get_route() !== '/' . $this->context->restNamespace() . HttpTransport::ROUTE) {
            return $result;
        }

        return $this->transport()->addChallenge($result, $server, $request);
    }

    /**
     * deleted_user: a deleted account's API keys stop working at once.
     *
     * @param mixed $userId
     */
    public function onUserDeleted($userId = 0): void
    {
        $userId = (int) $userId;
        if ($userId > 0) {
            $this->apiKeys()->revokeAllForUser($userId);
            // Straight through the storage: OAuth may be off now and switched on again later.
            $storage = $this->context->service('oauth_storage');
            (new TokenService($storage instanceof OAuthStorageInterface ? $storage : new OptionsOAuthStorage($this->context), $this->context->optionName('')))->revokeAllForUser($userId);
        }
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
        $settings = $this->mcpSettings()->read();
        $policy = AccessPolicy::fromSettings($settings);

        $tools = [];
        foreach ($this->registry()->all() as $tool) {
            $tools[] = [
                'name'        => $tool->name(),
                'title'       => $tool->title(),
                'description' => $tool->description(),
                'access'      => $tool->accessLevel(),
                'tier'        => $tool->tier(),
                'personal'    => $tool->containsPersonalData(),
                'enabled'     => $policy->allows($tool),
            ];
        }

        return [
            'settings'   => $settings,
            'forced_off' => [
                'mcp' => $this->features->isForcedOff(FeatureFlags::MCP_SERVER),
                'ai'  => $this->features->isForcedOff(FeatureFlags::ASSISTANT),
            ],
            'endpoint'     => rest_url($this->context->restNamespace() . HttpTransport::ROUTE),
            'tools'        => $tools,
            'requirements' => RequirementsReport::checks(),
            'recent'       => $this->auditLogger()->recent(20),
            'api_keys'     => [
                'enabled'    => $this->features->isEnabled(FeatureFlags::API_KEYS),
                'forced_off' => $this->features->isForcedOff(FeatureFlags::API_KEYS),
                'keys'       => $this->apiKeys()->list(),
            ],
            'oauth' => [
                'forced_off'        => $this->features->isForcedOff(FeatureFlags::OAUTH),
                'enabled'           => $this->oauthEnabled(),
                'pretty_permalinks' => OAuthEndpoints::prettyPermalinks(),
                'connections'       => $this->oauthConnections(),
            ],
        ];
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
        $repository = $this->context->service('conversations');
        $days = (int) ($this->aiSettings()->read()['history_days'] ?? 30);
        $maxAge = max(1, $days) * DAY_IN_SECONDS;

        if ($repository instanceof ConversationRepositoryInterface) {
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
        $userId = (int) get_current_user_id();
        $canUse = $userId > 0 && $this->context->canUse($userId);
        $assistantShipped = $this->context->provides(PluginContext::FEATURE_ASSISTANT) && !$this->features->isForcedOff(FeatureFlags::ASSISTANT);
        $assistantOn = $assistantShipped && $this->features->isEnabled(FeatureFlags::ASSISTANT);

        $engine = null;
        $reason = null;
        $engineObject = null;
        if ($assistantOn && $canUse) {
            $resolved = $this->assistant()->resolveEngine();
            $engineObject = $resolved['engine'];
            $engine = null !== $engineObject ? ['key' => $engineObject->key(), 'label' => $engineObject->label()] : null;
            $reason = $resolved['reason'];
        }
        $attachments = $this->attachments();
        $canUpload = $canUse && \function_exists('current_user_can') && current_user_can('upload_files');

        $brand = $this->context->service('brand');
        $icons = $this->context->icons();
        $bootstrap = [
            'contract' => Contract::CONTRACT_VERSION,
            'slug'     => $this->context->slug(),
            'name'     => $this->context->name(),
            'brand'    => [
                'name'  => $this->context->name(),
                'color' => \is_array($brand) && \is_string($brand['color'] ?? null) ? $brand['color'] : null,
                'icon'  => $icons[0]['src'] ?? null,
            ],
            'restBase'  => $this->context->provides(PluginContext::FEATURE_REST_UI) ? rest_url($this->context->restNamespace() . Contract::REST_BASE) : null,
            'nonce'     => wp_create_nonce('wp_rest'),
            'canUse'    => $canUse,
            'canManage' => $canUse && $this->context->canManage($userId),
            'canUpload' => $canUpload,
            'features'  => [
                'assistant'   => $assistantShipped,
                'mcp'         => $this->context->provides(PluginContext::FEATURE_MCP) && !$this->features->isForcedOff(FeatureFlags::MCP_SERVER),
                'restUi'      => $this->context->provides(PluginContext::FEATURE_REST_UI),
                'history'     => $this->context->provides(PluginContext::FEATURE_HISTORY),
                'audit'       => $this->context->provides(PluginContext::FEATURE_AUDIT),
                'oauth'       => $this->context->provides(PluginContext::FEATURE_OAUTH),
                'apiKeys'     => $this->context->provides(PluginContext::FEATURE_API_KEYS),
                'abilities'   => $this->context->provides(PluginContext::FEATURE_ABILITIES),
                'mcpAdapter'  => $this->context->provides(PluginContext::FEATURE_MCP_ADAPTER),
                'attachments' => null !== $attachments,
                'stream'      => $this->context->provides(PluginContext::FEATURE_STREAM),
            ],
            'assistantOn'  => $assistantOn,
            'mcpOn'        => $this->context->provides(PluginContext::FEATURE_MCP) && $this->features->isEnabled(FeatureFlags::MCP_SERVER),
            'engine'       => $engine,
            'engineReason' => $reason,
            'attachments'  => null !== $attachments && $canUpload ? self::attachmentRules($attachments, $engineObject) : null,
            'user'         => ['id' => $userId, 'locale' => \function_exists('get_user_locale') ? (string) get_user_locale() : 'en_US'],
            'settingsUrl'  => $this->context->url('settings'),
            'docsUrl'      => $this->context->url('docs'),
        ];

        $filtered = apply_filters(Contract::uiBootstrapHook($this->context->slug()), $bootstrap);

        return \is_array($filtered) ? $filtered : $bootstrap;
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
        $view = $this->aiSettings()->publicView();
        $policy = AccessPolicy::fromSettings($view['settings']);
        $resolved = $this->assistant()->resolveEngine();

        $tools = [];
        foreach ($this->registry()->all() as $tool) {
            $tools[] = [
                'name'        => $tool->name(),
                'title'       => $tool->title(),
                'description' => $tool->description(),
                'access'      => $tool->accessLevel(),
                'tier'        => $tool->tier(),
                'personal'    => $tool->containsPersonalData(),
                'enabled'     => $policy->allows($tool),
            ];
        }

        return $view + [
            'forced_off'       => $this->features->isForcedOff(FeatureFlags::ASSISTANT),
            'wordpress_ai'     => CoreAiClientEngine::available(),
            'wordpress_ai_api' => \function_exists('wp_ai_client_prompt'),
            'engine'           => null !== $resolved['engine'] ? ['key' => $resolved['engine']->key(), 'label' => $resolved['engine']->label()] : null,
            'engine_reason'    => $resolved['reason'],
            // Saved keys are encrypted with the auth salt; one kept in the database is weaker.
            'salts_in_config' => \defined('AUTH_KEY') && \defined('AUTH_SALT') && '' !== (string) AUTH_KEY && 'put your unique phrase here' !== AUTH_KEY,
            'tools'           => $tools,
        ];
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

    /** Files attached to assistant messages; null unless the plugin ships the assistant with attachments. */
    public function attachments(): ?AttachmentStore
    {
        if (!$this->context->provides(PluginContext::FEATURE_ASSISTANT) || !$this->context->provides(PluginContext::FEATURE_ATTACHMENTS)) {
            return null;
        }

        return $this->attachmentStore ??= new AttachmentStore($this->context);
    }

    /** init: keep the hourly removal of expired attachments scheduled. */
    public function onAttachmentsInit(): void
    {
        if (\function_exists('wp_next_scheduled') && !wp_next_scheduled($this->attachmentsPurgeHook())) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', $this->attachmentsPurgeHook());
        }
    }

    /** The hourly purge of attachments older than their time to live. */
    public function onPurgeAttachments(): void
    {
        $this->attachments()?->purgeExpired();
    }

    /** `{option_prefix}aikit_attachments_purge`: the cron hook of the attachment purge. */
    public function attachmentsPurgeHook(): string
    {
        return $this->context->optionName('aikit_attachments_purge');
    }

    /**
     * Mirror tools into the Abilities API (and the MCP Adapter) only while the
     * MCP server is on, and only when something will use them: the "offer as
     * WordPress abilities" setting, or the MCP Adapter plugin. Mirroring needs
     * every tool definition, and WordPress builds the abilities registry on REST
     * requests, so mirroring nobody reads would load the plugin's tools on each.
     */
    public function mirrorsAbilities(): bool
    {
        // Only with the MCP server on: the assistant alone must not open another way in.
        if (!AbilitiesBridge::available()
            || !$this->features->isEnabled(FeatureFlags::ABILITIES_BRIDGE)
            || !$this->features->isEnabled(FeatureFlags::MCP_SERVER)) {
            return false;
        }

        return !empty($this->mcpSettings()->read()['abilities_in_rest'])
            || ($this->features->isEnabled(FeatureFlags::ADAPTER_BRIDGE) && WpMcpAdapterBridge::available());
    }

    /** wp_abilities_api_categories_init */
    public function onAbilityCategoriesInit(): void
    {
        if ($this->mirrorsAbilities()) {
            $this->abilities()->registerCategory();
        }
    }

    /** wp_abilities_api_init */
    public function onAbilitiesInit(): void
    {
        if ($this->mirrorsAbilities()) {
            $this->abilities()->registerAbilities();
        }
    }

    /**
     * mcp_adapter_init.
     *
     * @param mixed $adapter
     */
    public function onMcpAdapterInit($adapter = null): void
    {
        if (!$this->mirrorsAbilities() || !$this->features->isEnabled(FeatureFlags::ADAPTER_BRIDGE)) {
            return;
        }

        (new WpMcpAdapterBridge($this->context, $this->abilities()))->createServer($adapter);
    }

    /**
     * What the composer checks before uploading: the file types, how many and how
     * large, and which kinds the current engine reads (text always).
     *
     * @return array<string, mixed>
     */
    private static function attachmentRules(AttachmentStore $store, ?EngineInterface $engine): array
    {
        $limits = $store->limits();
        $kinds = [AttachmentStore::KIND_TEXT, AttachmentStore::KIND_DOCX];
        $maxTotal = (int) $limits['max_total_bytes'];
        if ($engine instanceof SupportsAttachments) {
            $kinds = array_merge($kinds, $engine->attachmentKinds());
            if ($engine->maxAttachmentBytes() > 0) {
                $maxTotal = min($maxTotal, $engine->maxAttachmentBytes());
            }
        }
        $accept = [];
        foreach (AttachmentStore::TYPES as $ext => $type) {
            if (\in_array($type['kind'], $kinds, true)) {
                $accept[] = '.' . $ext;
            }
        }

        return [
            'accept'        => implode(',', $accept),
            'kinds'         => array_values(array_unique($kinds)),
            'maxFiles'      => (int) $limits['max_files'],
            'maxTotalBytes' => $maxTotal,
            'maxBytes'      => (array) $limits['max_bytes'],
            'ttlHours'      => (int) round((int) $limits['ttl'] / HOUR_IN_SECONDS),
        ];
    }

    private function piiMasker(): ?PiiMasker
    {
        $classifier = $this->context->service('pii_classifier');

        return \is_callable($classifier) ? new PiiMasker($classifier) : null;
    }

    /**
     * Only hook registrations here: nothing is read from the database and
     * nothing is instantiated until a hook fires.
     *
     * Core fires the Abilities init actions lazily, on the first registry access.
     * When this kit boots after that already happened, it registers at once —
     * without ever calling the registry's get_instance(), which would re-fire the
     * action from inside a hook.
     */
    /**
     * Hooks only: nothing below loads a class or reads an option until its hook
     * fires. A plugin that does not ship MCP (PluginContext `features`) gets none
     * of the MCP hooks; the assistant needs none of its own (the plugin calls it).
     */
    private function registerHooks(): void
    {
        if (null !== $this->attachments()) {
            add_action('init', [$this, 'onAttachmentsInit'], 1, 0);
            add_action($this->attachmentsPurgeHook(), [$this, 'onPurgeAttachments'], 10, 0);
        }
        if ([] !== $this->tables()->wanted()) {
            add_action('init', [$this, 'onStorageInit'], 1, 0);
            add_action($this->purgeHook(), [$this, 'onPurge'], 10, 0);
        }
        if ($this->context->provides(PluginContext::FEATURE_REST_UI) || $this->context->provides(PluginContext::FEATURE_MCP)) {
            add_action('rest_api_init', [$this, 'onRestApiInit'], 20, 0);
        }
        if (!$this->context->provides(PluginContext::FEATURE_MCP)) {
            return;
        }

        add_action('deleted_user', [$this, 'onUserDeleted'], 10, 1);
        add_action('wp_abilities_api_categories_init', [$this, 'onAbilityCategoriesInit'], 10, 0);
        add_action('wp_abilities_api_init', [$this, 'onAbilitiesInit'], 10, 0);
        add_action('mcp_adapter_init', [$this, 'onMcpAdapterInit'], 10, 1);
        add_action('init', [$this, 'onInit'], 0, 0);
        $consent = Contract::consentAction($this->context->slug());
        add_action('admin_post_' . $consent, [$this, 'onConsent'], 10, 0);
        add_action('admin_post_nopriv_' . $consent, [$this, 'onConsent'], 10, 0);

        if (did_action('wp_abilities_api_categories_init')) {
            $this->registerLateCategory();
        }
        if (did_action('wp_abilities_api_init')) {
            $this->registerLateAbilities();
        }
    }

    private function registerLateCategory(): void
    {
        if (!$this->mirrorsAbilities() || !class_exists(\WP_Ability_Categories_Registry::class)) {
            return;
        }

        $slug = Contract::abilityCategory($this->context->slug());
        if (\function_exists('wp_has_ability_category') && wp_has_ability_category($slug)) {
            return;
        }

        $registry = \WP_Ability_Categories_Registry::get_instance();
        if (null !== $registry) {
            $registry->register($slug, ['label' => $this->context->name(), 'description' => 'Tools provided by ' . $this->context->name() . '.']);
        }
    }

    private function registerLateAbilities(): void
    {
        if (!$this->mirrorsAbilities() || !class_exists(\WP_Abilities_Registry::class)) {
            return;
        }

        $registry = \WP_Abilities_Registry::get_instance();
        if (null === $registry) {
            return;
        }

        foreach ($this->registry()->all() as $tool) {
            $name = $tool->abilityName($this->context->slug());
            if ($tool->isDestructive() || (\function_exists('wp_has_ability') && wp_has_ability($name))) {
                continue;
            }
            $registry->register($name, $this->abilities()->abilityArgs($tool));
        }
    }
}
