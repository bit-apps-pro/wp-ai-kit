<?php

namespace BitApps\AiKit\Tests;

use BitApps\AiKit\PluginContext;

/**
 * @internal
 */
final class PluginContextTest extends TestCase
{
    public function testRequiredKeysAreEnforced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PluginContext::create(['slug' => 'x']);
    }

    public function testOptionPrefixMustBeSafe(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->context(['option_prefix' => 'Bit Demo']);
    }

    public function testDefaultsFallBackToWordPressCapabilities(): void
    {
        $ctx = $this->context();
        self::assertFalse($ctx->canUse(7));

        $this->grant(7, 'manage_options');
        self::assertTrue($ctx->canUse(7));

        \AiKitTestState::$currentUser = 7;
        self::assertTrue($ctx->can('manage_options'));
        self::assertFalse($ctx->can('edit_posts'));
    }

    public function testPluginCallbacksAndNamesAreUsed(): void
    {
        $ctx = $this->context([
            'can_use'   => static fn ($userId) => 42 === (int) $userId,
            'can'       => static fn (string $cap) => 'bit_demo_tools' === $cap,
            'scope_map' => ['flow:read' => 'read'],
            'urls'      => ['settings' => 'https://example.test/wp-admin/admin.php?page=bit-demo#/ai'],
            'services'  => ['storage' => 'custom'],
        ]);

        self::assertTrue($ctx->canUse(42));
        self::assertFalse($ctx->canUse(1));
        self::assertTrue($ctx->can('bit_demo_tools'));
        self::assertSame('read', $ctx->canonicalScope('flow:read'));
        self::assertSame('write', $ctx->canonicalScope('write'));
        self::assertSame('bit_demo_mcp_settings', $ctx->optionName('mcp_settings'));
        self::assertSame('bit-demo/v1', $ctx->restNamespace());
        self::assertSame('custom', $ctx->service('storage'));
        self::assertNull($ctx->service('audit'));
        self::assertStringContainsString('#/ai', (string) $ctx->url('settings'));
    }

    public function testWebsiteAndIconsAreKeptOnlyWhenWellFormed(): void
    {
        $ctx = $this->context([
            'website_url' => 'javascript:alert(1)',
            'icons'       => [
                ['src' => 'https://example.test/logo.png', 'mimeType' => 'text/html', 'sizes' => ['48x48', 'huge', 3], 'theme' => 'dark'],
                ['src' => 'data:image/png;base64,iVBORw0KGgo='],
                ['src' => 'file:///etc/passwd'],
                ['src' => 'data:text/html;base64,PHNjcmlwdD4='],
                'not an icon',
            ],
        ]);

        self::assertSame('', $ctx->websiteUrl());
        self::assertSame([
            ['src' => 'https://example.test/logo.png', 'sizes' => ['48x48'], 'theme' => 'dark'],
            ['src' => 'data:image/png;base64,iVBORw0KGgo='],
        ], $ctx->icons());
        self::assertSame('https://example.test/', $this->context(['website_url' => 'https://example.test/'])->websiteUrl());
    }

    public function testDescriptionMustBeAString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->context(['description' => ['not', 'a', 'string']]);
    }
}
