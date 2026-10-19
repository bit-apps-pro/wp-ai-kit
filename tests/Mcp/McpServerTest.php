<?php

namespace BitApps\AiKit\Tests\Mcp;

use BitApps\AiKit\Contract;
use BitApps\AiKit\Kit;
use BitApps\AiKit\Mcp\Server\JsonRpc;
use BitApps\AiKit\Mcp\Server\McpServer;
use BitApps\AiKit\Security\UntrustedContent;
use BitApps\AiKit\Tests\TestCase;
use BitApps\AiKit\Tools\ToolContext;

/**
 * @internal
 */
final class McpServerTest extends TestCase
{
    private McpServer $server;

    private ToolContext $call;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grant(3, 'manage_options');
        update_option('bit_demo_mcp_settings', ['enabled' => true]);
        add_filter(Contract::toolProvidersHook('bit-demo'), static fn ($p) => array_merge($p, [[
            ['name' => 'list_forms', 'description' => 'Forms.', 'annotations' => ['readonly' => true], 'handler' => static fn () => [['id' => 1]]],
            ['name' => 'save_form', 'description' => 'Save.', 'handler' => static fn () => ['ok' => true]],
        ]]), 10, 1);

        $this->server = Kit::boot($this->context(['instructions' => 'Bit Demo tools.']))->mcpServer();
        $this->call = new ToolContext(ToolContext::CHANNEL_MCP, 3);
    }

    public function testInitializeNegotiatesTheProtocolAndExplainsUntrustedData(): void
    {
        $known = $this->send(['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);
        $unknown = $this->send(['id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]);

        self::assertSame('2025-06-18', $known['result']['protocolVersion']);
        self::assertSame(McpServer::LATEST_PROTOCOL, $unknown['result']['protocolVersion']);
        self::assertSame('bit-demo', $known['result']['serverInfo']['name']);
        self::assertStringStartsWith('Bit Demo tools.', $known['result']['instructions']);
        self::assertStringContainsString(UntrustedContent::OPEN, $known['result']['instructions']);
    }

    public function testServerInfoCarriesTheWebsiteAndIconsWhenGiven(): void
    {
        $plain = $this->send(['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']]);
        self::assertSame(['name' => 'bit-demo', 'title' => 'Bit Demo', 'version' => '1.2.3'], $plain['result']['serverInfo'], 'nothing empty is sent');

        Kit::reset();
        $this->server = Kit::boot($this->context([
            'description' => 'Forms and entries.',
            'website_url' => 'https://example.test/bit-demo/',
            'icons'       => [['src' => 'https://example.test/logo.png', 'mimeType' => 'image/png', 'sizes' => ['128x128']]],
        ]))->mcpServer();
        $info = $this->send(['id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']])['result']['serverInfo'];

        self::assertSame('Forms and entries.', $info['description']);
        self::assertSame('https://example.test/bit-demo/', $info['websiteUrl']);
        self::assertSame([['src' => 'https://example.test/logo.png', 'mimeType' => 'image/png', 'sizes' => ['128x128']]], $info['icons']);
    }

    public function testListAndCall(): void
    {
        $list = $this->send(['id' => 1, 'method' => 'tools/list']);
        self::assertSame(['list_forms'], array_column($list['result']['tools'], 'name'), 'read-only policy hides the write tool');

        $call = $this->send(['id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list_forms', 'arguments' => new \stdClass()]]);
        self::assertFalse($call['result']['isError']);
        self::assertSame(['items' => [['id' => 1]]], $call['result']['structuredContent']);

        $refused = $this->send(['id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'save_form']]);
        self::assertTrue($refused['result']['isError'], 'a refused tool is a tool result, not a protocol error');
        self::assertSame('tool_disabled', $this->server->lastToolFailure()['code']);
    }

    public function testProtocolErrors(): void
    {
        self::assertSame(JsonRpc::INVALID_PARAMS, $this->send(['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nope']])['error']['code']);
        self::assertSame(JsonRpc::INVALID_PARAMS, $this->send(['id' => 1, 'method' => 'tools/call', 'params' => []])['error']['code']);
        self::assertSame(JsonRpc::METHOD_NOT_FOUND, $this->send(['id' => 1, 'method' => 'sampling/createMessage'])['error']['code']);
        self::assertSame(JsonRpc::INVALID_REQUEST, $this->server->handle(['id' => 1, 'method' => 'ping'], $this->call)['error']['code'], 'jsonrpc 2.0 is required');
        self::assertNull($this->send(['method' => 'notifications/initialized']));
        self::assertSame([], $this->send(['id' => 1, 'method' => 'prompts/list'])['result']['prompts']);
    }

    public function testDecodeAndBatchRules(): void
    {
        self::assertSame(JsonRpc::PARSE_ERROR, JsonRpc::decode('{nope')['error']['error']['code']);
        self::assertSame(JsonRpc::INVALID_REQUEST, JsonRpc::decode('[]')['error']['error']['code']);
        self::assertTrue(JsonRpc::decode('[{"jsonrpc":"2.0","id":1,"method":"ping"}]')['batch']);
        self::assertTrue(McpServer::allowsBatches('2025-03-26'));
        self::assertFalse(McpServer::allowsBatches('2025-06-18'));
        self::assertTrue(McpServer::allowsBatches(null));
    }

    private function send(array $message): ?array
    {
        return $this->server->handle(array_merge(['jsonrpc' => '2.0'], $message), $this->call);
    }
}
