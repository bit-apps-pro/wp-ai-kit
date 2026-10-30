<?php

namespace BitApps\AiKit\Ai\Providers;

use BitApps\AiKit\Ai\Engine\SupportsAttachments;

/**
 * OpenAI Chat Completions API.
 */
final class OpenAiProvider extends ChatCompletionsProvider implements SupportsAttachments
{
    public const KEY = 'openai';

    public const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'OpenAI';
    }

    public function attachmentKinds(): array
    {
        return ['image', 'pdf'];
    }

    public function maxAttachmentBytes(): int
    {
        return 0;
    }

    protected function endpoint(): string
    {
        return self::ENDPOINT;
    }

    protected function streamOptions(): array
    {
        return ['stream_options' => ['include_usage' => true]];
    }
}
