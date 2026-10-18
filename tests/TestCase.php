<?php

namespace BitApps\AiKit\Tests;

use BitApps\AiKit\PluginContext;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

abstract class TestCase extends PhpUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        resetAiKitTestState();
    }

    /**
     * A context for a fictional plugin; override keys per test.
     *
     * @param array<string, mixed> $overrides
     */
    protected function context(array $overrides = []): PluginContext
    {
        return PluginContext::create(array_merge([
            'slug'           => 'bit-demo',
            'name'           => 'Bit Demo',
            'version'        => '1.2.3',
            'option_prefix'  => 'bit_demo_',
            'rest_namespace' => 'bit-demo/v1',
        ], $overrides));
    }

    protected function grant(int $userId, string ...$capabilities): void
    {
        foreach ($capabilities as $capability) {
            \AiKitTestState::$capabilities[$userId][$capability] = true;
        }
    }
}
