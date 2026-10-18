<?php

namespace BitApps\AiKit\Tests\Tools;

use BitApps\AiKit\Audit\AuditLoggerInterface;
use BitApps\AiKit\Audit\AuditStatus;
use BitApps\AiKit\Contract;
use BitApps\AiKit\Kit;
use BitApps\AiKit\Security\PiiMasker;
use BitApps\AiKit\Tests\TestCase;
use BitApps\AiKit\Tools\ToolContext;
use BitApps\AiKit\Tools\ToolProviderInterface;

/**
 * The whole call pipeline through Kit, as a transport would drive it.
 *
 * @internal
 */
final class ToolRunnerTest extends TestCase
{
    private const ADMIN = 5;

    /** @var array<int, array<string, mixed>> */
    private array $audit = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->grant(self::ADMIN, 'manage_options', 'demo_manage');
        \AiKitTestState::$currentUser = self::ADMIN;
        update_option('bit_demo_mcp_settings', ['enabled' => true, 'access_level' => 'destructive']);

        $audit = new class($this->audit) implements AuditLoggerInterface {
            private array $events;

            public function __construct(array &$events)
            {
                $this->events = &$events;
            }

            public function record(array $event): void
            {
                $this->events[] = $event;
            }

            public function recent(int $limit = 50): array
            {
                return $this->events;
            }
        };

        Kit::boot($this->context([
            'services' => [
                'audit'          => $audit,
                'pii_classifier' => static fn (string $key) => 'email' === $key ? PiiMasker::EMAIL : null,
            ],
        ]));

        add_filter(Contract::toolProvidersHook('bit-demo'), static function ($providers) {
            $providers[] = new DemoTools();
            $providers[] = [['name' => 'list_forms', 'description' => 'Forms.', 'annotations' => ['readonly' => true], 'handler' => static fn () => [['id' => 1, 'title' => 'Contact']]]];

            return $providers;
        }, 10, 1);
    }

    protected function tearDown(): void
    {
        DemoTools::$deleted = 0;
        parent::tearDown();
    }

    public function testListingRespectsGuardAndPolicy(): void
    {
        $names = array_column($this->runner()->listTools(new ToolContext(ToolContext::CHANNEL_MCP, self::ADMIN)), 'name');
        self::assertSame(['get_entry', 'delete_entry', 'save_secret', 'preview_change', 'explode', 'list_forms'], $names);

        update_option('bit_demo_mcp_settings', ['enabled' => true]);
        Kit::reset();
        Kit::boot($this->context());
        $readOnly = array_column(Kit::for('bit-demo')->runner()->listTools(new ToolContext(ToolContext::CHANNEL_MCP, self::ADMIN)), 'name');
        self::assertSame(['get_entry', 'list_forms'], $readOnly, 'default policy is read-only');

        self::assertSame([], $this->runner()->listTools(new ToolContext(ToolContext::CHANNEL_MCP, 999)), 'a user without access sees nothing');
    }

    public function testReadsAreMaskedRedactedAndAudited(): void
    {
        $result = $this->call('get_entry', ['entry_id' => '7']);

        self::assertFalse($result->isError(), $result->textContent());
        $data = $result->structured();
        self::assertSame(7, $data['id'], 'arguments were coerced');
        self::assertSame('j***@example.com', $data['email']);
        self::assertSame('[redacted]', $data['integration']['api_key']);
        self::assertStringNotContainsString('jane@example.com', wp_json_encode($result->toMcp()));

        $event = end($this->audit);
        self::assertSame('get_entry', $event['tool']);
        self::assertSame(AuditStatus::OK, $event['status']);
        self::assertSame('7', $event['resource_id']);
        self::assertSame('Claude', $event['client_ref']);
        self::assertSame(64, \strlen($event['args_digest']));
        self::assertArrayNotHasKey('args', $event, 'arguments are never stored, only their digest');
    }

    public function testPersonalDataIsShownWhenTheGrantAllowsIt(): void
    {
        $context = new ToolContext(ToolContext::CHANNEL_MCP, self::ADMIN, null, null, null, false, true);

        self::assertSame('jane@example.com', $this->call('get_entry', ['entry_id' => 7], $context)->structured()['email']);
    }

    public function testRefusalsCarryACode(): void
    {
        self::assertSame('unknown_tool', $this->call('nope')->errorCode());
        self::assertSame('invalid_arguments', $this->call('get_entry', ['entry_id' => 'x'])->errorCode());
        self::assertSame('resource_forbidden', $this->call('get_entry', ['entry_id' => 13])->errorCode());

        $scoped = new ToolContext(ToolContext::CHANNEL_MCP, self::ADMIN, ['read']);
        $refused = $this->call('save_secret', ['value' => 'x'], $scoped);
        self::assertSame('insufficient_scope', $refused->errorCode());

        self::assertSame('not_authenticated', $this->call('list_forms', [], new ToolContext(ToolContext::CHANNEL_MCP, 0))->errorCode());
        self::assertSame(AuditStatus::DENIED, end($this->audit)['status']);
    }

    public function testAccessIsCheckedBeforeArguments(): void
    {
        $scoped = new ToolContext(ToolContext::CHANNEL_MCP, self::ADMIN, ['read']);

        self::assertSame('insufficient_scope', $this->call('save_secret', [], $scoped)->errorCode(), 'a caller without access learns nothing about the schema');
        self::assertSame('not_authenticated', $this->call('get_entry', ['entry_id' => 'x'], new ToolContext(ToolContext::CHANNEL_MCP, 0))->errorCode());
    }

    public function testDestructiveToolsRunOnlyAfterConfirmation(): void
    {
        $first = $this->call('delete_entry', ['entry_id' => 7]);
        self::assertSame('confirmation_required', $first->errorCode());
        self::assertSame(0, DemoTools::$deleted);
        preg_match('/confirmation_token "([a-f0-9]{32})"/', $first->textContent(), $m);
        $token = $m[1];

        self::assertSame('invalid_confirmation', $this->call('delete_entry', ['entry_id' => 8, 'confirmation_token' => $token])->errorCode(), 'token is bound to the arguments');

        $again = $this->call('delete_entry', ['entry_id' => 7]);
        preg_match('/confirmation_token "([a-f0-9]{32})"/', $again->textContent(), $m);
        $done = $this->call('delete_entry', ['entry_id' => 7, 'confirmation_token' => $m[1]]);
        self::assertFalse($done->isError(), $done->textContent());
        self::assertSame(1, DemoTools::$deleted);

        self::assertSame('invalid_confirmation', $this->call('delete_entry', ['entry_id' => 7, 'confirmation_token' => $m[1]])->errorCode(), 'single use');

        $unattended = new ToolContext(ToolContext::CHANNEL_MCP, self::ADMIN, null, 'cron', null, true);
        self::assertFalse($this->call('delete_entry', ['entry_id' => 7], $unattended)->isError());
        self::assertSame(2, DemoTools::$deleted);
    }

    public function testAssistantWritesAskFirstUnlessTheyOnlyPreview(): void
    {
        update_option('bit_demo_ai_settings', ['enabled' => true, 'access_level' => 'write']);
        $assistant = new ToolContext(ToolContext::CHANNEL_ASSISTANT, self::ADMIN);

        self::assertSame('confirmation_required', $this->call('save_secret', ['value' => 'x'], $assistant)->errorCode());
        $previewed = $this->call('preview_change', ['value' => 'x'], $assistant);
        self::assertFalse($previewed->isError(), $previewed->textContent());
        self::assertTrue($previewed->structured()['previewed']);
    }

    public function testHandlerFailuresDoNotLeakDetails(): void
    {
        $result = $this->call('explode');

        self::assertSame('tool_failed', $result->errorCode());
        self::assertStringNotContainsString('secret path', $result->textContent());
    }

    public function testWritesAreRateLimited(): void
    {
        update_option('bit_demo_mcp_settings', ['enabled' => true, 'access_level' => 'destructive', 'writes_per_minute' => 2]);
        Kit::reset();
        Kit::boot($this->context());

        $runner = Kit::for('bit-demo')->runner();
        $context = new ToolContext(ToolContext::CHANNEL_MCP, self::ADMIN, null, 'Cursor');
        $runner->call('save_secret', ['value' => 'a'], $context);
        $runner->call('save_secret', ['value' => 'b'], $context);

        self::assertSame('rate_limited', $runner->call('save_secret', ['value' => 'c'], $context)->errorCode());
        self::assertFalse($runner->call('list_forms', [], $context)->isError(), 'reads have their own, higher limit');
    }

    private function runner()
    {
        return Kit::for('bit-demo')->runner();
    }

    private function call(string $tool, $args = [], ?ToolContext $context = null)
    {
        return $this->runner()->call($tool, $args, $context ?? new ToolContext(ToolContext::CHANNEL_MCP, self::ADMIN, null, 'Claude'));
    }
}

final class DemoTools implements ToolProviderInterface
{
    public static int $deleted = 0;

    public function definitions(): array
    {
        return [
            [
                'name'           => 'get_entry',
                'description'    => 'One entry.',
                'input_schema'   => ['properties' => ['entry_id' => ['type' => 'integer']], 'required' => ['entry_id']],
                'annotations'    => ['readonly' => true],
                'capability'     => 'demo_manage',
                'personal_data'  => true,
                'meta'           => ['resource_argument' => 'entry_id'],
                'resource_check' => static fn (array $args) => 13 !== $args['entry_id'],
                'handler'        => static fn (array $args) => [
                    'id'          => $args['entry_id'],
                    'email'       => 'jane@example.com',
                    'integration' => ['api_key' => 'sk-live-123'],
                ],
            ],
            [
                'name'         => 'delete_entry',
                'description'  => 'Delete one entry.',
                'input_schema' => ['properties' => ['entry_id' => ['type' => 'integer']], 'required' => ['entry_id']],
                'annotations'  => ['destructive' => true],
                'handler'      => static function (): array {
                    ++self::$deleted;

                    return ['deleted' => true];
                },
            ],
            [
                'name'         => 'save_secret',
                'description'  => 'A write.',
                'input_schema' => ['properties' => ['value' => ['type' => 'string']]],
                'handler'      => static fn () => ['saved' => true],
            ],
            [
                'name'         => 'preview_change',
                'description'  => 'Changes a preview in the assistant.',
                'input_schema' => ['properties' => ['value' => ['type' => 'string']]],
                'meta'         => ['assistant_preview' => true],
                'handler'      => static fn () => ['previewed' => true],
            ],
            [
                'name'        => 'explode',
                'description' => 'Fails.',
                'handler'     => static function (): void {
                    throw new \RuntimeException('secret path /var/www');
                },
            ],
            ['name' => 'get_entry', 'description' => 'Duplicate, ignored.', 'handler' => 'strlen'],
            ['name' => 'bad name!', 'description' => 'Invalid, skipped.', 'handler' => 'strlen'],
        ];
    }
}
