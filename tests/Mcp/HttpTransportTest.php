<?php

namespace BitApps\AiKit\Tests\Mcp;

use BitApps\AiKit\Contract;
use BitApps\AiKit\Kit;
use BitApps\AiKit\Mcp\Auth\BearerGrant;
use BitApps\AiKit\Mcp\Auth\BearerResolverInterface;
use BitApps\AiKit\Tests\TestCase;

/**
 * @internal
 */
final class HttpTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->grant(3, 'manage_options');
        add_filter(Contract::toolProvidersHook('bit-demo'), static fn ($p) => array_merge($p, [[
            ['name' => 'list_forms', 'description' => 'Forms.', 'annotations' => ['readonly' => true], 'handler' => static fn () => [['id' => 1]]],
            ['name' => 'save_form', 'description' => 'Save.', 'scope' => 'write', 'handler' => static fn () => ['ok' => true]],
        ]]), 10, 1);
    }

    public function testRouteExistsOnlyWhenEnabled(): void
    {
        $this->boot(['enabled' => false]);
        do_action('rest_api_init');
        self::assertArrayNotHasKey('/bit-demo/v1/mcp', \AiKitRest::$routes);

        resetAiKitTestState();
        $this->boot();
        do_action('rest_api_init');
        self::assertArrayHasKey('/bit-demo/v1/mcp', \AiKitRest::$routes);
        self::assertSame('POST', \AiKitRest::$routes['/bit-demo/v1/mcp'][0]['methods']);
    }

    public function testApplicationPasswordSessionsAreNotScopeLimited(): void
    {
        $transport = $this->boot()->transport();
        \AiKitTestState::$currentUser = 3;

        $request = $this->request('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', ['user-agent' => 'claude-code/2.1']);
        self::assertTrue($transport->authorize($request));

        $response = $transport->handle($request);
        self::assertSame(200, $response->get_status());
        self::assertSame(['list_forms'], array_column($response->get_data()['result']['tools'], 'name'));
    }

    public function testMissingOrInvalidCredentialsAre401WithAChallenge(): void
    {
        $transport = $this->boot()->transport();

        $missing = $transport->authorize($this->request('{}'));
        self::assertSame(401, $missing->get_error_data()['status']);

        $invalid = $transport->authorize($this->request('{}', ['authorization' => 'Bearer nope']));
        self::assertSame('mcp_unauthorized', $invalid->get_error_code());

        $response = new \WP_REST_Response(['code' => 'mcp_unauthorized'], 401);
        $transport->addChallenge($response, null, new \WP_REST_Request('POST', '/bit-demo/v1/mcp'));
        self::assertSame('Basic realm="Bit Demo MCP"', $response->get_headers()['WWW-Authenticate'], 'no OAuth yet: ask for an Application Password');

        $other = new \WP_REST_Response([], 401);
        $transport->addChallenge($other, null, new \WP_REST_Request('POST', '/wp/v2/posts'));
        self::assertArrayNotHasKey('WWW-Authenticate', $other->get_headers(), 'other routes are left alone');
    }

    public function testUsersWithoutAccessAreForbidden(): void
    {
        $transport = $this->boot()->transport();
        \AiKitTestState::$currentUser = 8;

        self::assertSame(403, $transport->authorize($this->request('{}'))->get_error_data()['status']);
    }

    public function testForeignBrowserOriginsAreRefused(): void
    {
        $transport = $this->boot()->transport();
        \AiKitTestState::$currentUser = 3;

        self::assertSame('mcp_origin_forbidden', $transport->authorize($this->request('{}', ['origin' => 'https://evil.example']))->get_error_code());
        self::assertTrue($transport->authorize($this->request('{}', ['origin' => 'https://example.test'])));

        add_filter('bitapps_ai_allowed_origins', static fn () => ['https://inspector.example'], 10, 2);
        self::assertTrue($transport->authorize($this->request('{}', ['origin' => 'https://inspector.example'])));
    }

    public function testBearerGrantsAreScopedAndStepUpIs403(): void
    {
        $kit = $this->boot(['enabled' => true, 'access_level' => 'write']);
        $kit->addBearerResolver(new class implements BearerResolverInterface {
            public function resolve(string $token): ?BearerGrant
            {
                return 'good' === $token ? new BearerGrant(3, ['read'], 'Claude', 'oauth') : null;
            }
        });
        $transport = $kit->transport();

        $request = $this->request('{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"save_form","arguments":{}}}', ['authorization' => 'Bearer good']);
        self::assertTrue($transport->authorize($request));
        self::assertSame(3, get_current_user_id(), 'the grant user is adopted for the request');

        $response = $transport->handle($request);
        self::assertSame(403, $response->get_status());
        self::assertStringContainsString('error="insufficient_scope"', $response->get_headers()['WWW-Authenticate']);
        self::assertStringContainsString('scope="write"', $response->get_headers()['WWW-Authenticate']);
    }

    public function testNotificationsBatchesAndProtocolHeader(): void
    {
        $transport = $this->boot()->transport();
        \AiKitTestState::$currentUser = 3;

        self::assertSame(202, $transport->handle($this->request('{"jsonrpc":"2.0","method":"notifications/initialized"}'))->get_status());
        self::assertSame(400, $transport->handle($this->request('{"jsonrpc":"2.0","id":1,"method":"ping"}', ['mcp-protocol-version' => '2099-01-01']))->get_status());

        $batch = '[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"ping"}]';
        self::assertCount(2, $transport->handle($this->request($batch, ['mcp-protocol-version' => '2025-03-26']))->get_data());
        self::assertSame(400, $transport->handle($this->request($batch, ['mcp-protocol-version' => '2025-06-18']))->get_status());

        self::assertSame(400, $transport->handle($this->request('{broken'))->get_status());
        self::assertSame(405, $transport->methodNotAllowed()->get_status());
    }

    public function testBrowserTricksAreRefused(): void
    {
        $transport = $this->boot()->transport();
        \AiKitTestState::$currentUser = 3;

        self::assertSame('mcp_origin_forbidden', $transport->authorize($this->request('{}', ['origin' => 'null']))->get_error_code(), 'sandboxed frames');
        self::assertSame('mcp_origin_forbidden', $transport->authorize($this->request('{}', ['origin' => 'http://example.test']))->get_error_code(), 'scheme counts');
        self::assertSame('mcp_origin_forbidden', $transport->authorize($this->request('{}', ['origin' => 'https://example.test:8443']))->get_error_code(), 'port counts');
        self::assertTrue($transport->authorize($this->request('{}', ['origin' => 'https://EXAMPLE.test:443'])));

        foreach (['text/plain', 'application/x-www-form-urlencoded; charset=UTF-8', 'multipart/form-data; boundary=x'] as $type) {
            self::assertSame('mcp_unsupported_media_type', $transport->authorize($this->request('{}', ['content-type' => $type]))->get_error_code(), $type);
        }
        self::assertTrue($transport->authorize($this->request('{}', ['content-type' => 'application/json'])));
    }

    public function testBatchesAreCappedAndRepliesAreNotCached(): void
    {
        $transport = $this->boot()->transport();
        \AiKitTestState::$currentUser = 3;

        $batch = wp_json_encode(array_fill(0, 21, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));
        $reply = $transport->handle($this->request($batch));
        self::assertSame(400, $reply->get_status());

        $object = $transport->handle($this->request('{"0":{"jsonrpc":"2.0","id":1,"method":"ping"}}'));
        self::assertArrayNotHasKey(0, (array) $object->get_data(), 'an object with numeric keys is not a batch');

        $ping = $transport->handle($this->request('{"jsonrpc":"2.0","id":1,"method":"ping"}'));
        self::assertSame('no-store', $ping->get_headers()['Cache-Control']);
    }

    public function testApplicationPasswordCallersAreLabelledAndShareOneRateLimit(): void
    {
        update_option('bit_demo_mcp_settings', ['enabled' => true, 'calls_per_minute' => 2]);
        $transport = Kit::boot($this->context())->transport();
        \AiKitTestState::$currentUser = 3;

        $codes = [];
        foreach (['curl/1', 'curl/2', 'curl/3'] as $agent) {
            $reply = $transport->handle($this->request('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"list_forms","arguments":{}}}', ['user-agent' => $agent]));
            $codes[] = $reply->get_data()['result']['isError'] ?? null;
        }

        self::assertSame([false, false, true], $codes, 'a new User-Agent does not reset the limit');
    }

    private function boot(array $settings = ['enabled' => true]): Kit
    {
        update_option('bit_demo_mcp_settings', $settings);

        return Kit::boot($this->context());
    }

    private function request(string $body, array $headers = []): \WP_REST_Request
    {
        $request = new \WP_REST_Request('POST', '/bit-demo/v1/mcp');
        $request->set_body($body);
        foreach ($headers as $key => $value) {
            $request->set_header($key, $value);
        }

        return $request;
    }
}
