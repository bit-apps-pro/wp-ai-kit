<?php

namespace BitApps\AiKit\Ai\Providers;

/**
 * Any service that speaks OpenAI's Chat Completions API at a base URL the site
 * manager sets (Mistral, Groq, OpenRouter, DeepSeek, Together, a company gateway…).
 *
 * The URL is checked when it is saved (https, a public host: AiSettings) and the
 * request goes through wp_safe_remote_post(), which refuses local and private
 * addresses again at send time. The saved key belongs to that URL: changing the
 * URL clears it, so a key cannot be re-pointed at another server.
 * Attachments are sent as text only, as not every such service reads images or PDFs.
 */
final class OpenAiCompatibleProvider extends ChatCompletionsProvider
{
    public const KEY = 'openai_compatible';

    public function __construct(string $apiKey, private string $baseUrl)
    {
        parent::__construct($apiKey);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        $host = (string) wp_parse_url($this->baseUrl, PHP_URL_HOST);

        return '' !== $host ? $host : 'OpenAI-compatible';
    }

    protected function endpoint(): string
    {
        return rtrim($this->baseUrl, '/') . '/chat/completions';
    }

    protected function tokenField(): string
    {
        return 'max_tokens';
    }
}
