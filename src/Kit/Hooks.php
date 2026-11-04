<?php

namespace BitApps\AiKit\Kit;

use BitApps\AiKit\Contract;
use BitApps\AiKit\Kit;
use BitApps\AiKit\Mcp\OAuth\OAuthServer;
use BitApps\AiKit\Mcp\OAuth\OptionsOAuthStorage;
use BitApps\AiKit\Mcp\OAuth\TokenService;
use BitApps\AiKit\Mcp\Transport\HttpTransport;
use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Rest\RestRoutes;
use BitApps\AiKit\Settings\FeatureFlags;

/**
 * What the kit hangs on WordPress, and the request-level callbacks behind it.
 *
 * The callbacks stay registered as `[$kit, 'onInit']` and so on: those are public
 * in 1.x, so a plugin may have removed one by that name.
 *
 * @internal plugins call these through Kit
 */
final class Hooks
{
    private PluginContext $context;

    public function __construct(private Kit $kit)
    {
        $this->context = $kit->context();
    }

    /**
     * Hooks only: nothing below loads a tool or reads an option until its hook fires.
     * A plugin that does not ship MCP (PluginContext `features`) gets none of the MCP
     * hooks; the assistant needs none of its own (the plugin calls it).
     *
     * Core fires the Abilities init actions lazily, on the first registry access.
     * When this kit boots after that already happened, it registers at once, without
     * ever calling the registry's get_instance(), which would re-fire the action from
     * inside a hook.
     */
    public function register(): void
    {
        $kit = $this->kit;

        if (null !== $kit->attachments()) {
            add_action('init', [$kit, 'onAttachmentsInit'], 1, 0);
            add_action($kit->attachmentsPurgeHook(), [$kit, 'onPurgeAttachments'], 10, 0);
        }
        if ([] !== $kit->tables()->wanted()) {
            add_action('init', [$kit, 'onStorageInit'], 1, 0);
            add_action($kit->purgeHook(), [$kit, 'onPurge'], 10, 0);
        }
        if ($this->context->provides(PluginContext::FEATURE_REST_UI) || $this->context->provides(PluginContext::FEATURE_MCP)) {
            add_action('rest_api_init', [$kit, 'onRestApiInit'], 20, 0);
        }
        if ($this->context->provides(PluginContext::FEATURE_ASSISTANT)) {
            // Before WordPress removes the account: its chats and files go with it.
            add_action('delete_user', [$kit, 'onUserDeleting'], 10, 1);
            add_filter('wp_privacy_personal_data_exporters', [$kit, 'onPrivacyExporters'], 10, 1);
            add_filter('wp_privacy_personal_data_erasers', [$kit, 'onPrivacyErasers'], 10, 1);
        }
        if (!$this->context->provides(PluginContext::FEATURE_MCP)) {
            return;
        }

        add_action('deleted_user', [$kit, 'onUserDeleted'], 10, 1);
        add_action('wp_abilities_api_categories_init', [$kit, 'onAbilityCategoriesInit'], 10, 0);
        add_action('wp_abilities_api_init', [$kit, 'onAbilitiesInit'], 10, 0);
        add_action('mcp_adapter_init', [$kit, 'onMcpAdapterInit'], 10, 1);
        add_action('init', [$kit, 'onInit'], 0, 0);
        $consent = Contract::consentAction($this->context->slug());
        add_action('admin_post_' . $consent, [$kit, 'onConsent'], 10, 0);
        add_action('admin_post_nopriv_' . $consent, [$kit, 'onConsent'], 10, 0);

        if (did_action('wp_abilities_api_categories_init')) {
            (new AbilitiesMirror($kit))->registerLateCategory();
        }
        if (did_action('wp_abilities_api_init')) {
            (new AbilitiesMirror($kit))->registerLateAbilities();
        }
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

        $oauth = $this->kit->oauth();
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
        $oauth = $this->kit->oauth();
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
            RestRoutes::register($namespace, fn () => $this->kit, $this->context);
        }

        if (!$this->context->provides(PluginContext::FEATURE_MCP) || !$this->kit->features()->isEnabled(FeatureFlags::MCP_SERVER)) {
            return;
        }

        // Routes only: the server, its tools and the OAuth stores are built when a request reaches them.
        HttpTransport::registerLazily($namespace, fn () => $this->kit->transport());
        add_filter('rest_post_dispatch', [$this->kit, 'onRestPostDispatch'], 10, 3);

        if ($this->kit->oauthEnabled()) {
            OAuthServer::registerLazily($namespace, fn () => $this->kit->oauth());
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

        return $this->kit->transport()->addChallenge($result, $server, $request);
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
            $this->kit->apiKeys()->revokeAllForUser($userId);
            // Straight through the storage: OAuth may be off now and switched on again later.
            $storage = $this->context->oauthStorage() ?? new OptionsOAuthStorage($this->context);
            (new TokenService($storage, $this->context->optionName('')))->revokeAllForUser($userId);
        }
    }

    /**
     * delete_user: the account's assistant chats and attached files.
     *
     * @param mixed $userId
     */
    public function onUserDeleting($userId = 0): void
    {
        $this->kit->privacy()->forget((int) $userId);
    }

    /**
     * wp_privacy_personal_data_exporters: the assistant's chats in Tools → Export Personal Data.
     *
     * @param mixed $exporters
     *
     * @return mixed
     */
    public function onPrivacyExporters($exporters)
    {
        if (!\is_array($exporters)) {
            return $exporters;
        }
        $exporters[$this->context->slug() . '-ai-chats'] = [
            'exporter_friendly_name' => sprintf('%s AI assistant chats', $this->context->name()),
            'callback'               => fn ($email, $page = 1) => $this->kit->privacy()->export((string) $email, (int) $page),
        ];

        return $exporters;
    }

    /**
     * wp_privacy_personal_data_erasers: the assistant's chats and files in Tools → Erase Personal Data.
     *
     * @param mixed $erasers
     *
     * @return mixed
     */
    public function onPrivacyErasers($erasers)
    {
        if (!\is_array($erasers)) {
            return $erasers;
        }
        $erasers[$this->context->slug() . '-ai-chats'] = [
            'eraser_friendly_name' => sprintf('%s AI assistant chats', $this->context->name()),
            'callback'             => fn ($email) => $this->kit->privacy()->erase((string) $email),
        ];

        return $erasers;
    }
}
