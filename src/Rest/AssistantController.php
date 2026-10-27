<?php

namespace BitApps\AiKit\Rest;

use BitApps\AiKit\Contract;
use BitApps\AiKit\Kit;

/**
 * `/ai/assistant/*`: the chat panel. Sends a message, approves or cancels the
 * waiting action, starts over, and manages earlier conversations.
 *
 * The browser sends ids and choices, never text the model should trust: the
 * plugin turns `context` (which screen, which record, what was mentioned) into
 * the sentence the system prompt gets through the `chatContextHook` filter, and
 * may add to every reply through `chatViewHook` (a builder preview, say).
 */
final class AssistantController
{
    /** Seconds per model call (the provider timeout) plus margin, times the allowed steps. */
    private const SECONDS_PER_STEP = 95;

    public function __construct(private Kit $kit) {}

    /** @param \WP_REST_Request $request */
    public function view($request)
    {
        return Response::ok($this->decorated($this->kit->assistant()->view($this->userId()), $request));
    }

    /** @param \WP_REST_Request $request */
    public function chat($request)
    {
        $params = Response::params($request);
        $message = Response::string($params, 'message');
        if ('' === trim($message)) {
            return Response::error('ai_empty_message', 'Type a message first.', 400);
        }

        $this->extendTimeLimit();
        $context = $this->chatContext($params, $request);
        $view = $this->kit->assistant()->chat($this->userId(), $message, $context['summary'], $context['about']);

        return $this->reply($view, $request);
    }

    /** @param \WP_REST_Request $request */
    public function decide($request)
    {
        $params = Response::params($request);
        $approve = Response::bool($params, 'approve');
        if (null === $approve) {
            return Response::error('ai_missing_decision', 'Say whether the action is approved.', 400);
        }

        $this->extendTimeLimit();
        $context = $this->chatContext($params, $request);

        return $this->reply($this->kit->assistant()->decide($this->userId(), $approve, $context['summary']), $request);
    }

    /** @param \WP_REST_Request $request */
    public function reset($request)
    {
        $this->switching('reset', null);

        return Response::ok($this->decorated($this->kit->assistant()->reset($this->userId()), $request));
    }

    /** @param \WP_REST_Request $request */
    public function history($request)
    {
        $params = Response::params($request);

        return Response::ok($this->kit->assistant()->history(
            $this->userId(),
            mb_substr(Response::string($params, 'search'), 0, 100),
            Response::int($params, 'limit', 20),
            Response::int($params, 'offset', 0),
            (bool) Response::bool($params, 'archived', false)
        ));
    }

    /** @param \WP_REST_Request $request */
    public function open($request)
    {
        $id = Response::urlParam($request, 'id');
        $this->switching('open', $id);
        $view = $this->kit->assistant()->open($this->userId(), $id);

        return Response::ok($this->decorated($view, $request), isset($view['error']) ? 404 : 200);
    }

    /**
     * PATCH: `title` renames; `pinned` / `archived` set the history flags.
     *
     * @param \WP_REST_Request $request
     */
    public function update($request)
    {
        $params = Response::params($request);
        $id = Response::urlParam($request, 'id');
        $assistant = $this->kit->assistant();
        $view = null;

        if (isset($params['title'])) {
            $view = $assistant->rename($this->userId(), $id, Response::string($params, 'title'));
            if (isset($view['error'])) {
                return Response::ok($this->decorated($view, $request), 404);
            }
        }

        $flags = [];
        foreach (['pinned', 'archived'] as $flag) {
            $value = Response::bool($params, $flag);
            if (null !== $value) {
                $flags[$flag] = $value;
            }
        }
        if ([] !== $flags) {
            $view = $assistant->setFlags($this->userId(), $id, $flags);
            if (isset($view['error'])) {
                return Response::ok($this->decorated($view, $request), 404);
            }
        }

        if (null === $view) {
            return Response::error('ai_nothing_to_change', 'Give a title, pinned or archived.', 400);
        }

        return Response::ok($this->decorated($view, $request));
    }

    /**
     * DELETE one conversation, or every conversation of the user (`all`).
     *
     * @param \WP_REST_Request $request
     */
    public function forget($request)
    {
        $id = Response::urlParam($request, 'id');
        $this->switching('forget', $id);
        $view = $this->kit->assistant()->forget($this->userId(), $id);

        return Response::ok($this->decorated($view, $request));
    }

    private function userId(): int
    {
        return (int) get_current_user_id();
    }

    /** Tell the plugin the open conversation is about to change (a builder preview belongs to it). */
    private function switching(string $event, ?string $conversationId): void
    {
        do_action(Contract::conversationSwitchHook($this->kit->context()->slug()), $this->userId(), $event, $conversationId);
    }

    /**
     * The plugin's say on a turn: `summary` for the system prompt, `about` stored
     * with a new conversation. Nothing the browser sent reaches the model unless
     * the plugin's filter puts it there.
     *
     * @param array<string, mixed> $params
     * @param \WP_REST_Request     $request
     *
     * @return array{summary: string, about: array<string, mixed>}
     */
    private function chatContext(array $params, $request): array
    {
        $context = apply_filters(
            Contract::chatContextHook($this->kit->context()->slug()),
            ['summary' => '', 'about' => [], 'input' => Response::array($params, 'context')],
            $this->userId(),
            $request
        );

        return [
            'summary' => \is_array($context) && \is_string($context['summary'] ?? null) ? $context['summary'] : '',
            'about'   => \is_array($context) && \is_array($context['about'] ?? null) ? $context['about'] : [],
        ];
    }

    /**
     * A failed turn still answers with the conversation, under 422, so the panel
     * stays in step with what was saved.
     *
     * @param array<string, mixed> $view
     * @param \WP_REST_Request     $request
     */
    private function reply(array $view, $request): \WP_REST_Response
    {
        return Response::ok($this->decorated($view, $request), isset($view['error']) ? 422 : 200);
    }

    /**
     * The view plus which engine answers (the panel's status line) and whatever
     * the plugin adds.
     *
     * @param array<string, mixed> $view
     * @param \WP_REST_Request     $request
     *
     * @return array<string, mixed>
     */
    private function decorated(array $view, $request): array
    {
        $resolved = $this->kit->assistant()->resolveEngine();
        $view['engine'] = null !== $resolved['engine'] ? ['key' => $resolved['engine']->key(), 'label' => $resolved['engine']->label()] : null;
        $view['engine_reason'] = $resolved['reason'];

        $filtered = apply_filters(Contract::chatViewHook($this->kit->context()->slug()), $view, $this->userId(), $request);

        return \is_array($filtered) ? $filtered : $view;
    }

    /** A turn may run several model calls; the default request budget is too short for that. */
    private function extendTimeLimit(): void
    {
        if (\function_exists('set_time_limit') && !str_contains((string) ini_get('disable_functions'), 'set_time_limit')) {
            $steps = (int) ($this->kit->aiSettings()->read()['max_turns'] ?? 6);
            @set_time_limit(max(180, $steps * self::SECONDS_PER_STEP)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- some hosts forbid it; the default limit then applies.
        }
    }
}
