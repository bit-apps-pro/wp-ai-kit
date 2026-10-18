<?php

namespace BitApps\AiKit\Tests\Tools;

use BitApps\AiKit\Tests\TestCase;
use BitApps\AiKit\Tools\AccessPolicy;
use BitApps\AiKit\Tools\InputValidator;
use BitApps\AiKit\Tools\Scopes;
use BitApps\AiKit\Tools\ToolDefinition;

/**
 * @internal
 */
final class PolicyAndValidationTest extends TestCase
{
    public function testScopeHierarchy(): void
    {
        self::assertTrue(Scopes::satisfies([Scopes::DESTRUCTIVE], Scopes::READ));
        self::assertTrue(Scopes::satisfies([Scopes::WRITE], Scopes::READ));
        self::assertFalse(Scopes::satisfies([Scopes::READ], Scopes::WRITE));
        self::assertTrue(Scopes::satisfies(['flow:read'], 'flow:read'));
        self::assertFalse(Scopes::satisfies(['flow:read'], 'flow:write'), 'plugin scopes do not nest');
    }

    public function testPolicyDefaultsToReadOnlyAndHonoursLists(): void
    {
        $read = ToolDefinition::fromArray(['name' => 'list', 'description' => 'd', 'handler' => 'strlen', 'annotations' => ['readonly' => true]]);
        $write = ToolDefinition::fromArray(['name' => 'save', 'description' => 'd', 'handler' => 'strlen']);
        $delete = ToolDefinition::fromArray(['name' => 'drop', 'description' => 'd', 'handler' => 'strlen', 'annotations' => ['destructive' => true]]);

        $default = AccessPolicy::fromSettings('garbage');
        self::assertTrue($default->allows($read));
        self::assertFalse($default->allows($write));
        self::assertTrue($default->confirmsDestructive());

        $writes = AccessPolicy::fromSettings(['access_level' => 'write', 'disabled_tools' => ['list']]);
        self::assertFalse($writes->allows($read), 'disabled list wins');
        self::assertTrue($writes->allows($write));
        self::assertFalse($writes->allows($delete));

        $only = AccessPolicy::fromSettings(['access_level' => 'destructive', 'enabled_tools' => ['drop'], 'confirm_destructive' => false]);
        self::assertTrue($only->allows($delete));
        self::assertFalse($only->allows($write));
        self::assertFalse($only->confirmsDestructive());
    }

    public function testValidationCoercesAndRejects(): void
    {
        $schema = [
            'properties' => [
                'form_id' => ['type' => 'integer'],
                'status'  => ['type' => 'string', 'enum' => ['read', 'unread']],
                'full'    => ['type' => 'boolean'],
            ],
            'required' => ['form_id'],
        ];

        $ok = InputValidator::validate($schema, (object) ['form_id' => '12', 'full' => 'true']);
        self::assertTrue($ok['valid']);
        self::assertSame(12, $ok['args']['form_id']);
        self::assertTrue($ok['args']['full']);

        self::assertFalse(InputValidator::validate($schema, [])['valid']);
        self::assertFalse(InputValidator::validate($schema, ['form_id' => 'abc'])['valid']);
        self::assertFalse(InputValidator::validate($schema, ['form_id' => 1, 'status' => 'gone'])['valid']);
        self::assertFalse(InputValidator::validate($schema, 'string')['valid']);
        self::assertTrue(InputValidator::validate([], null)['valid'], 'no arguments for a tool without properties');
    }
}
