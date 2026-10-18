<?php

namespace BitApps\AiKit\Mcp\Server;

use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Security\UntrustedContent;
use BitApps\AiKit\Tools\ToolContext;
use BitApps\AiKit\Tools\ToolRunner;

/**
 * Transport-independent MCP method dispatch.
 *
 * Supports the stateless subset every Bit plugin needs: initialize, ping,
 * tools/list, tools/call, empty resources/prompts listings (clients probe them)
 * and notifications. Tool failures are results with `isError: true`, as the spec
 * asks; only protocol problems (unknown method, unknown tool, malformed call)
 * are JSON-RPC errors.
 */
final class McpServer
{
    public const LATEST_PROTOCOL = '2025-11-25';

    public const SUPPORTED_PROTOCOLS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    /** Batching existed only up to this revision. */
    public const LAST_BATCHING_PROTOCOL = '2025-03-26';

    /** The outcome of the last tools/call, for transports that map it to HTTP (step-up 403). */
    private ?array $lastToolFailure = null;

    public function __construct(private PluginContext $context, private ToolRunner $runner) {}

    public static function supports(string $version): bool
    {
        return \in_array($version, self::SUPPORTED_PROTOCOLS, true);
    }

    public static function allowsBatches(?string $version): bool
    {
        return null === $version || '' === $version || version_compare($version, self::LAST_BATCHING_PROTOCOL, '<=');
    }

    /**
     * Handle one decoded JSON-RPC message.
     *
     * @param mixed $message
     *
     * @return null|array<string, mixed> the response, or null for notifications
     */
    public function handle($message, ToolContext $call): ?array
    {
        $this->lastToolFailure = null;

        if (!\is_array($message)) {
            return JsonRpc::error(null, JsonRpc::INVALID_REQUEST, 'Invalid Request');
        }

        $isNotification = !\array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;

        if ('2.0' !== ($message['jsonrpc'] ?? null) || !\is_string($method) || '' === $method) {
            return $isNotification ? null : JsonRpc::error($id, JsonRpc::INVALID_REQUEST, 'Invalid Request');
        }

        if (str_starts_with($method, 'notifications/')) {
            return null;
        }

        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        $response = match ($method) {
            'initialize'               => JsonRpc::result($id, $this->initialize($params)),
            'ping'                     => JsonRpc::result($id, new \stdClass()),
            'tools/list'               => JsonRpc::result($id, ['tools' => $this->runner->listTools($call)]),
            'tools/call'               => $this->callTool($id, $params, $call),
            'resources/list'           => JsonRpc::result($id, ['resources' => []]),
            'resources/templates/list' => JsonRpc::result($id, ['resourceTemplates' => []]),
            'prompts/list'             => JsonRpc::result($id, ['prompts' => []]),
            'logging/setLevel'         => JsonRpc::result($id, new \stdClass()),
            default                    => JsonRpc::error($id, JsonRpc::METHOD_NOT_FOUND, "Method not found: {$method}"),
        };

        return $isNotification ? null : $response;
    }

    /**
     * Code and required scope of the last refused tools/call, if any.
     *
     * @return null|array{code: string, scope: ?string}
     */
    public function lastToolFailure(): ?array
    {
        return $this->lastToolFailure;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = \is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '';

        $instructions = trim($this->context->instructions());
        $instructions = ('' !== $instructions ? $instructions . "\n\n" : '') . UntrustedContent::GUIDANCE;

        return [
            'protocolVersion' => self::supports($requested) ? $requested : self::LATEST_PROTOCOL,
            'capabilities'    => [
                'tools'     => ['listChanged' => false],
                'resources' => new \stdClass(),
                'prompts'   => new \stdClass(),
            ],
            'serverInfo' => [
                'name'    => $this->context->slug(),
                'title'   => $this->context->name(),
                'version' => $this->context->version(),
            ],
            'instructions' => $instructions,
        ];
    }

    /**
     * @param mixed                $id
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function callTool($id, array $params, ToolContext $call): array
    {
        $name = $params['name'] ?? null;
        if (!\is_string($name) || '' === $name) {
            return JsonRpc::error($id, JsonRpc::INVALID_PARAMS, 'tools/call requires a tool name');
        }

        $arguments = $params['arguments'] ?? [];
        if (null !== $arguments && !\is_array($arguments) && !\is_object($arguments)) {
            return JsonRpc::error($id, JsonRpc::INVALID_PARAMS, 'tools/call arguments must be an object');
        }

        $result = $this->runner->call($name, $arguments ?? [], $call);

        if ('unknown_tool' === $result->errorCode()) {
            return JsonRpc::error($id, JsonRpc::INVALID_PARAMS, "Unknown tool: {$name}");
        }

        if ($result->isError()) {
            $this->lastToolFailure = ['code' => (string) $result->errorCode(), 'scope' => $result->meta('required_scope')];
        }

        return JsonRpc::result($id, $result->toMcp());
    }
}
