<?php

namespace BitApps\AiKit\Ai;

use BitApps\AiKit\Ai\Attachments\AttachmentStore;
use BitApps\AiKit\PluginContext;

/**
 * A user's assistant chats and attached files in WordPress's personal data tools
 * (Tools → Export / Erase Personal Data), and gone with the user's account. Chats
 * can quote entry data, so they count as the user's personal data.
 */
final class AssistantPrivacy
{
    /** Conversations per export page. */
    private const PAGE = 20;

    public function __construct(private PluginContext $context, private ConversationStore $conversations, private ?AttachmentStore $attachments) {}

    /** Every chat and file of the user, from this plugin (the kit's copy in each plugin keeps its own). */
    public function forget(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $this->conversations->repository()->deleteAll($userId);
        $this->attachments?->deleteAllOfUser($userId);
    }

    /**
     * wp_privacy_personal_data_exporters: one page of the user's chats.
     *
     * @return array{data: array<int, array<string, mixed>>, done: bool}
     */
    public function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }
        $userId = (int) $user->ID;
        $page = max(1, $page);
        $list = $this->conversations->repository()->list($userId, '', self::PAGE, ($page - 1) * self::PAGE);

        $data = [];
        foreach ($list['items'] as $item) {
            $conversation = $this->conversations->repository()->find($userId, (string) $item['id']);
            if (null === $conversation) {
                continue;
            }
            $data[] = [
                'group_id'    => $this->context->slug() . '-ai-chats',
                'group_label' => sprintf('%s AI assistant chats', $this->context->name()),
                'item_id'     => $this->context->slug() . '-ai-chat-' . $conversation['id'],
                'data'        => [
                    ['name' => 'Title', 'value' => $conversation['title']],
                    ['name' => 'Started', 'value' => gmdate('Y-m-d H:i:s', (int) $conversation['created']) . ' UTC'],
                    ['name' => 'Last used', 'value' => gmdate('Y-m-d H:i:s', (int) $conversation['updated']) . ' UTC'],
                    ['name' => 'Messages', 'value' => self::transcript($conversation['messages'])],
                ],
            ];
        }

        return ['data' => $data, 'done' => $page * self::PAGE >= (int) $list['total']];
    }

    /**
     * wp_privacy_personal_data_erasers: the user's chats and files.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
     */
    public function erase(string $email): array
    {
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        $removed = (int) $this->conversations->repository()->list((int) $user->ID, '', 1, 0)['total'] > 0;
        $this->forget((int) $user->ID);
        $this->conversations->clear((int) $user->ID);

        return ['items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    /**
     * What the user and the assistant wrote; tool calls and their results are
     * left out (they are what the site already holds).
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private static function transcript(array $messages): string
    {
        $lines = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            $text = 'user' === $role ? (string) ($message['content'] ?? '') : ('assistant' === $role ? (string) ($message['text'] ?? '') : '');
            if ('' === trim($text)) {
                continue;
            }
            $files = 'user' === $role && \is_array($message['attachments'] ?? null)
                ? array_map(static fn ($file) => (string) ($file['name'] ?? 'file'), $message['attachments'])
                : [];
            $lines[] = ('user' === $role ? 'You: ' : 'Assistant: ') . $text . ([] === $files ? '' : ' [Attached: ' . implode(', ', $files) . ']');
        }

        return implode("\n\n", $lines);
    }
}
