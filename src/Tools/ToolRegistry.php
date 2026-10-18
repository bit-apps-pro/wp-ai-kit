<?php

namespace BitApps\AiKit\Tools;

use BitApps\AiKit\Contract;
use BitApps\AiKit\PluginContext;

/**
 * The plugin's tools, collected lazily from its providers.
 *
 * Providers are appended on `bitapps_ai_tool_providers_<slug>`. An entry may be
 * a ToolProviderInterface, a callable returning definitions, or a plain list of
 * definition arrays (what Bit Form Pro hands Free through its facade). A broken
 * definition is logged and skipped so one bad tool never takes the site down;
 * the first definition of a name wins.
 */
final class ToolRegistry
{
    /** @var null|array<string, ToolDefinition> */
    private ?array $tools = null;

    public function __construct(private PluginContext $context) {}

    /** @return array<string, ToolDefinition> */
    public function all(): array
    {
        $this->tools ??= $this->collect();

        return $this->tools;
    }

    public function get(string $name): ?ToolDefinition
    {
        return $this->all()[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return null !== $this->get($name);
    }

    /** Forget the collected tools, e.g. after a provider was added at runtime. */
    public function reset(): void
    {
        $this->tools = null;
    }

    /** @return array<string, ToolDefinition> */
    private function collect(): array
    {
        $providers = apply_filters(Contract::toolProvidersHook($this->context->slug()), [], $this->context);
        if (!\is_array($providers)) {
            $this->context->log('warning', 'Tool providers filter returned a non-array; no tools registered.');

            return [];
        }

        $tools = [];
        foreach ($providers as $provider) {
            foreach ($this->definitionsOf($provider) as $definition) {
                $tool = $this->toDefinition($definition);
                if (null === $tool) {
                    continue;
                }

                if (isset($tools[$tool->name()])) {
                    $this->context->log('warning', "Duplicate tool name {$tool->name()} ignored.");

                    continue;
                }

                $tools[$tool->name()] = $tool;
            }
        }

        return $tools;
    }

    /**
     * @param mixed $provider
     *
     * @return array<int, mixed>
     */
    private function definitionsOf($provider): array
    {
        try {
            if ($provider instanceof ToolProviderInterface) {
                $definitions = $provider->definitions();
            } elseif ($provider instanceof ToolDefinition) {
                $definitions = [$provider];
            } elseif (\is_callable($provider)) {
                $definitions = $provider();
            } elseif (\is_array($provider)) {
                $definitions = isset($provider['name']) ? [$provider] : $provider;
            } else {
                $definitions = [];
            }
        } catch (\Throwable $error) {
            $this->context->log('error', 'A tool provider failed: ' . $error->getMessage());

            return [];
        }

        return \is_array($definitions) ? array_values($definitions) : [];
    }

    /** @param mixed $definition */
    private function toDefinition($definition): ?ToolDefinition
    {
        if ($definition instanceof ToolDefinition) {
            return $definition;
        }

        if (!\is_array($definition)) {
            return null;
        }

        try {
            return ToolDefinition::fromArray($definition);
        } catch (\InvalidArgumentException $error) {
            $this->context->log('warning', 'Invalid tool definition skipped: ' . $error->getMessage());

            return null;
        }
    }
}
