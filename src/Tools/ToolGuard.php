<?php

namespace BitApps\AiKit\Tools;

use BitApps\AiKit\PluginContext;

/**
 * The single authorization pipeline every tool call goes through.
 *
 * Order: an authenticated user → the plugin's "may use AI/MCP" rule → the tool's
 * capability → the grant's scope (OAuth tokens and API keys only) → the site's
 * access policy for the channel → the tool's resource check (form, contact,
 * flow…). Each step is the plugin's own rule handed in through PluginContext or
 * the tool definition; the guard only fixes the order and refuses by default.
 */
final class ToolGuard
{
    /** @var callable(string): AccessPolicy */
    private $policyFor;

    /**
     * @param callable(string): AccessPolicy $policyFor channel → policy
     */
    public function __construct(private PluginContext $context, callable $policyFor)
    {
        $this->policyFor = $policyFor;
    }

    public function policy(string $channel): AccessPolicy
    {
        $policy = ($this->policyFor)($channel);

        return $policy instanceof AccessPolicy ? $policy : new AccessPolicy();
    }

    /** Whether the tool appears in this caller's tools/list. */
    public function isVisible(ToolDefinition $tool, ToolContext $call): bool
    {
        return $this->checkAccess($tool, $call)->allowed();
    }

    /**
     * The full check for a call, including the tool's resource rule.
     *
     * @param array<string, mixed> $args validated arguments
     */
    public function check(ToolDefinition $tool, ToolContext $call, array $args): GuardResult
    {
        $result = $this->checkAccess($tool, $call);
        if (!$result->allowed()) {
            return $result;
        }

        return $this->checkResource($tool, $call, $args);
    }

    /**
     * Everything but the resource rule, which needs validated arguments. Runs before
     * argument validation so a caller without access learns nothing about the schema.
     */
    public function access(ToolDefinition $tool, ToolContext $call): GuardResult
    {
        return $this->checkAccess($tool, $call);
    }

    /**
     * The tool's resource rule alone (form, contact, flow …).
     *
     * @param array<string, mixed> $args validated arguments
     */
    public function checkResource(ToolDefinition $tool, ToolContext $call, array $args): GuardResult
    {
        $resourceCheck = $tool->resourceCheck();
        if (null !== $resourceCheck && true !== $resourceCheck($args, $call)) {
            return GuardResult::deny(GuardResult::RESOURCE_FORBIDDEN, 'That item does not exist or this account cannot access it.');
        }

        return GuardResult::allow();
    }

    private function checkAccess(ToolDefinition $tool, ToolContext $call): GuardResult
    {
        if ($call->userId() <= 0) {
            return GuardResult::deny(GuardResult::NOT_AUTHENTICATED, 'Sign in is required.');
        }

        if (!$this->context->canUse($call->userId())) {
            return GuardResult::deny(GuardResult::NOT_ALLOWED, 'This account may not use AI tools on this site.');
        }

        $capabilities = $tool->capabilities();
        if ([] !== $capabilities) {
            $granted = false;
            foreach ($capabilities as $capability) {
                if ($this->context->can($capability)) {
                    $granted = true;

                    break;
                }
            }
            if (!$granted) {
                return GuardResult::deny(GuardResult::MISSING_CAPABILITY, 'Your account lacks the permission this tool needs.');
            }
        }

        if ($call->isScopeLimited()) {
            $required = $this->context->canonicalScope($tool->requiredScope());
            $granted = array_map([$this->context, 'canonicalScope'], (array) $call->scopes());

            if (!Scopes::satisfies($granted, $required)) {
                return GuardResult::deny(GuardResult::INSUFFICIENT_SCOPE, "This connection was not granted the \"{$required}\" scope.", $required);
            }
        }

        if (!$this->policy($call->channel())->allows($tool)) {
            return GuardResult::deny(GuardResult::TOOL_DISABLED, 'This tool is turned off for AI clients on this site.');
        }

        return GuardResult::allow();
    }
}
