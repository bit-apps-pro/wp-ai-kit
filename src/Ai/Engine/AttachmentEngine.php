<?php

namespace BitApps\AiKit\Ai\Engine;

use BitApps\AiKit\Ai\Attachments\AttachmentStore;
use BitApps\AiKit\Security\UntrustedContent;

/**
 * Puts a user's attached files into the transcript just before it goes to the
 * engine, so the stored conversation keeps only their names and ids.
 *
 * A user message with `attachments` gets `parts`: its text, then each file — the
 * text of text files and Word documents (fenced as untrusted content), images and
 * PDFs as base64 for engines that read them. A file that expired since becomes a
 * short note, so later turns still make sense.
 *
 * Every model call re-sends the transcript, so images and PDFs from earlier
 * messages go along only while they fit one message's size limit, newest first:
 * a follow-up about the last screenshot still sees it, but files piling up over
 * a long chat do not grow every request past the provider's or PHP's limits.
 */
final class AttachmentEngine implements EngineInterface, SupportsAttachments
{
    public function __construct(private EngineInterface $inner, private AttachmentStore $store, private int $userId) {}

    public function key(): string
    {
        return $this->inner->key();
    }

    public function label(): string
    {
        return $this->inner->label();
    }

    public function attachmentKinds(): array
    {
        return $this->inner instanceof SupportsAttachments ? $this->inner->attachmentKinds() : [];
    }

    public function maxAttachmentBytes(): int
    {
        return $this->inner instanceof SupportsAttachments ? $this->inner->maxAttachmentBytes() : 0;
    }

    public function send(array $messages, array $tools, string $system, array $options): array
    {
        return $this->inner->send($this->withAllFiles($messages), $tools, $system, $options);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private function withAllFiles(array $messages): array
    {
        $budget = (int) $this->store->limits()['max_total_bytes'];
        if ($this->maxAttachmentBytes() > 0) {
            $budget = min($budget, $this->maxAttachmentBytes());
        }
        // Newest message first, so the budget goes to the files the user just talked about.
        for ($i = \count($messages) - 1; $i >= 0; --$i) {
            $messages[$i] = $this->withFiles($messages[$i], $budget);
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $message
     * @param int                  $budget  bytes of images and PDFs still allowed in this request; what is sent is taken off
     *
     * @return array<string, mixed>
     */
    private function withFiles(array $message, int &$budget): array
    {
        if ('user' !== ($message['role'] ?? '') || empty($message['attachments']) || !\is_array($message['attachments'])) {
            return $message;
        }

        $readable = $this->attachmentKinds();
        $parts = [['type' => 'text', 'text' => (string) ($message['content'] ?? '')]];
        foreach ($message['attachments'] as $attachment) {
            $id = (string) ($attachment['id'] ?? '');
            $name = (string) ($attachment['name'] ?? 'file');
            $meta = $this->store->get($this->userId, $id);
            if (null === $meta) {
                $parts[] = ['type' => 'text', 'text' => '[Attached file "' . $name . '" is no longer available; files are kept for a day.]'];

                continue;
            }

            $kind = (string) $meta['kind'];
            if (\in_array($kind, [AttachmentStore::KIND_TEXT, AttachmentStore::KIND_DOCX], true)) {
                $parts[] = ['type' => 'text', 'text' => 'Attached file "' . $name . "\":\n" . UntrustedContent::wrap((string) ($meta['text'] ?? ''))];

                continue;
            }

            if (!\in_array($kind, $readable, true)) {
                $parts[] = ['type' => 'text', 'text' => '[Attached file "' . $name . '" cannot be read by this AI engine.]'];

                continue;
            }
            $size = (int) ($meta['size'] ?? 0);
            if ($size > $budget) {
                $parts[] = ['type' => 'text', 'text' => '[Attached file "' . $name . '" was shared earlier in this chat and is not sent again; ask the user to attach it again if you need it.]'];

                continue;
            }
            $budget -= $size;
            $data = $this->store->base64($this->userId, $id);
            $parts[] = null === $data
                ? ['type' => 'text', 'text' => '[Attached file "' . $name . '" cannot be read by this AI engine.]']
                : ['type' => $kind, 'mime' => (string) $meta['mime'], 'name' => $name, 'data' => $data];
        }
        $message['parts'] = $parts;

        return $message;
    }
}
