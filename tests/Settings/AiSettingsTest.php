<?php

namespace BitApps\AiKit\Tests\Settings;

use BitApps\AiKit\Settings\AiSettings;
use BitApps\AiKit\Tests\TestCase;

/**
 * The OpenAI-compatible provider's base URL: what is accepted, and the key bound to it.
 *
 * @internal
 */
final class AiSettingsTest extends TestCase
{
    public function testOnlyPublicHttpsBaseUrlsAreAccepted(): void
    {
        self::assertNull(AiSettings::baseUrlError(''));
        self::assertNull(AiSettings::baseUrlError('https://api.mistral.ai/v1'));

        foreach ([
            'http://api.mistral.ai/v1',
            'https://localhost:11434/v1',
            'https://127.0.0.1/v1',
            'https://10.0.0.5/v1',
            'https://192.168.1.2/v1',
            'https://user:pass@api.example.com/v1',
            'https://api.example.com/v1?key=1',
            'ftp://api.example.com',
            ['https://api.example.com'],
        ] as $url) {
            self::assertNotNull(AiSettings::baseUrlError($url), (string) wp_json_encode($url));
        }
    }

    public function testTheKeyBelongsToItsBaseUrl(): void
    {
        $settings = new AiSettings($this->context());
        $saved = $settings->save(['provider' => 'openai_compatible', 'base_url' => 'https://api.mistral.ai/v1/chat/completions/', 'model' => 'mistral-large-latest', 'api_key' => 'mk-secret']);

        self::assertSame('https://api.mistral.ai/v1', $saved['base_url'], 'a pasted endpoint is cut to its base');
        self::assertSame('mk-secret', $settings->apiKey('openai_compatible'));

        $settings->save(['model' => 'mistral-small-latest']);
        self::assertSame('mk-secret', $settings->apiKey('openai_compatible'), 'other changes keep the key');

        $settings->save(['base_url' => 'https://evil.example.com/v1']);
        self::assertSame('', $settings->apiKey('openai_compatible'), 'a new address needs the key typed again');

        $kept = $settings->save(['base_url' => 'https://127.0.0.1/v1']);
        self::assertSame('https://evil.example.com/v1', $kept['base_url'], 'a refused address keeps the saved one');
    }
}
