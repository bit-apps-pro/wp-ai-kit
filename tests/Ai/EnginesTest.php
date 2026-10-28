<?php

namespace BitApps\AiKit\Tests\Ai;

use BitApps\AiKit\Ai\AiException;
use BitApps\AiKit\Ai\Engine\CoreAiClientEngine;
use BitApps\AiKit\Ai\Providers\ClaudeProvider;
use BitApps\AiKit\Ai\Providers\GeminiProvider;
use BitApps\AiKit\Ai\Providers\OpenAiCompatibleProvider;
use BitApps\AiKit\Ai\Providers\OpenAiProvider;
use BitApps\AiKit\Tests\TestCase;

/**
 * Each engine's translation between the kit transcript and the provider's wire
 * format, against stubbed HTTP (no network).
 *
 * @internal
 */
final class EnginesTest extends TestCase
{
    private const TOOLS = [
        ['name' => 'list-forms', 'description' => 'Forms.', 'inputSchema' => ['type' => 'object', 'properties' => []]],
        ['name' => 'get-form', 'description' => 'One form.', 'inputSchema' => ['type' => 'object', 'properties' => ['form_id' => ['type' => 'integer', 'minimum' => 1, 'examples' => [3]]], 'required' => ['form_id']]],
    ];

    /** A conversation in the kit's transcript, with one finished tool round. */
    private const TRANSCRIPT = [
        ['role' => 'user', 'content' => 'How many forms?'],
        ['role' => 'assistant', 'text' => 'Checking.', 'tool_calls' => [['id' => 'c1', 'name' => 'list-forms', 'input' => []]], 'engine' => 'other', 'raw' => null],
        ['role' => 'tool', 'results' => [['id' => 'c1', 'name' => 'list-forms', 'content' => '{"total":2}', 'is_error' => false]]],
    ];

    public function testClaude(): void
    {
        \AiKitTestState::$httpResponses[ClaudeProvider::ENDPOINT] = ['response' => ['code' => 200], 'body' => json_encode([
            'content'     => [['type' => 'text', 'text' => 'You have 2 forms.'], ['type' => 'tool_use', 'id' => 'tu1', 'name' => 'get-form', 'input' => ['form_id' => 1]]],
            'stop_reason' => 'tool_use',
            'usage'       => ['input_tokens' => 50, 'output_tokens' => 9],
        ])];

        $result = (new ClaudeProvider('sk-ant'))->send(self::TRANSCRIPT, self::TOOLS, 'System.', ['model' => 'claude-sonnet-5-5', 'max_tokens' => 100]);
        $request = \AiKitTestState::$httpRequests[0];
        $body = json_decode($request['args']['body'], true);

        self::assertSame('sk-ant', $request['args']['headers']['x-api-key']);
        self::assertSame('{}', json_encode(json_decode($request['args']['body'])->tools[0]->input_schema->properties), 'empty properties encode as an object');
        self::assertSame('tool_use', $body['messages'][1]['content'][1]['type'], 'a foreign turn is rebuilt from the normalised fields');
        self::assertSame('tool_result', $body['messages'][2]['content'][0]['type']);
        self::assertSame('You have 2 forms.', $result['text']);
        self::assertSame([['id' => 'tu1', 'name' => 'get-form', 'input' => ['form_id' => 1]]], $result['tool_calls']);
        self::assertSame(['input_tokens' => 50, 'output_tokens' => 9], $result['usage']);
    }

    public function testOpenAiCompatibleSendsToItsBaseUrl(): void
    {
        \AiKitTestState::$httpResponses['https://api.mistral.ai/v1/chat/completions'] = ['response' => ['code' => 200], 'body' => json_encode([
            'choices' => [['message' => ['content' => 'Two forms.'], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 12, 'completion_tokens' => 3],
        ])];

        $engine = new OpenAiCompatibleProvider('mk-1', 'https://api.mistral.ai/v1');
        $result = $engine->send(self::TRANSCRIPT, self::TOOLS, 'System.', ['model' => 'mistral-large-latest', 'max_tokens' => 100]);
        $request = \AiKitTestState::$httpRequests[0];
        $body = json_decode($request['args']['body'], true);

        self::assertSame('https://api.mistral.ai/v1/chat/completions', $request['url']);
        self::assertSame('Bearer mk-1', $request['args']['headers']['authorization']);
        self::assertSame(100, $body['max_tokens'], 'compatible services read max_tokens');
        self::assertArrayNotHasKey('max_completion_tokens', $body);
        self::assertSame('tool', $body['messages'][3]['role']);
        self::assertSame('Two forms.', $result['text']);
        self::assertSame('api.mistral.ai', $engine->label());
    }

    public function testChatCompletionsStreamTextAndToolCalls(): void
    {
        $events = [
            ['choices' => [['delta' => ['role' => 'assistant', 'content' => 'You have ']]]],
            ['choices' => [['delta' => ['content' => '2 forms.']]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get-form', 'arguments' => '{"form']]]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '_id":1}']]]]]]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 7]],
        ];
        $body = implode('', array_map(static fn ($event) => 'data: ' . json_encode($event) . "\n\n", $events)) . "data: [DONE]\n\n";
        // Chunk borders fall anywhere, also inside a line.
        \AiKitTestState::$httpResponses[OpenAiProvider::ENDPOINT] = ['response' => ['code' => 200], 'body' => $body, 'chunks' => str_split($body, 17)];

        $pieces = [];
        $result = (new OpenAiProvider('sk-oa'))->stream(self::TRANSCRIPT, self::TOOLS, 'System.', ['model' => 'gpt-5.4', 'max_tokens' => 100], static function (string $delta) use (&$pieces): void {
            $pieces[] = $delta;
        });
        $sent = json_decode(\AiKitTestState::$httpRequests[0]['args']['body'], true);

        self::assertTrue($sent['stream']);
        self::assertTrue($sent['stream_options']['include_usage']);
        self::assertSame(['You have ', '2 forms.'], $pieces);
        self::assertSame('You have 2 forms.', $result['text']);
        self::assertSame([['id' => 'call_1', 'name' => 'get-form', 'input' => ['form_id' => 1]]], $result['tool_calls']);
        self::assertSame(['input_tokens' => 30, 'output_tokens' => 7], $result['usage']);
    }

    public function testClaudeStreamsTextAndToolInput(): void
    {
        $events = [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 40, 'output_tokens' => 1]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Checking ']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'form 1.']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'tu1', 'name' => 'get-form', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"form_id"']],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => ':1}']],
            ['type' => 'content_block_stop', 'index' => 1],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 12]],
            ['type' => 'message_stop'],
        ];
        $body = implode('', array_map(static fn ($event) => 'event: ' . $event['type'] . "\ndata: " . json_encode($event) . "\n\n", $events));
        \AiKitTestState::$httpResponses[ClaudeProvider::ENDPOINT] = ['response' => ['code' => 200], 'body' => $body, 'chunks' => str_split($body, 23)];

        $pieces = [];
        $result = (new ClaudeProvider('sk-ant'))->stream(self::TRANSCRIPT, self::TOOLS, 'System.', ['model' => 'claude-sonnet-5-5', 'max_tokens' => 100], static function (string $delta) use (&$pieces): void {
            $pieces[] = $delta;
        });

        self::assertSame(['Checking ', 'form 1.'], $pieces);
        self::assertSame('Checking form 1.', $result['text']);
        self::assertSame([['id' => 'tu1', 'name' => 'get-form', 'input' => ['form_id' => 1]]], $result['tool_calls']);
        self::assertSame('tool_use', $result['stop_reason']);
        self::assertSame(['input_tokens' => 40, 'output_tokens' => 12], $result['usage']);
    }

    public function testAStreamedErrorIsReportedAsTheProviderSaysIt(): void
    {
        \AiKitTestState::$httpResponses[ClaudeProvider::ENDPOINT] = ['response' => ['code' => 401], 'body' => json_encode(['error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']])];

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('invalid x-api-key');
        (new ClaudeProvider('bad'))->stream(self::TRANSCRIPT, [], 'System.', ['model' => 'm'], static fn () => null);
    }

    public function testOpenAi(): void
    {
        \AiKitTestState::$httpResponses[OpenAiProvider::ENDPOINT] = ['response' => ['code' => 200], 'body' => json_encode([
            'choices' => [['message' => ['content' => null, 'tool_calls' => [['id' => 'call_9', 'type' => 'function', 'function' => ['name' => 'get-form', 'arguments' => '{"form_id":1}']]]], 'finish_reason' => 'tool_calls']],
            'usage'   => ['prompt_tokens' => 30, 'completion_tokens' => 4],
        ])];

        $result = (new OpenAiProvider('sk-oa'))->send(self::TRANSCRIPT, self::TOOLS, 'System.', ['model' => 'gpt-5.4', 'max_tokens' => 100]);
        $body = json_decode(\AiKitTestState::$httpRequests[0]['args']['body'], true);

        self::assertSame('system', $body['messages'][0]['role']);
        self::assertSame('tool', $body['messages'][3]['role']);
        self::assertSame('c1', $body['messages'][3]['tool_call_id']);
        self::assertSame('Bearer sk-oa', \AiKitTestState::$httpRequests[0]['args']['headers']['authorization']);
        self::assertSame('', $result['text']);
        self::assertSame(['form_id' => 1], $result['tool_calls'][0]['input']);
        self::assertSame('tool_use', $result['stop_reason']);
    }

    public function testGemini(): void
    {
        $url = GeminiProvider::ENDPOINT . 'gemini-2.5-flash:generateContent';
        \AiKitTestState::$httpResponses[$url] = ['response' => ['code' => 200], 'body' => json_encode([
            'candidates'    => [['content' => ['role' => 'model', 'parts' => [['text' => 'thinking…', 'thought' => true], ['text' => 'Two forms.']]]]],
            'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 3],
        ])];

        $result = (new GeminiProvider('g-key'))->send(self::TRANSCRIPT, self::TOOLS, 'System.', ['model' => 'gemini-2.5-flash']);
        $body = json_decode(\AiKitTestState::$httpRequests[0]['args']['body'], true);

        self::assertArrayNotHasKey('parameters', $body['tools'][0]['functionDeclarations'][0], 'a tool without arguments has no parameters');
        self::assertArrayNotHasKey('examples', $body['tools'][0]['functionDeclarations'][1]['parameters']['properties']['form_id'], 'unsupported schema keys are dropped');
        self::assertSame('functionResponse', array_key_first($body['contents'][2]['parts'][0]));
        self::assertSame('Two forms.', $result['text'], 'thought parts are not the answer');
    }

    public function testGeminiStreamsTextAndKeepsThePartsWhole(): void
    {
        $url = GeminiProvider::ENDPOINT . 'gemini-2.5-flash:streamGenerateContent?alt=sse';
        $chunks = [
            ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'thinking…', 'thought' => true]]]]]],
            ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Checking ']]]]]],
            ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'form 1.', 'thoughtSignature' => 'sig-1']]]]]],
            ['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'get-form', 'args' => ['form_id' => 1]]]]]]], 'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 9]],
        ];
        $body = implode('', array_map(static fn ($chunk) => 'data: ' . json_encode($chunk) . "\r\n\r\n", $chunks));
        \AiKitTestState::$httpResponses[$url] = ['response' => ['code' => 200], 'body' => $body, 'chunks' => str_split($body, 17)];

        $pieces = [];
        $result = (new GeminiProvider('g-key'))->stream(self::TRANSCRIPT, self::TOOLS, 'System.', ['model' => 'gemini-2.5-flash'], static function (string $delta) use (&$pieces): void {
            $pieces[] = $delta;
        });

        self::assertSame(['Checking ', 'form 1.'], $pieces, 'thoughts are not streamed');
        self::assertSame('Checking form 1.', $result['text']);
        self::assertSame('get-form', $result['tool_calls'][0]['name']);
        self::assertSame(['form_id' => 1], $result['tool_calls'][0]['input']);
        self::assertSame(['input_tokens' => 20, 'output_tokens' => 9], $result['usage']);
        // The next turn sends these parts back: one text part, with the signature on it.
        self::assertSame(['text' => 'Checking form 1.', 'thoughtSignature' => 'sig-1'], $result['raw']['parts'][1]);
        self::assertCount(3, $result['raw']['parts']);
    }

    public function testProviderErrorsBecomeAiExceptions(): void
    {
        \AiKitTestState::$httpResponses[ClaudeProvider::ENDPOINT] = ['response' => ['code' => 401], 'body' => json_encode(['error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']])];

        try {
            (new ClaudeProvider('bad'))->send(self::TRANSCRIPT, [], 'S', ['model' => 'm']);
            self::fail('expected an exception');
        } catch (AiException $error) {
            self::assertSame('authentication_error', $error->reason());
            self::assertSame(401, $error->httpStatus());
            self::assertSame('invalid x-api-key', $error->getMessage());
        }

        $this->expectException(AiException::class);
        (new OpenAiProvider(''))->send(self::TRANSCRIPT, [], 'S', ['model' => 'm']);
    }

    public function testCoreClientConversions(): void
    {
        $messages = CoreAiClientEngine::toMessages(self::TRANSCRIPT);

        self::assertSame('user', $messages[0]['role']);
        self::assertSame('model', $messages[1]['role']);
        self::assertSame('function_call', $messages[1]['parts'][1]['type']);
        self::assertSame(['id' => 'c1', 'name' => 'list-forms', 'args' => []], $messages[1]['parts'][1]['functionCall']);
        self::assertSame('user', $messages[2]['role'], 'function responses go back in a user message');
        self::assertSame('{"total":2}', $messages[2]['parts'][0]['functionResponse']['response']['content']);

        $own = [['role' => 'assistant', 'text' => 'x', 'tool_calls' => [], 'engine' => 'wordpress', 'raw' => ['role' => 'model', 'parts' => [['type' => 'text', 'text' => 'x', 'thoughtSignature' => 'sig']]]]];
        self::assertSame('sig', CoreAiClientEngine::toMessages($own)[0]['parts'][0]['thoughtSignature'], 'own turns replay verbatim');

        $declarations = CoreAiClientEngine::toDeclarations(self::TOOLS);
        self::assertSame('{}', json_encode($declarations[0]['parameters']['properties']));

        $result = CoreAiClientEngine::normalize(['role' => 'model', 'parts' => [
            ['channel' => 'thought', 'type' => 'text', 'text' => 'hmm'],
            ['channel' => 'content', 'type' => 'text', 'text' => 'Answer'],
            ['channel' => 'content', 'type' => 'function_call', 'functionCall' => ['name' => 'get-form', 'args' => ['form_id' => 2]]],
        ]], ['promptTokens' => 7, 'completionTokens' => 2]);

        self::assertSame('Answer', $result['text']);
        self::assertSame('call_2_get-form', $result['tool_calls'][0]['id'], 'a call without an id gets one');
        self::assertSame(['input_tokens' => 7, 'output_tokens' => 2], $result['usage']);
        self::assertFalse(CoreAiClientEngine::available(), 'no AI Client in the plain test environment');
    }
}
