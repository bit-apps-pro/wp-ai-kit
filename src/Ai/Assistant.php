<?php

namespace BitApps\AiKit\Ai;

use BitApps\AiKit\Ai\Engine\CoreAiClientEngine;
use BitApps\AiKit\Ai\Engine\EngineInterface;
use BitApps\AiKit\Ai\Providers\ClaudeProvider;
use BitApps\AiKit\Ai\Providers\GeminiProvider;
use BitApps\AiKit\Ai\Providers\OpenAiProvider;
use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Security\RateLimiter;
use BitApps\AiKit\Security\UntrustedContent;
use BitApps\AiKit\Settings\AiSettings;
use BitApps\AiKit\Tools\ToolContext;
use BitApps\AiKit\Tools\ToolRegistry;
use BitApps\AiKit\Tools\ToolRunner;

/**
 * The in-admin AI assistant for one plugin: picks the engine, builds the system
 * prompt, runs the agent loop over the plugin's tools as the signed-in user,
 * keeps the conversation server-side, and returns what the chat panel shows.
 */
final class Assistant
{
    public const MAX_MESSAGE_CHARS = 4000;

    /** @var null|callable(): ?EngineInterface test seam */
    private $engineFactory;

    public function __construct(
        private PluginContext $context,
        private AiSettings $settings,
        private ToolRunner $runner,
        private ToolRegistry $registry,
        private ConversationStore $store,
        private RateLimiter $limiter,
        ?callable $engineFactory = null
    ) {
        $this->engineFactory = $engineFactory;
    }

    /**
     * The engine the settings select, or null with the reason.
     *
     * @return array{engine: ?EngineInterface, reason: ?string}
     */
    public function resolveEngine(): array
    {
        if (null !== $this->engineFactory) {
            return ['engine' => ($this->engineFactory)(), 'reason' => null];
        }

        $settings = $this->settings->read();
        $mode = (string) $settings['engine'];

        if ('provider' !== $mode && CoreAiClientEngine::available()) {
            return ['engine' => new CoreAiClientEngine(), 'reason' => null];
        }
        if ('wordpress' === $mode) {
            return ['engine' => null, 'reason' => 'No AI provider is set up in WordPress Settings → Connectors (needs WordPress 7.0 or newer).'];
        }

        $provider = (string) $settings['provider'];
        $key = $this->settings->apiKey($provider);
        if ('' === $key) {
            return ['engine' => null, 'reason' => 'auto' === $mode
                ? 'Set up an AI provider in WordPress Settings → Connectors, or save an API key in the AI engine section of the assistant settings.'
                : 'Save an API key for the chosen provider in the AI engine section of the assistant settings.'];
        }

        return match ($provider) {
            OpenAiProvider::KEY => ['engine' => new OpenAiProvider($key), 'reason' => null],
            GeminiProvider::KEY => ['engine' => new GeminiProvider($key), 'reason' => null],
            default             => ['engine' => new ClaudeProvider($key), 'reason' => null],
        };
    }

    /**
     * Send the user's message and run the loop.
     *
     * @return array<string, mixed> the chat view, or `error` + `reason`
     */
    /**
     * @param array<string, mixed> $context stored with a new conversation (e.g. the form it is about)
     */
    public function chat(int $userId, string $message, string $pageContext = '', array $context = []): array
    {
        $message = trim($message);
        if ('' === $message) {
            return $this->failure($userId, 'Type a message first.', 'empty_message');
        }
        if (mb_strlen($message) > self::MAX_MESSAGE_CHARS) {
            return $this->failure($userId, 'That message is too long (' . self::MAX_MESSAGE_CHARS . ' characters at most).', 'message_too_long');
        }

        $conversation = $this->store->load($userId);
        if (null !== $conversation['pending']) {
            return $this->failure($userId, 'Confirm or cancel the waiting action first.', 'pending_confirmation');
        }

        $ready = $this->ready($userId);
        if (isset($ready['error'])) {
            return $ready;
        }

        $limited = $this->overDailyLimit($userId);
        if (null !== $limited) {
            return $limited;
        }

        $messages = $conversation['messages'];
        $messages[] = ['role' => 'user', 'content' => $message, 'at' => time()];

        return $this->execute($userId, $ready['engine'], $messages, $pageContext, static fn (AgentLoop $loop, string $system, ToolContext $call) => $loop->run($messages, $system, $call), $context);
    }

    /** Approve or cancel the action the assistant is waiting on. */
    public function decide(int $userId, bool $approve, string $pageContext = ''): array
    {
        $conversation = $this->store->load($userId);
        $pending = $conversation['pending'];
        if (null === $pending) {
            return $this->failure($userId, 'Nothing is waiting for confirmation.', 'no_pending');
        }

        $ready = $this->ready($userId);
        if (isset($ready['error'])) {
            return $ready;
        }

        // A decision runs more model calls on the site's key, so it counts like a message.
        $limited = $this->overDailyLimit($userId);
        if (null !== $limited) {
            return $limited;
        }

        $messages = $conversation['messages'];

        return $this->execute($userId, $ready['engine'], $messages, $pageContext, static fn (AgentLoop $loop, string $system, ToolContext $call) => $loop->resume($messages, $pending, $approve, $system, $call));
    }

    /** Start a new conversation; the current one stays in the history. */
    public function reset(int $userId): array
    {
        $this->store->clear($userId);

        return $this->view($userId);
    }

    /**
     * The user's earlier conversations, newest first (titles, not messages).
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function history(int $userId, string $search = '', int $limit = 20, int $offset = 0): array
    {
        return $this->store->list($userId, trim($search), $limit, $offset);
    }

    /** Continue an earlier conversation. */
    public function open(int $userId, string $conversationId): array
    {
        if (!$this->store->open($userId, $conversationId)) {
            return $this->failure($userId, 'That conversation no longer exists.', 'not_found');
        }

        return $this->view($userId);
    }

    /** Delete one conversation, or every conversation of the user (`all`). */
    public function forget(int $userId, string $conversationId): array
    {
        if ('all' === $conversationId) {
            $this->store->deleteAll($userId);
        } else {
            $this->store->delete($userId, $conversationId);
        }

        return $this->view($userId);
    }

    /**
     * What the chat panel shows: user and assistant messages (tool turns folded
     * into the assistant message as a list of tools used) and the waiting action.
     *
     * @return array<string, mixed>
     */
    public function view(int $userId): array
    {
        $conversation = $this->store->load($userId);
        $items = [];
        $results = [];

        foreach ($conversation['messages'] as $entry) {
            if ('tool' === ($entry['role'] ?? '')) {
                foreach ((array) ($entry['results'] ?? []) as $result) {
                    $results[(string) $result['id']] = !empty($result['is_error']);
                }
            }
        }

        foreach ($conversation['messages'] as $entry) {
            $role = (string) ($entry['role'] ?? '');
            if ('user' === $role) {
                $items[] = ['role' => 'user', 'text' => (string) $entry['content']];
            } elseif ('assistant' === $role) {
                $tools = [];
                foreach ((array) ($entry['tool_calls'] ?? []) as $toolCall) {
                    $definition = $this->registry->get((string) $toolCall['name']);
                    $tools[] = [
                        'name'  => (string) $toolCall['name'],
                        'title' => $definition?->title() ?? (string) $toolCall['name'],
                        'error' => $results[(string) $toolCall['id']] ?? false,
                    ];
                }
                if ('' !== trim((string) $entry['text']) || [] !== $tools) {
                    $items[] = ['role' => 'assistant', 'text' => (string) $entry['text'], 'tools' => $tools];
                }
            }
        }

        $pending = null;
        if (null !== $conversation['pending']) {
            $definition = $this->registry->get((string) $conversation['pending']['name']);
            $pending = [
                'name'        => (string) $conversation['pending']['name'],
                'title'       => $definition?->title() ?? (string) $conversation['pending']['name'],
                'description' => $definition?->description() ?? '',
                'destructive' => null !== $definition && $definition->isDestructive(),
                'input'       => (array) $conversation['pending']['input'],
            ];
        }

        return ['messages' => $items, 'pending' => $pending, 'conversation' => ['id' => $conversation['id'], 'title' => $conversation['title']]];
    }

    /**
     * Engine and access checks shared by chat() and decide().
     *
     * @return array<string, mixed> ['engine' => EngineInterface] or a failure
     */
    private function ready(int $userId): array
    {
        if (!$this->settings->read()['enabled']) {
            return $this->failure($userId, 'The AI assistant is switched off.', 'disabled');
        }
        if (!$this->context->canUse($userId)) {
            return $this->failure($userId, 'This account may not use the AI assistant.', 'forbidden');
        }

        $resolved = $this->resolveEngine();
        if (null === $resolved['engine']) {
            return $this->failure($userId, (string) $resolved['reason'], 'no_engine');
        }

        return ['engine' => $resolved['engine']];
    }

    /**
     * @param array<int, array<string, mixed>>                               $messages transcript before this step
     * @param callable(AgentLoop, string, ToolContext): array<string, mixed> $step
     * @param array<string, mixed>                                           $context
     */
    private function execute(int $userId, EngineInterface $engine, array $messages, string $pageContext, callable $step, array $context = []): array
    {
        $settings = $this->settings->read();
        $call = new ToolContext(ToolContext::CHANNEL_ASSISTANT, $userId, null, 'Assistant · ' . $engine->label(), null, false, (bool) $settings['allow_personal_data']);
        $loop = new AgentLoop($engine, $this->runner, (int) $settings['max_turns'], 4096, CoreAiClientEngine::KEY === $engine->key() ? '' : $this->settings->model($engine->key()));

        try {
            $outcome = $step($loop, $this->systemPrompt($userId, $pageContext), $call);
        } catch (AiException $error) {
            // Nothing is saved: the user can send the same message again.
            return $this->failure($userId, $error->getMessage(), $error->reason());
        }

        // A paused turn's assistant message is in `messages`; its results wait in `pending`.
        $transcript = array_merge($messages, $outcome['messages']);
        $this->store->save($userId, $transcript, $outcome['pending'], $context);

        return $this->view($userId) + ['usage' => $outcome['usage'], 'stop_reason' => $outcome['stop_reason']];
    }

    private function systemPrompt(int $userId, string $pageContext): string
    {
        $user = get_userdata($userId);
        $name = \is_object($user) ? (string) ($user->display_name ?? '') : '';
        $persona = $this->context->service('assistant_persona');

        $prompt = 'You are the AI assistant inside ' . $this->context->name() . ' on the WordPress site "' . get_bloginfo('name') . '". '
            . 'You help ' . ('' !== $name ? $name : 'the signed-in user') . ' understand and manage it, using the tools provided.' . "\n\n"
            . "Rules:\n"
            . "- Look things up with the tools; never invent IDs, numbers, field names or settings.\n"
            . "- Do only what the user asked. Before a change, say briefly what you will do. Actions that cannot be undone are confirmed by the user in the chat; the tool result tells you when you are waiting.\n"
            . "- Personal data in tool results may be masked (for example j***@example.com). Do not try to recover it.\n"
            . "- Keep answers short and concrete: short paragraphs or lists, with the form or entry IDs you used.\n"
            . '- ' . UntrustedContent::GUIDANCE . "\n\n"
            . 'Today is ' . gmdate('l, j F Y') . ' (UTC).';

        if (\is_string($persona) && '' !== trim($persona)) {
            $prompt .= "\n\n" . trim($persona);
        }
        if ('' !== trim($pageContext)) {
            $prompt .= "\n\nWhere the user is now: " . mb_substr(trim($pageContext), 0, 500);
        }

        return $prompt;
    }

    /** @return null|array<string, mixed> the failure when today's budget is used up */
    private function overDailyLimit(int $userId): ?array
    {
        $daily = (int) $this->settings->read()['daily_messages'];
        if ($this->limiter->attempt('assistant_day_' . $userId, $daily, DAY_IN_SECONDS)['allowed']) {
            return null;
        }

        return $this->failure($userId, 'You reached today’s limit of ' . $daily . ' assistant messages.', 'daily_limit');
    }

    /** @return array<string, mixed> */
    private function failure(int $userId, string $message, string $reason): array
    {
        return ['error' => $message, 'reason' => $reason] + $this->view($userId);
    }
}
