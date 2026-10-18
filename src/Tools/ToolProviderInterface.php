<?php

namespace BitApps\AiKit\Tools;

/**
 * A plugin's group of related tools (forms, entries, flows, contacts …).
 *
 * Providers are appended on the `bitapps_ai_tool_providers_<slug>` filter and
 * instantiated cheaply: definitions() is called only when a tool list is needed.
 */
interface ToolProviderInterface
{
    /**
     * @return array<int, array<string, mixed>|ToolDefinition> definitions or ToolDefinition::fromArray() specs
     */
    public function definitions(): array;
}
