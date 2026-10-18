<?php

namespace BitApps\AiKit\Tools;

use BitApps\AiKit\Audit\AuditLoggerInterface;
use BitApps\AiKit\Audit\AuditStatus;
use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Security\Digest;
use BitApps\AiKit\Security\PiiMasker;
use BitApps\AiKit\Security\RateLimiter;
use BitApps\AiKit\Security\SecretRedactor;
use BitApps\AiKit\Tools\Confirmation\ConfirmationGate;

/**
 * Runs one tool call for any channel (MCP, assistant, Abilities API, CLI).
 *
 * Every call: look up → authorize → validate and coerce arguments → resource check → rate-limit →
 * two-step confirmation for destructive tools → handler → redact credentials →
 * mask personal data unless the grant allows it → audit. Transports only build a
 * ToolContext and convert the ToolResult; none of them can skip a step.
 */
final class ToolRunner
{
    /** @var array{calls_per_minute: int, writes_per_minute: int} */
    private array $limits;

    /**
     * @param array{calls_per_minute?: int, writes_per_minute?: int} $limits
     */
    public function __construct(
        private PluginContext $context,
        private ToolRegistry $registry,
        private ToolGuard $guard,
        private RateLimiter $limiter,
        private SecretRedactor $redactor,
        private ?PiiMasker $masker,
        private AuditLoggerInterface $audit,
        private ConfirmationGate $confirmation,
        array $limits = []
    ) {
        $this->limits = [
            'calls_per_minute'  => (int) ($limits['calls_per_minute'] ?? 120),
            'writes_per_minute' => (int) ($limits['writes_per_minute'] ?? 30),
        ];
    }

    /**
     * The tools this caller may see, in MCP `tools/list` shape.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTools(ToolContext $call): array
    {
        $confirm = $this->guard->policy($call->channel())->confirmsDestructive() && !$call->isUnattended();
        $tools = [];
        foreach ($this->registry->all() as $tool) {
            if ($this->guard->isVisible($tool, $call)) {
                $tools[] = $tool->toMcp($confirm);
            }
        }

        return $tools;
    }

    /** Whether this caller may see (and so try to call) the tool. */
    public function isVisible(ToolDefinition $tool, ToolContext $call): bool
    {
        return $this->guard->isVisible($tool, $call);
    }

    /** @return ToolDefinition[] the definitions this caller may see */
    public function visibleDefinitions(ToolContext $call): array
    {
        return array_values(array_filter(
            $this->registry->all(),
            fn (ToolDefinition $tool) => $this->guard->isVisible($tool, $call)
        ));
    }

    /** @param mixed $rawArgs */
    public function call(string $name, $rawArgs, ToolContext $call): ToolResult
    {
        $started = microtime(true);
        $tool = $this->registry->get($name);

        if (null === $tool) {
            return $this->finish(null, $name, $call, [], ToolResult::error("Unknown tool: {$name}", 'unknown_tool'), AuditStatus::DENIED, $started);
        }

        $args = \is_object($rawArgs) ? json_decode((string) wp_json_encode($rawArgs), true) : $rawArgs;
        $args = \is_array($args) ? $args : [];

        if (isset($args[ConfirmationGate::ARGUMENT])) {
            $token = $args[ConfirmationGate::ARGUMENT];
            unset($args[ConfirmationGate::ARGUMENT]);
            if (\is_string($token) && '' !== $token) {
                $call = $call->withConfirmationToken($token);
            }
        }

        $decision = $this->guard->access($tool, $call);
        if (!$decision->allowed()) {
            return $this->refuse($tool, $name, $call, $args, $decision, $started);
        }

        // Counted before validation, so malformed or probing calls use up the budget too.
        if (!$this->withinRateLimits($tool, $call)) {
            return $this->finish($tool, $name, $call, $args, ToolResult::error('Too many requests. Wait a minute and try again.', 'rate_limited'), AuditStatus::RATE_LIMITED, $started);
        }

        $validation = InputValidator::validate($tool->inputSchema(), $args);
        if (!$validation['valid']) {
            return $this->finish($tool, $name, $call, $args, ToolResult::error((string) $validation['error'], 'invalid_arguments'), AuditStatus::ERROR, $started);
        }
        $args = $validation['args'];

        $decision = $this->guard->checkResource($tool, $call, $args);
        if (!$decision->allowed()) {
            return $this->refuse($tool, $name, $call, $args, $decision, $started);
        }

        if ($this->needsConfirmation($tool, $call)) {
            $token = $call->confirmationToken();
            if (null === $token) {
                $issued = $this->confirmation->issue($tool, $args, $call->userId());
                $what = $tool->isDestructive() ? 'cannot be undone and needs' : 'changes data and needs';
                $result = ToolResult::error(
                    "Not executed: {$tool->title()} {$what} the user's explicit confirmation. Describe exactly what will happen, ask the user to confirm, then call {$name} again with the same arguments and confirmation_token \"{$issued}\" (valid for " . (int) ($this->confirmation->ttl() / 60) . ' minutes).',
                    'confirmation_required'
                )->withMeta('confirmation_token', $issued);

                return $this->finish($tool, $name, $call, $args, $result, AuditStatus::CONFIRMATION_REQUIRED, $started);
            }

            if (!$this->confirmation->consume($token, $tool, $args, $call->userId())) {
                return $this->finish($tool, $name, $call, $args, ToolResult::error('The confirmation token is invalid, expired, already used, or was issued for different arguments. Call the tool again without a token to get a new one.', 'invalid_confirmation'), AuditStatus::DENIED, $started);
            }
        }

        try {
            $result = ToolResult::from(\call_user_func($tool->handler(), $args, $call));
        } catch (\Throwable $error) {
            $this->context->log('error', "Tool {$name} failed: " . $error->getMessage(), ['file' => $error->getFile(), 'line' => $error->getLine()]);
            $result = ToolResult::error('The tool failed unexpectedly. The site error log has details.', 'tool_failed');
        }

        $result = $this->sanitize($tool, $call, $result);

        return $this->finish($tool, $name, $call, $args, $result, $result->isError() ? AuditStatus::ERROR : AuditStatus::OK, $started);
    }

    /**
     * Destructive tools ask first wherever the site policy says so. In the
     * assistant every change asks first: tool results there include text visitors
     * wrote, and the chat has a confirm button, so a prompt hidden in an entry can
     * never change anything on its own. The exception is a tool that, in the
     * assistant, only changes a preview the user applies or discards afterwards
     * (`meta.assistant_preview`): the apply step is the confirmation.
     */
    private function needsConfirmation(ToolDefinition $tool, ToolContext $call): bool
    {
        if ($call->isUnattended()) {
            return false;
        }
        if (ToolContext::CHANNEL_ASSISTANT === $call->channel() && !$tool->isReadOnly()) {
            return true !== ($tool->meta()['assistant_preview'] ?? false);
        }

        return $tool->isDestructive() && $this->guard->policy($call->channel())->confirmsDestructive();
    }

    /**
     * Per user and channel. Never keyed on what the caller sends (a User-Agent
     * can be changed on every request).
     */
    private function withinRateLimits(ToolDefinition $tool, ToolContext $call): bool
    {
        $who = $call->userId() . ':' . $call->channel();

        if (!$this->limiter->attempt('calls:' . $who, $this->limits['calls_per_minute'], 60)['allowed']) {
            return false;
        }

        if (!$tool->isReadOnly()) {
            return $this->limiter->attempt('writes:' . $who, $this->limits['writes_per_minute'], 60)['allowed'];
        }

        return true;
    }

    private function sanitize(ToolDefinition $tool, ToolContext $call, ToolResult $result): ToolResult
    {
        $structured = $result->structured();
        if (null === $structured) {
            // Plain text cannot be masked field by field.
            if ($tool->containsPersonalData() && !$call->personalDataAllowed() && !$result->isError() && '' !== $result->textContent()) {
                return ToolResult::error('This tool returned text that cannot be checked for personal data, so it was withheld.', 'unmaskable_result');
            }

            return $result;
        }

        $structured = $this->redactor->redact($structured);

        if (null !== $this->masker && $tool->containsPersonalData() && !$call->personalDataAllowed()) {
            $structured = $this->masker->mask($structured);
        }

        return $result->withStructured(\is_array($structured) ? $structured : []);
    }

    /** @param array<string, mixed> $args */
    private function refuse(ToolDefinition $tool, string $name, ToolContext $call, array $args, GuardResult $decision, float $started): ToolResult
    {
        $refusal = ToolResult::error($decision->message(), $decision->code())->withMeta('required_scope', $decision->requiredScope());

        return $this->finish($tool, $name, $call, $args, $refusal, AuditStatus::DENIED, $started);
    }

    /** @param array<string, mixed> $args */
    private function finish(?ToolDefinition $tool, string $name, ToolContext $call, array $args, ToolResult $result, string $status, float $started): ToolResult
    {
        $resourceArg = null !== $tool ? ($tool->meta()['resource_argument'] ?? null) : null;
        $resource = \is_string($resourceArg) && isset($args[$resourceArg]) && is_scalar($args[$resourceArg]) ? (string) $args[$resourceArg] : null;

        try {
            $this->audit->record([
                'tool'        => mb_substr($name, 0, 191),
                'status'      => $status,
                'channel'     => $call->channel(),
                'user_id'     => $call->userId(),
                'client_ref'  => null !== $call->clientRef() ? mb_substr($call->clientRef(), 0, 191) : null,
                'resource_id' => $resource,
                'item_count'  => self::itemCount($result),
                'args_digest' => Digest::of($args),
                'error_code'  => $result->isError() ? $result->errorCode() : null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $error) {
            $this->context->log('error', 'Audit write failed: ' . $error->getMessage());
        }

        return $result;
    }

    private static function itemCount(ToolResult $result): int
    {
        $structured = $result->structured();
        if (!\is_array($structured)) {
            return 0;
        }

        foreach (['items', 'entries', 'rows', 'data'] as $key) {
            if (isset($structured[$key]) && \is_array($structured[$key])) {
                return \count($structured[$key]);
            }
        }

        return array_values($structured) === $structured ? \count($structured) : 1;
    }
}
