<?php

namespace BitApps\AiKit\Tests\Tools;

use BitApps\AiKit\Tests\TestCase;
use BitApps\AiKit\Tools\Scopes;
use BitApps\AiKit\Tools\ToolDefinition;

/**
 * @internal
 */
final class ToolDefinitionTest extends TestCase
{
    public function testBitCrmArrayToolsConvertAsTheyAre(): void
    {
        $tool = ToolDefinition::fromArray([
            'name'        => 'get_lead',
            'title'       => 'Get Lead',
            'description' => 'Get a single lead by its ID.',
            'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
            'capability'  => ['bit_crm_lead_view', 'bit_crm_admin'],
            'handler'     => static fn (array $args) => ['content' => [['type' => 'text', 'text' => 'ok']]],
        ]);

        self::assertSame('get_lead', $tool->name());
        self::assertSame(['bit_crm_lead_view', 'bit_crm_admin'], $tool->capabilities());
        self::assertSame(Scopes::WRITE, $tool->requiredScope(), 'no annotations: treated as a write');
        self::assertSame('bit-crm/get-lead', $tool->abilityName('bit-crm'));
    }

    public function testMcpAnnotationSpellingsAreAccepted(): void
    {
        $read = ToolDefinition::fromArray(['name' => 'list_tags', 'description' => 'd', 'handler' => 'strlen', 'annotations' => ['readOnlyHint' => true], 'scope' => 'tag:read']);
        $delete = ToolDefinition::fromArray(['name' => 'delete_tag', 'description' => 'd', 'handler' => 'strlen', 'annotations' => ['destructiveHint' => true, 'readOnlyHint' => true]]);

        self::assertTrue($read->isReadOnly());
        self::assertSame('tag:read', $read->requiredScope());
        self::assertSame(Scopes::READ, $read->accessLevel());
        self::assertTrue($delete->isDestructive());
        self::assertFalse($delete->isReadOnly(), 'destructive wins over read-only');
        self::assertSame(Scopes::DESTRUCTIVE, $delete->requiredScope());
    }

    public function testMcpListingKeepsEmptyPropertiesAnObjectAndOffersConfirmation(): void
    {
        $tool = ToolDefinition::fromArray(['name' => 'purge', 'description' => 'Purge.', 'handler' => 'strlen', 'annotations' => ['destructive' => true]]);

        $plain = $tool->toMcp();
        self::assertInstanceOf(\stdClass::class, $plain['inputSchema']['properties']);
        self::assertSame('object', $plain['inputSchema']['type']);
        self::assertTrue($plain['annotations']['destructiveHint']);
        self::assertSame('{"type":"object","properties":{}}', json_encode($plain['inputSchema']));

        $confirmable = $tool->toMcp(true);
        self::assertArrayHasKey('confirmation_token', $confirmable['inputSchema']['properties']);
        self::assertStringContainsString('confirms', $confirmable['description']);
    }

    /**
     * @dataProvider invalidSpecs
     */
    public function testInvalidSpecsAreRejected(array $spec): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ToolDefinition::fromArray($spec);
    }

    public function invalidSpecs(): array
    {
        return [
            'bad name'       => [['name' => 'has space', 'description' => 'd', 'handler' => 'strlen']],
            'no description' => [['name' => 'x', 'handler' => 'strlen']],
            'no handler'     => [['name' => 'x', 'description' => 'd', 'handler' => 'no_such_function_here']],
            'bad tier'       => [['name' => 'x', 'description' => 'd', 'handler' => 'strlen', 'tier' => 'gold']],
            'bad schema'     => [['name' => 'x', 'description' => 'd', 'handler' => 'strlen', 'input_schema' => 'nope']],
        ];
    }
}
