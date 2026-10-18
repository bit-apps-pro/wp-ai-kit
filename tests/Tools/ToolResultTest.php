<?php

namespace BitApps\AiKit\Tests\Tools;

use BitApps\AiKit\Tests\TestCase;
use BitApps\AiKit\Tools\ToolResult;

/**
 * @internal
 */
final class ToolResultTest extends TestCase
{
    public function testHandlerReturnShapesAreNormalised(): void
    {
        self::assertSame('hi', ToolResult::from('hi')->textContent());
        self::assertSame(['a' => 1], ToolResult::from(['a' => 1])->structured());
        self::assertSame(['result' => true], ToolResult::from(true)->structured());

        $error = ToolResult::from(new \WP_Error('nope', 'Not found'));
        self::assertTrue($error->isError());
        self::assertSame('nope', $error->errorCode());

        $mcp = ToolResult::from(['content' => [['type' => 'text', 'text' => 'line 1'], ['type' => 'text', 'text' => 'line 2']], 'isError' => false]);
        self::assertSame("line 1\nline 2", $mcp->textContent());
        self::assertCount(2, $mcp->toMcp()['content'], 'raw MCP content blocks pass through');
    }

    public function testRowsAreReadFromCollectionsWithoutAnOrm(): void
    {
        $collection = new class {
            public function toArray(): array
            {
                return [['id' => 1], ['id' => 2]];
            }
        };
        $model = new class {
            public function toArray(): array
            {
                return ['id' => 9];
            }
        };

        self::assertSame([['id' => 1], ['id' => 2]], ToolResult::rows($collection));
        self::assertSame([['id' => 9]], ToolResult::rows($model));
        self::assertSame([['id' => 3]], ToolResult::rows(new \ArrayIterator([(object) ['id' => 3]])));
        self::assertSame([], ToolResult::rows(null));
    }

    public function testMcpShapeWrapsListsForStructuredContent(): void
    {
        $result = ToolResult::json([['id' => 1]])->toMcp();

        self::assertFalse($result['isError']);
        self::assertSame(['items' => [['id' => 1]]], $result['structuredContent']);
        self::assertSame('text', $result['content'][0]['type']);
        self::assertArrayNotHasKey('structuredContent', ToolResult::error('x')->toMcp());
    }

    public function testReplacingStructuredDataRegeneratesJsonText(): void
    {
        $result = ToolResult::json(['email' => 'jane@example.com'])->withStructured(['email' => 'j***@example.com']);

        self::assertStringNotContainsString('jane@', $result->textContent());
        self::assertStringContainsString('j***@example.com', $result->textContent());

        $summary = ToolResult::json(['email' => 'jane@example.com'], 'Entry from jane@example.com')->withStructured(['email' => 'x']);
        self::assertStringNotContainsString('jane@', $summary->textContent(), 'a summary written from unmasked data is replaced');

        $raw = ToolResult::from(['content' => [['type' => 'text', 'text' => 'jane@example.com']], 'structuredContent' => ['email' => 'jane@example.com']])->withStructured(['email' => 'x']);
        self::assertStringNotContainsString('jane@', wp_json_encode($raw->toMcp()), 'original content blocks are dropped');
    }
}
