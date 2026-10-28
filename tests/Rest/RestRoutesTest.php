<?php

namespace BitApps\AiKit\Tests\Rest;

use BitApps\AiKit\Contract;
use BitApps\AiKit\Kit;
use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Rest\AssistantController;
use BitApps\AiKit\Rest\RestRoutes;
use BitApps\AiKit\Tests\Ai\MemoryConversations;
use BitApps\AiKit\Tests\Ai\ScriptedEngine;
use BitApps\AiKit\Tests\TestCase;
use BitApps\AiKit\Tools\ToolContext;

/**
 * The kit's admin REST routes (`<ns>/ai/*`): registration, permissions, the
 * chat round trip with the plugin's context and view filters, conversation
 * management, settings, the activity log, API keys and connections.
 *
 * @internal
 */
final class RestRoutesTest extends TestCase
{
    private const MANAGER = 5;

    private const MEMBER = 6;

    private const OUTSIDER = 8;

    private ScriptedEngine $engine;

    private MemoryConversations $conversations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grant(self::MANAGER, 'manage_options');
        update_option('bit_demo_ai_settings', ['enabled' => true, 'access_level' => 'write']);
        update_option('bit_demo_mcp_settings', ['enabled' => true, 'access_level' => 'write', 'api_keys' => true]);
        $this->engine = new ScriptedEngine();
        $this->conversations = new MemoryConversations();
        add_filter(Contract::toolProvidersHook('bit-demo'), static fn ($providers) => array_merge($providers, [[
            ['name' => 'list-forms', 'title' => 'List forms', 'description' => 'Forms.', 'annotations' => ['readonly' => true], 'handler' => static fn () => ['items' => [['id' => 7]]]],
        ]]), 10, 1);
    }

    public function testRoutesRegisterOnlyForPluginsThatOptIn(): void
    {
        Kit::boot($this->context());
        do_action('rest_api_init');
        self::assertArrayNotHasKey('/bit-demo/v1/ai/assistant/view', \AiKitRest::$routes, 'off by default');

        resetAiKitTestState();
        Kit::reset();
        Kit::boot($this->context(['features' => [PluginContext::FEATURE_REST_UI => true, PluginContext::FEATURE_MCP => false]]));
        do_action('rest_api_init');
        self::assertArrayHasKey('/bit-demo/v1/ai/assistant/view', \AiKitRest::$routes, 'an assistant-only plugin gets the UI routes');
        self::assertArrayHasKey('/bit-demo/v1/ai/settings/assistant', \AiKitRest::$routes);
        self::assertArrayNotHasKey('/bit-demo/v1/ai/settings/mcp', \AiKitRest::$routes, 'no MCP screen routes');
        self::assertArrayNotHasKey('/bit-demo/v1/mcp', \AiKitRest::$routes, 'and no MCP endpoint');
        foreach (\AiKitRest::$routes as $route => $endpoints) {
            foreach ($endpoints as $endpoint) {
                self::assertNotSame('__return_true', $endpoint['permission_callback'], $route . ' is guarded');
            }
        }
    }

    public function testAPluginCanLeaveOutTheActivityLogAndOtherOptionalParts(): void
    {
        $logged = [];
        $logger = new class($logged) implements \BitApps\AiKit\Audit\AuditLoggerInterface {
            public function __construct(private array &$events) {}

            public function record(array $event): void
            {
                $this->events[] = $event;
            }

            public function recent(int $limit = 50): array
            {
                return $this->events;
            }
        };
        $kit = Kit::boot($this->context([
            'features' => [
                PluginContext::FEATURE_REST_UI  => true,
                PluginContext::FEATURE_AUDIT    => false,
                PluginContext::FEATURE_HISTORY  => false,
                PluginContext::FEATURE_OAUTH    => false,
                PluginContext::FEATURE_API_KEYS => false,
            ],
            'services' => ['audit' => $logger],
        ]));
        do_action('rest_api_init');

        foreach (['/mcp/activity', '/assistant/history', '/mcp/api-keys', '/mcp/connections'] as $route) {
            self::assertArrayNotHasKey('/bit-demo/v1/ai' . $route, \AiKitRest::$routes, $route . ' is not registered');
        }
        self::assertArrayHasKey('/bit-demo/v1/ai/assistant/chat', \AiKitRest::$routes, 'the chat stays');
        self::assertArrayHasKey('/bit-demo/v1/ai/settings/mcp', \AiKitRest::$routes, 'and the MCP screen');

        self::assertInstanceOf(\BitApps\AiKit\Audit\NullAuditLogger::class, $kit->auditLogger(), 'off wins over the plugin\'s own logger');
        $kit->auditLogger()->record(['tool' => 'list-forms']);
        self::assertSame([], $logged, 'nothing reaches it');

        $state = $kit->adminState();
        self::assertTrue($state['oauth']['forced_off']);
        self::assertTrue($state['api_keys']['forced_off']);
        self::assertFalse($state['api_keys']['enabled'], 'saved settings cannot switch a left-out part on');

        \AiKitTestState::$currentUser = self::MANAGER;
        $features = $kit->uiBootstrap()['features'];
        self::assertFalse($features['audit']);
        self::assertFalse($features['history']);
        self::assertFalse($features['oauth']);
        self::assertFalse($features['apiKeys']);
        self::assertTrue($features['abilities']);
    }

    public function testPermissionsFollowCanUseAndTheManageCapability(): void
    {
        $this->boot();

        \AiKitTestState::$currentUser = 0;
        self::assertSame(401, $this->permission('GET', '/assistant/view')->get_error_data()['status']);

        \AiKitTestState::$currentUser = self::OUTSIDER;
        self::assertSame(403, $this->permission('GET', '/assistant/view')->get_error_data()['status']);

        \AiKitTestState::$currentUser = self::MEMBER;
        self::assertTrue($this->permission('GET', '/assistant/view'));
        self::assertSame('ai_manage_forbidden', $this->permission('POST', '/settings/assistant')->get_error_code());

        \AiKitTestState::$currentUser = self::MANAGER;
        self::assertTrue($this->permission('POST', '/settings/assistant'));

        // The plugin has the last word.
        add_filter(Contract::restPermissionHook('bit-demo'), static fn ($allowed, $route) => '/assistant/reset' === $route ? false : $allowed, 10, 2);
        self::assertFalse($this->permission('POST', '/assistant/reset'));
        self::assertTrue($this->permission('GET', '/assistant/view'));
    }

    public function testAChatTurnUsesThePluginsContextAndViewFilters(): void
    {
        $this->boot();
        \AiKitTestState::$currentUser = self::MEMBER;
        $this->engine->script = [
            ['text' => 'Looking.', 'tool_calls' => [['id' => 't1', 'name' => 'list-forms', 'input' => []]]],
            ['text' => 'You have form 7.', 'tool_calls' => []],
        ];
        $seen = null;
        add_filter(Contract::chatContextHook('bit-demo'), static function ($context, $userId) use (&$seen) {
            $seen ??= [$context['input'], $userId];
            $formId = (int) ($context['input']['form_id'] ?? 0);
            if ($formId > 0) {
                $context['summary'] = 'The user is on form ' . $formId . '.';
                $context['about'] = ['form_id' => $formId];
            }

            return $context;
        }, 10, 2);
        add_filter(Contract::chatViewHook('bit-demo'), static fn ($view) => $view + ['preview' => ['form_id' => 7]], 10, 1);

        $response = $this->call('POST', '/assistant/chat', ['message' => 'Which forms?', 'context' => ['form_id' => 7, 'screen' => 'builder']]);

        self::assertSame(200, $response->get_status());
        $view = $response->get_data();
        self::assertSame([['form_id' => 7, 'screen' => 'builder'], self::MEMBER], $seen);
        self::assertStringContainsString('The user is on form 7.', $this->engine->calls[0]['system'], 'only the plugin decides what the model is told');
        self::assertSame('You have form 7.', end($view['messages'])['text']);
        self::assertSame(['form_id' => 7], $view['preview'], 'the view filter adds to every reply');
        self::assertSame('scripted', $view['engine']['key']);
        self::assertSame(['form_id' => 7], $this->conversations->rows[$view['conversation']['id']]['context'], 'about is stored with the conversation');

        self::assertSame(400, $this->call('POST', '/assistant/chat', ['message' => '  '])->get_error_data()['status']);
        $this->call('DELETE', '/assistant/conversations/' . $view['conversation']['id'] . 'x', ['id' => 'all']);
        self::assertArrayHasKey($view['conversation']['id'], $this->conversations->rows, 'a body or query id never replaces the one in the path');
        self::assertSame(400, $this->call('POST', '/assistant/decide', [])->get_error_data()['status']);

        $failed = $this->call('POST', '/assistant/decide', ['approve' => true]);
        self::assertSame(422, $failed->get_status(), 'a failed turn still carries the view');
        self::assertSame('no_pending', $failed->get_data()['reason']);
        self::assertCount(3, $failed->get_data()['messages'], 'user, the tool turn, the answer');
    }

    public function testAStreamedTurnReportsItsProgressThenTheView(): void
    {
        $this->boot();
        self::assertArrayNotHasKey('/bit-demo/v1/ai/assistant/stream', \AiKitRest::$routes, 'streaming is opt-in');

        resetAiKitTestState();
        Kit::reset();
        update_option('bit_demo_ai_settings', ['enabled' => true, 'access_level' => 'write']);
        add_filter(Contract::toolProvidersHook('bit-demo'), static fn ($providers) => array_merge($providers, [[
            ['name' => 'list-forms', 'title' => 'List forms', 'description' => 'Forms.', 'annotations' => ['readonly' => true], 'handler' => static fn () => ['items' => [['id' => 7]]]],
        ]]), 10, 1);
        $kit = Kit::boot($this->context([
            'features' => [PluginContext::FEATURE_REST_UI => true, PluginContext::FEATURE_STREAM => true],
            'can_use'  => static fn ($userId) => self::MEMBER === (int) $userId,
            'services' => ['conversations' => $this->conversations],
        ]));
        $engine = $this->engine;
        $kit->useEngineFactory(static fn () => $engine);
        do_action('rest_api_init');
        self::assertArrayHasKey('/bit-demo/v1/ai/assistant/stream', \AiKitRest::$routes);
        self::assertTrue($kit->uiBootstrap()['features']['stream']);

        \AiKitTestState::$currentUser = self::MEMBER;
        $this->engine->script = [
            ['text' => 'Looking.', 'tool_calls' => [['id' => 't1', 'name' => 'list-forms', 'input' => []]]],
            ['text' => 'You have form 7.', 'tool_calls' => []],
        ];
        [, $request] = $this->endpoint('POST', '/assistant/stream', ['message' => 'Which forms?'], []);
        $events = [];

        $view = (new AssistantController($kit))->streamTo($request, static function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        });

        self::assertSame(['step', 'text', 'tool', 'tool', 'step', 'text', 'done'], array_column($events, 0));
        self::assertSame('List forms', $events[2][1]['title']);
        self::assertSame(200, end($events)[1]['status']);
        self::assertSame('You have form 7.', end($view['messages'])['text']);
        self::assertSame($view, end($events)[1]['view']);
    }

    public function testAnApprovedActionStreamsTheRestOfTheTurn(): void
    {
        resetAiKitTestState();
        Kit::reset();
        update_option('bit_demo_ai_settings', ['enabled' => true, 'access_level' => 'destructive', 'confirm_destructive' => true]);
        $deleted = new \ArrayObject();
        add_filter(Contract::toolProvidersHook('bit-demo'), static fn ($providers) => array_merge($providers, [[
            ['name' => 'delete-form', 'title' => 'Delete form', 'description' => 'Delete.', 'annotations' => ['destructive' => true], 'handler' => static function () use ($deleted) {
                $deleted[] = 7;

                return ['deleted' => 7];
            }],
        ]]), 10, 1);
        $kit = Kit::boot($this->context([
            'features' => [PluginContext::FEATURE_REST_UI => true, PluginContext::FEATURE_STREAM => true],
            'can_use'  => static fn ($userId) => self::MEMBER === (int) $userId,
            'services' => ['conversations' => $this->conversations],
        ]));
        $engine = $this->engine;
        $kit->useEngineFactory(static fn () => $engine);
        do_action('rest_api_init');
        self::assertArrayHasKey('/bit-demo/v1/ai/assistant/decide/stream', \AiKitRest::$routes);

        \AiKitTestState::$currentUser = self::MEMBER;
        $this->engine->script = [
            ['text' => 'Deleting.', 'tool_calls' => [['id' => 't1', 'name' => 'delete-form', 'input' => []]]],
            ['text' => 'Form 7 is deleted.', 'tool_calls' => []],
        ];
        $paused = $kit->assistant()->chat(self::MEMBER, 'Delete form 7');
        self::assertNotNull($paused['pending']);

        [, $request] = $this->endpoint('POST', '/assistant/decide/stream', ['approve' => true], []);
        $events = [];
        $view = (new AssistantController($kit))->decideStreamTo($request, static function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        });

        self::assertSame([7], $deleted->getArrayCopy(), 'the approved action ran');
        self::assertSame(['tool', 'tool', 'step', 'text', 'done'], array_column($events, 0), 'the approved action runs, then the reply streams');
        self::assertSame(['running', 'ok'], [$events[0][1]['status'], $events[1][1]['status']]);
        self::assertSame('Delete form', $events[0][1]['title']);
        self::assertSame('Form 7 is deleted.', end($view['messages'])['text']);
        self::assertSame('done', end($events)[0]);
        self::assertSame(200, end($events)[1]['status']);
        self::assertNull($view['pending']);
    }

    public function testConversationsAreListedRenamedFlaggedOpenedAndForgotten(): void
    {
        $this->boot();
        \AiKitTestState::$currentUser = self::MEMBER;
        $this->engine->script = [['text' => 'One', 'tool_calls' => []], ['text' => 'Two', 'tool_calls' => []]];
        $first = $this->call('POST', '/assistant/chat', ['message' => 'First'])->get_data()['conversation']['id'];
        $this->call('POST', '/assistant/reset');
        $second = $this->call('POST', '/assistant/chat', ['message' => 'Second'])->get_data()['conversation']['id'];

        self::assertSame([$second, $first], array_column($this->call('GET', '/assistant/history')->get_data()['items'], 'id'));

        $renamed = $this->call('PATCH', "/assistant/conversations/{$first}", ['title' => 'Renamed', 'pinned' => true]);
        self::assertSame(200, $renamed->get_status());
        self::assertSame('Renamed', $this->conversations->rows[$first]['title']);
        self::assertSame([$first, $second], array_column($this->call('GET', '/assistant/history')->get_data()['items'], 'id'), 'pinned first');
        self::assertSame(400, $this->call('PATCH', "/assistant/conversations/{$first}", [])->get_error_data()['status']);
        self::assertSame(404, $this->call('PATCH', '/assistant/conversations/nope', ['title' => 'x'])->get_status());

        $this->call('PATCH', "/assistant/conversations/{$second}", ['archived' => true]);
        self::assertSame([$first], array_column($this->call('GET', '/assistant/history')->get_data()['items'], 'id'));
        self::assertSame([$second], array_column($this->call('GET', '/assistant/history', [], ['archived' => '1'])->get_data()['items'], 'id'));

        $opened = $this->call('POST', "/assistant/conversations/{$first}/open");
        self::assertSame('One', $opened->get_data()['messages'][1]['text']);
        self::assertTrue($opened->get_data()['conversation']['pinned']);
        self::assertSame(404, $this->call('POST', '/assistant/conversations/nope/open')->get_status());

        \AiKitTestState::$currentUser = self::MANAGER;
        self::assertSame(404, $this->call('POST', "/assistant/conversations/{$first}/open")->get_status(), 'another user never reaches it');

        \AiKitTestState::$currentUser = self::MEMBER;
        $switches = [];
        add_action(Contract::conversationSwitchHook('bit-demo'), static function ($userId, $event, $id) use (&$switches) {
            $switches[] = [$userId, $event, $id];
        }, 10, 3);
        $this->call('DELETE', "/assistant/conversations/{$first}");
        self::assertArrayNotHasKey($first, $this->conversations->rows);
        $this->call('POST', '/assistant/reset');
        self::assertSame([[self::MEMBER, 'forget', $first], [self::MEMBER, 'reset', null]], $switches, 'the plugin hears about every switch first');
        $this->call('DELETE', '/assistant/conversations/all');
        self::assertSame(0, $this->call('GET', '/assistant/history', [], ['archived' => '1'])->get_data()['total']);
    }

    public function testSettingsScreensReadAndSave(): void
    {
        $this->boot();
        \AiKitTestState::$currentUser = self::MANAGER;

        $state = $this->call('GET', '/settings/assistant')->get_data();
        self::assertTrue($state['settings']['enabled']);
        self::assertSame('List forms', $state['tools'][0]['title']);

        $savedEvents = [];
        add_action(Contract::settingsSavedHook('bit-demo'), static function ($which, $settings) use (&$savedEvents) {
            $savedEvents[] = [$which, $settings['max_turns'] ?? $settings['access_level']];
        }, 10, 2);
        $saved = $this->call('POST', '/settings/assistant', ['settings' => ['max_turns' => 3]])->get_data();
        self::assertSame(3, $saved['settings']['max_turns'], 'the Bit Form shape, under settings');
        self::assertSame([['assistant', 3]], $savedEvents, 'the plugin hears about saves made through the kit');
        $flat = $this->call('POST', '/settings/assistant', ['max_turns' => 4])->get_data();
        self::assertSame(4, $flat['settings']['max_turns'], 'or flat');

        $mcp = $this->call('GET', '/settings/mcp')->get_data();
        self::assertSame('https://example.test/wp-json/bit-demo/v1/mcp', $mcp['endpoint']);
        self::assertSame('read', $this->call('POST', '/settings/mcp', ['settings' => ['access_level' => 'read']])->get_data()['settings']['access_level']);
        self::assertSame('read', $this->call('GET', '/mcp/state')->get_data()['settings']['access_level'], 'saved');
    }

    public function testTheActivityLogPagesFiltersAndClears(): void
    {
        $kit = $this->boot();
        \AiKitTestState::$currentUser = self::MEMBER;
        $this->engine->script = [
            ['text' => '', 'tool_calls' => [['id' => 't1', 'name' => 'list-forms', 'input' => []], ['id' => 't2', 'name' => 'missing-tool', 'input' => []]]],
            ['text' => 'Done.', 'tool_calls' => []],
        ];
        $this->call('POST', '/assistant/chat', ['message' => 'Go']);

        $page = $this->call('GET', '/mcp/activity', [], ['per_page' => '1'])->get_data();
        self::assertSame(2, $page['total']);
        self::assertCount(1, $page['rows']);
        self::assertSame('User 6', $page['rows'][0]['user']);
        self::assertContains('list-forms', $page['filters']['tool']);
        self::assertSame([['id' => self::MEMBER, 'name' => 'User 6']], $page['filters']['user']);

        $ok = $this->call('GET', '/mcp/activity', [], ['status' => 'ok'])->get_data();
        self::assertSame(['list-forms'], array_column($ok['rows'], 'tool'));

        // A manager's calls stay out of a member's log, filter menus and screen state.
        \AiKitTestState::$currentUser = self::MANAGER;
        $this->engine->script = [['text' => '', 'tool_calls' => [['id' => 't3', 'name' => 'list-forms', 'input' => []]]], ['text' => 'Done.', 'tool_calls' => []]];
        $this->call('POST', '/assistant/reset');
        $this->call('POST', '/assistant/chat', ['message' => 'Mine']);
        self::assertSame(3, $this->call('GET', '/mcp/activity')->get_data()['total'], 'a manager sees everyone');
        \AiKitTestState::$currentUser = self::MEMBER;
        $mine = $this->call('GET', '/mcp/activity', [], ['user_id' => (string) self::MANAGER])->get_data();
        self::assertSame(2, $mine['total'], 'a member sees their own calls, whatever user they ask for');
        self::assertArrayNotHasKey('client_ref', $mine['filters']);
        self::assertSame([], $this->call('GET', '/mcp/state')->get_data()['recent'], "the MCP screen's summary leaves the assistant's calls to the assistant's screen");
        foreach ([self::MEMBER, self::MANAGER] as $user) {
            $kit->runner()->call('list-forms', [], new ToolContext(ToolContext::CHANNEL_MCP, $user, null, 'claude-desktop'));
        }
        $recent = $this->call('GET', '/mcp/state')->get_data()['recent'];
        self::assertSame([['mcp', self::MEMBER]], array_map(static fn ($row) => [$row['channel'], $row['user_id']], $recent), 'connected apps only, a member\'s own');
        \AiKitTestState::$currentUser = self::MEMBER;

        self::assertSame(403, $this->permission('DELETE', '/mcp/activity')->get_error_data()['status'], 'clearing is for managers');
        \AiKitTestState::$currentUser = self::MANAGER;
        self::assertSame(0, $this->call('DELETE', '/mcp/activity')->get_data()['total']);
        self::assertSame(0, $this->call('GET', '/mcp/activity')->get_data()['total']);
    }

    public function testApiKeysAndConnectionsAreScopedToTheirOwner(): void
    {
        $this->boot();
        \AiKitTestState::$currentUser = self::MEMBER;

        $created = $this->call('POST', '/mcp/api-keys', ['name' => 'CI deploy', 'access' => 'read', 'expires_days' => 30]);
        self::assertSame(201, $created->get_status());
        self::assertStringStartsWith('bitdemo_key_', $created->get_data()['key']);
        $keyId = $created->get_data()['record']['id'];
        self::assertSame(422, $this->call('POST', '/mcp/api-keys', ['name' => '', 'access' => 'read', 'expires_days' => 30])->get_error_data()['status']);
        self::assertSame(403, $this->call('POST', '/mcp/api-keys', ['name' => 'Forever', 'access' => 'read'])->get_error_data()['status'], 'a key that never expires is for managers');
        self::assertSame(403, $this->call('POST', '/mcp/api-keys', ['name' => 'Bot', 'access' => 'read', 'expires_days' => 30, 'unattended' => true])->get_error_data()['status'], 'so is an unattended one');

        \AiKitTestState::$currentUser = self::MANAGER;
        $this->call('POST', '/mcp/api-keys', ['name' => 'Admin key', 'access' => 'write']);
        self::assertCount(2, $this->call('GET', '/mcp/api-keys')->get_data()['keys'], 'a manager sees every key');
        self::assertSame('User 6', $this->call('GET', '/mcp/api-keys')->get_data()['keys'][0]['user']);

        \AiKitTestState::$currentUser = self::MEMBER;
        $own = $this->call('GET', '/mcp/api-keys')->get_data()['keys'];
        self::assertSame(['CI deploy'], array_column($own, 'name'), 'a member sees only their own');
        $adminKeyId = array_values(array_filter($this->call('GET', '/settings/mcp')->get_data()['api_keys']['keys'], static fn ($k) => 'Admin key' === $k['name']));
        self::assertSame([], $adminKeyId);
        self::assertSame(404, $this->call('DELETE', "/mcp/api-keys/{$keyId}x")->get_error_data()['status']);
        self::assertSame(200, $this->call('DELETE', "/mcp/api-keys/{$keyId}")->get_status());

        self::assertSame([], $this->call('GET', '/mcp/connections')->get_data()['connections'], 'OAuth is off without pretty permalinks');
        self::assertSame(404, $this->call('DELETE', '/mcp/connections/nope')->get_error_data()['status']);
        self::assertSame(200, $this->call('DELETE', '/mcp/connections')->get_status());
    }

    public function testUiBootstrapDescribesWhatTheScreensNeed(): void
    {
        $kit = $this->boot();
        \AiKitTestState::$currentUser = self::MEMBER;

        $bootstrap = $kit->uiBootstrap();
        self::assertSame(Contract::CONTRACT_VERSION, $bootstrap['contract']);
        self::assertSame('https://example.test/wp-json/bit-demo/v1/ai', $bootstrap['restBase']);
        self::assertSame(wp_create_nonce('wp_rest'), $bootstrap['nonce']);
        self::assertTrue($bootstrap['canUse']);
        self::assertFalse($bootstrap['canManage']);
        self::assertTrue($bootstrap['assistantOn']);
        self::assertSame('scripted', $bootstrap['engine']['key']);
        self::assertSame(['assistant' => true, 'mcp' => true, 'restUi' => true, 'history' => true, 'audit' => true, 'oauth' => true, 'apiKeys' => true, 'abilities' => true, 'mcpAdapter' => true, 'attachments' => false, 'stream' => false], $bootstrap['features']);

        add_filter(Contract::uiBootstrapHook('bit-demo'), static fn ($b) => $b + ['persona' => ['greeting' => 'Hi']], 10, 1);
        self::assertSame('Hi', $kit->uiBootstrap()['persona']['greeting']);

        \AiKitTestState::$currentUser = self::OUTSIDER;
        $outsider = $kit->uiBootstrap();
        self::assertFalse($outsider['canUse']);
        self::assertNull($outsider['engine'], 'no engine is resolved for a user without access');
    }

    private function boot(): Kit
    {
        $kit = Kit::boot($this->context([
            'features' => [PluginContext::FEATURE_REST_UI => true],
            'can_use'  => static fn ($userId) => \in_array((int) $userId, [self::MANAGER, self::MEMBER], true),
            'services' => ['conversations' => $this->conversations],
        ]));
        $engine = $this->engine;
        $kit->useEngineFactory(static fn () => $engine);
        do_action('rest_api_init');

        return $kit;
    }

    /** @return bool|\WP_Error */
    private function permission(string $method, string $path)
    {
        [$endpoint, $request] = $this->endpoint($method, $path, [], []);

        return ($endpoint['permission_callback'])($request);
    }

    /**
     * Run a route as the current user: the permission callback first, then the handler.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     *
     * @return \WP_Error|\WP_REST_Response
     */
    private function call(string $method, string $path, array $body = [], array $query = [])
    {
        [$endpoint, $request] = $this->endpoint($method, $path, $body, $query);
        $allowed = ($endpoint['permission_callback'])($request);
        if (true !== $allowed) {
            return $allowed instanceof \WP_Error ? $allowed : new \WP_Error('rest_forbidden', 'Forbidden', ['status' => 403]);
        }

        return ($endpoint['callback'])($request);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     *
     * @return array{0: array<string, mixed>, 1: \WP_REST_Request}
     */
    private function endpoint(string $method, string $path, array $body, array $query): array
    {
        foreach (RestRoutes::table() as $route => $endpoints) {
            $pattern = '#^' . str_replace('(?P<id>[A-Za-z0-9_-]{1,64})', '(?P<id>[A-Za-z0-9_-]{1,64})', $route) . '$#';
            if (!preg_match($pattern, $path, $matches)) {
                continue;
            }
            $registered = \AiKitRest::$routes['/bit-demo/v1' . Contract::REST_BASE . $route] ?? null;
            self::assertNotNull($registered, $route . ' is registered');
            foreach ($registered as $endpoint) {
                if (\in_array($method, array_map('trim', explode(',', $endpoint['methods'])), true)) {
                    $request = new \WP_REST_Request($method, '/bit-demo/v1' . Contract::REST_BASE . $path);
                    $request->set_url_params(isset($matches['id']) ? ['id' => $matches['id']] : []);
                    $request->query = $query;
                    $request->set_header('content-type', 'application/json');
                    $request->set_body((string) wp_json_encode($body));

                    return [$endpoint, $request];
                }
            }
        }

        self::fail("No route for {$method} {$path}");
    }
}
