<?php

namespace BitApps\AiKit\Kit;

use BitApps\AiKit\Ai\Attachments\AttachmentStore;
use BitApps\AiKit\Ai\Engine\CoreAiClientEngine;
use BitApps\AiKit\Ai\Engine\EngineInterface;
use BitApps\AiKit\Ai\Engine\SupportsAttachments;
use BitApps\AiKit\Contract;
use BitApps\AiKit\Kit;
use BitApps\AiKit\Mcp\OAuth\OAuthEndpoints;
use BitApps\AiKit\Mcp\Transport\HttpTransport;
use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Settings\FeatureFlags;
use BitApps\AiKit\Settings\RequirementsReport;
use BitApps\AiKit\Tools\AccessPolicy;
use BitApps\AiKit\Tools\ToolContext;

/**
 * What the admin UI and the settings screens read.
 *
 * @internal plugins call these through Kit
 */
final class AdminState
{
    private PluginContext $context;

    private FeatureFlags $features;

    public function __construct(private Kit $kit)
    {
        $this->context = $kit->context();
        $this->features = $kit->features();
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
        $settings = $this->kit->mcpSettings()->read();

        return [
            'settings'   => $settings,
            'forced_off' => [
                'mcp' => $this->features->isForcedOff(FeatureFlags::MCP_SERVER),
                'ai'  => $this->features->isForcedOff(FeatureFlags::ASSISTANT),
            ],
            'endpoint'     => rest_url($this->context->restNamespace() . HttpTransport::ROUTE),
            'tools'        => $this->toolRows(AccessPolicy::fromSettings($settings)),
            'requirements' => RequirementsReport::checks(),
            'recent'       => $this->recentOutsideCalls(20),
            'api_keys'     => [
                'enabled'    => $this->features->isEnabled(FeatureFlags::API_KEYS),
                'forced_off' => $this->features->isForcedOff(FeatureFlags::API_KEYS),
                'keys'       => $this->kit->apiKeys()->list(),
            ],
            'oauth' => [
                'forced_off'        => $this->features->isForcedOff(FeatureFlags::OAUTH),
                'enabled'           => $this->kit->oauthEnabled(),
                'pretty_permalinks' => OAuthEndpoints::prettyPermalinks(),
                'connections'       => $this->kit->oauthConnections(),
            ],
        ];
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
            $resolved = $this->kit->assistant()->resolveEngine();
            $engineObject = $resolved['engine'];
            $engine = null !== $engineObject ? ['key' => $engineObject->key(), 'label' => $engineObject->label()] : null;
            $reason = $resolved['reason'];
        }
        $attachments = $this->kit->attachments();
        $canUpload = $canUse && \function_exists('current_user_can') && current_user_can('upload_files');

        $brandColor = $this->context->brand()['color'] ?? null;
        $icons = $this->context->icons();
        $bootstrap = [
            'contract' => Contract::CONTRACT_VERSION,
            'slug'     => $this->context->slug(),
            'name'     => $this->context->name(),
            'brand'    => [
                'name'  => $this->context->name(),
                'color' => \is_string($brandColor) ? $brandColor : null,
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
        $view = $this->kit->aiSettings()->publicView();
        $resolved = $this->kit->assistant()->resolveEngine();

        return $view + [
            'forced_off'       => $this->features->isForcedOff(FeatureFlags::ASSISTANT),
            'wordpress_ai'     => CoreAiClientEngine::available(),
            'wordpress_ai_api' => \function_exists('wp_ai_client_prompt'),
            'engine'           => null !== $resolved['engine'] ? ['key' => $resolved['engine']->key(), 'label' => $resolved['engine']->label()] : null,
            'engine_reason'    => $resolved['reason'],
            // Saved keys are encrypted with the auth salt; one kept in the database is weaker.
            'salts_in_config' => \defined('AUTH_KEY') && \defined('AUTH_SALT') && '' !== (string) AUTH_KEY && 'put your unique phrase here' !== AUTH_KEY,
            'tools'           => $this->toolRows(AccessPolicy::fromSettings($view['settings'])),
        ];
    }

    /**
     * Every tool as a settings screen lists it, and whether the policy allows it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function toolRows(AccessPolicy $policy): array
    {
        $tools = [];
        foreach ($this->kit->registry()->all() as $tool) {
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

        return $tools;
    }

    /**
     * The last calls from outside the assistant (MCP, Abilities API, WP-CLI) for
     * the MCP screen's summary; the assistant shows its own on its settings page.
     * Read from a wider window so a busy assistant does not empty the list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentOutsideCalls(int $limit): array
    {
        $rows = array_filter(
            $this->kit->auditLogger()->recent($limit * 10),
            static fn ($row) => ToolContext::CHANNEL_ASSISTANT !== ($row['channel'] ?? '')
        );

        return \array_slice(array_values($rows), 0, $limit);
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
}
