<?php

namespace BitApps\AiKit\Rest;

use BitApps\AiKit\Ai\AiException;
use BitApps\AiKit\Ai\Assistant;
use BitApps\AiKit\Contract;
use BitApps\AiKit\Kit;
use BitApps\AiKit\Security\RateLimiter;

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
    /** Audit-trail names of the attachment events (in the tool column; the ID is the resource). */
    public const AUDIT_UPLOADED = 'attachment.uploaded';

    public const AUDIT_DELETED = 'attachment.deleted';

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
        if ('' === trim($message) && [] === Response::array($params, 'attachments')) {
            return Response::error('ai_empty_message', 'Type a message first.', 400);
        }

        $this->extendTimeLimit();
        $context = $this->chatContext($params, $request);
        $attachments = array_values(array_filter(Response::array($params, 'attachments'), 'is_string'));
        $view = $this->kit->assistant()->chat($this->userId(), $message, $context['summary'], $context['about'], $attachments);

        return $this->reply($view, $request);
    }

    /**
     * POST, as chat, answered as server-sent events while the turn runs: `text`
     * (`delta`), `tool` (`name`, `title`, `status`), `step`, then `done` with the
     * chat view and its `status` (422 for a refused turn, as chat answers).
     * Anything refused before the turn starts is a plain JSON error.
     *
     * @param \WP_REST_Request $request
     */
    public function stream($request)
    {
        $params = Response::params($request);
        if ('' === trim(Response::string($params, 'message')) && [] === Response::array($params, 'attachments')) {
            return Response::error('ai_empty_message', 'Type a message first.', 400);
        }

        self::openStream();
        $this->streamTo($request, self::eventWriter());

        exit;
    }

    /**
     * POST, as decide, answered as events like stream: the rest of the turn after
     * an approval (or a cancel) shows as it runs.
     *
     * @param \WP_REST_Request $request
     */
    public function decideStream($request)
    {
        if (null === Response::bool(Response::params($request), 'approve')) {
            return Response::error('ai_missing_decision', 'Say whether the action is approved.', 400);
        }

        self::openStream();
        $this->decideStreamTo($request, self::eventWriter());

        exit;
    }

    /**
     * The streamed turn, written through $write (the REST route echoes it; tests collect it).
     *
     * @param \WP_REST_Request                             $request
     * @param callable(string, array<string, mixed>): void $write
     *
     * @return array<string, mixed> the final chat view
     */
    public function streamTo($request, callable $write): array
    {
        $params = Response::params($request);
        $context = $this->chatContext($params, $request);
        $attachments = array_values(array_filter(Response::array($params, 'attachments'), 'is_string'));
        $message = Response::string($params, 'message');

        return $this->streamTurn($request, $write, fn (Assistant $assistant): array => $assistant->chat($this->userId(), $message, $context['summary'], $context['about'], $attachments));
    }

    /**
     * The streamed rest of a turn after a decision, as streamTo().
     *
     * @param \WP_REST_Request                             $request
     * @param callable(string, array<string, mixed>): void $write
     *
     * @return array<string, mixed> the final chat view
     */
    public function decideStreamTo($request, callable $write): array
    {
        $params = Response::params($request);
        $approve = (bool) Response::bool($params, 'approve');
        $context = $this->chatContext($params, $request);

        return $this->streamTurn($request, $write, fn (Assistant $assistant): array => $assistant->decide($this->userId(), $approve, $context['summary']));
    }

    /**
     * POST multipart (`file`): keep one file for the user's next message. Needs the
     * upload_files capability beside the plugin's own rule, and is rate limited.
     *
     * @param \WP_REST_Request $request
     */
    public function upload($request)
    {
        $store = $this->kit->attachments();
        if (null === $store) {
            return Response::error('ai_attachments_off', 'Attachments are not available here.', 404);
        }
        if (!current_user_can('upload_files')) {
            return Response::error('ai_upload_forbidden', 'This account may not upload files.', 403);
        }

        $limits = $store->limits();
        $attempt = (new RateLimiter($this->kit->context()->optionPrefix()))->attempt('attachments_' . $this->userId(), (int) $limits['uploads_per_window'], (int) $limits['window']);
        if (!$attempt['allowed']) {
            return Response::error('ai_rate_limited', 'Too many uploads. Try again in a few minutes.', 429, ['retry_after' => $attempt['retry_after']]);
        }

        $files = method_exists($request, 'get_file_params') ? $request->get_file_params() : [];
        $file = \is_array($files['file'] ?? null) ? $files['file'] : null;
        if (null === $file || !empty($file['error']) || !\is_string($file['tmp_name'] ?? null)) {
            return Response::error('ai_upload_missing', 'The upload did not arrive. Try again.', 400);
        }

        $started = microtime(true);

        try {
            $attachment = $store->add($this->userId(), (string) ($file['name'] ?? 'file'), $file['tmp_name']);
        } catch (AiException $error) {
            $this->auditAttachment(self::AUDIT_UPLOADED, null, $error->reason(), $started);

            return Response::error($error->reason(), $error->getMessage(), 422);
        }
        $this->auditAttachment(self::AUDIT_UPLOADED, (string) $attachment['id'], null, $started);

        return Response::ok($attachment, 201);
    }

    /**
     * DELETE an attachment the user no longer wants to send.
     *
     * @param \WP_REST_Request $request
     */
    public function deleteAttachment($request)
    {
        $store = $this->kit->attachments();
        $id = Response::urlParam($request, 'id');
        if (null === $store || !$store->delete($this->userId(), $id)) {
            return Response::error('ai_attachment_not_found', 'That file no longer exists.', 404);
        }
        $this->auditAttachment(self::AUDIT_DELETED, $id, null, microtime(true));

        return Response::ok(['deleted' => true]);
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

    /**
     * @param \WP_REST_Request                             $request
     * @param callable(string, array<string, mixed>): void $write
     * @param callable(Assistant): array<string, mixed>    $turn
     *
     * @return array<string, mixed>
     */
    private function streamTurn($request, callable $write, callable $turn): array
    {
        $this->extendTimeLimit();
        $assistant = $this->kit->assistant()->onProgress(static function (array $event) use ($write): void {
            $write((string) $event['type'], $event);
        });
        $reply = $this->reply($turn($assistant), $request);
        $write('done', ['status' => $reply->get_status(), 'view' => $reply->get_data()]);

        return (array) $reply->get_data();
    }

    /**
     * An upload or removal in the audit trail: who, which attachment and how it
     * ended (a refused file has its reason). Never the file's name or content.
     */
    private function auditAttachment(string $event, ?string $id, ?string $error, float $started): void
    {
        try {
            $this->kit->auditLogger()->record([
                'tool'        => $event,
                'status'      => null === $error ? 'ok' : 'error',
                'channel'     => 'assistant',
                'user_id'     => $this->userId(),
                'client_ref'  => null,
                'resource_id' => $id,
                'item_count'  => null === $error ? 1 : 0,
                'args_digest' => null,
                'error_code'  => $error,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $failure) {
            $this->kit->context()->log('error', 'Audit write failed: ' . $failure->getMessage());
        }
    }

    /** @return callable(string, array<string, mixed>): void one server-sent event, sent at once */
    private static function eventWriter(): callable
    {
        return static function (string $event, array $data): void {
            echo 'event: ' . $event . "\n" . 'data: ' . wp_json_encode($data) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in an event stream, not HTML.
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        };
    }

    /** Headers for an event stream, and no output buffering in the way. */
    private static function openStream(): void
    {
        // The turn is saved even when the browser goes away halfway.
        ignore_user_abort(true);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        if (!headers_sent()) {
            status_header(200);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store');
            // nginx: do not buffer this response.
            header('X-Accel-Buffering: no');
        }
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
