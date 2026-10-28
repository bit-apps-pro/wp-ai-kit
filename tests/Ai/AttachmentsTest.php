<?php

namespace BitApps\AiKit\Tests\Ai;

use BitApps\AiKit\Ai\AiException;
use BitApps\AiKit\Ai\Attachments\AttachmentStore;
use BitApps\AiKit\Ai\Engine\CoreAiClientEngine;
use BitApps\AiKit\Ai\Engine\EngineInterface;
use BitApps\AiKit\Ai\Engine\SupportsAttachments;
use BitApps\AiKit\Ai\Providers\ClaudeProvider;
use BitApps\AiKit\Ai\Providers\GeminiProvider;
use BitApps\AiKit\Contract;
use BitApps\AiKit\Kit;
use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Tests\TestCase;

/**
 * Files attached to assistant messages: what the store accepts and refuses, that
 * the transcript keeps only names while the engine gets the content, how each
 * engine sends files, and the REST routes.
 *
 * @internal
 */
final class AttachmentsTest extends TestCase
{
    private const USER = 5;

    /** A 1×1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /** @var string[] temp files to remove */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->grant(self::USER, 'manage_options', 'upload_files');
        \AiKitTestState::$currentUser = self::USER;
        update_option('bit_demo_ai_settings', ['enabled' => true, 'access_level' => 'write']);
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }
        if ('' !== \AiKitTestState::$uploadDir) {
            exec('rm -rf ' . escapeshellarg(\AiKitTestState::$uploadDir));
        }
        parent::tearDown();
    }

    public function testTheStoreKeepsAllowedFilesPrivateAndRefusesTheRest(): void
    {
        $store = $this->store();

        $png = $store->add(self::USER, 'chart.png', $this->tempFile(base64_decode(self::PNG)));
        self::assertSame(['image', 'image/png'], [$png['kind'], $png['mime']]);
        self::assertMatchesRegularExpression(AttachmentStore::ID_PATTERN, $png['id']);
        self::assertFileExists($store->baseDir() . '/.htaccess', 'the folder blocks web access');
        self::assertFileExists($store->baseDir() . '/web.config');
        self::assertSame(base64_decode(self::PNG), base64_decode((string) $store->base64(self::USER, $png['id'])));

        $text = $store->add(self::USER, 'notes.md', $this->tempFile("# Plan\nShip it."));
        self::assertSame("# Plan\nShip it.", $store->get(self::USER, $text['id'])['text']);

        $docx = $store->add(self::USER, 'brief.docx', $this->docx(['Hello', 'World']));
        self::assertSame("Hello\nWorld", $store->get(self::USER, $docx['id'])['text']);

        $pdf = $store->add(self::USER, 'invoice.pdf', $this->tempFile("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"));
        self::assertSame('pdf', $pdf['kind']);

        $refused = [
            'old.doc'   => 'attachment_type',
            'logo.svg'  => 'attachment_type',
            'page.html' => 'attachment_type',
            'fake.png'  => 'attachment_invalid',
            'fake.pdf'  => 'attachment_invalid',
            'shell.txt' => 'attachment_invalid',
            'empty.txt' => 'attachment_empty',
        ];
        $contents = ['fake.png' => 'not an image', 'fake.pdf' => 'not a pdf', 'shell.txt' => "hi <?php system('id');", 'empty.txt' => ''];
        foreach ($refused as $name => $reason) {
            try {
                $store->add(self::USER, $name, $this->tempFile($contents[$name] ?? 'x'));
                self::fail($name . ' was accepted');
            } catch (AiException $error) {
                self::assertSame($reason, $error->reason(), $name);
            }
        }
    }

    public function testFilesBelongToTheirUserAndExpire(): void
    {
        $store = $this->store();
        $file = $store->add(self::USER, 'a.txt', $this->tempFile('hello'));

        self::assertNull($store->get(6, $file['id']), 'another user cannot read it');
        self::assertFalse($store->delete(6, $file['id']));
        self::assertNull($store->get(self::USER, '../../etc/passwd'));

        self::assertFileDoesNotExist($store->baseDir() . '/' . self::USER . '/' . $file['id'] . '.json', 'the id is not the name on disk');
        $sidecars = glob($store->baseDir() . '/' . self::USER . '/*.json');
        self::assertCount(1, $sidecars);
        $sidecar = $sidecars[0];
        $meta = json_decode((string) file_get_contents($sidecar), true);
        $meta['created'] = time() - DAY_IN_SECONDS - 5;
        file_put_contents($sidecar, json_encode($meta));
        self::assertNull($store->get(self::USER, $file['id']), 'older than a day');
        self::assertFileDoesNotExist($sidecar);
    }

    public function testLimitsComeFromThePluginsFilter(): void
    {
        add_filter(Contract::attachmentLimitsHook('bit-demo'), static fn ($limits) => ['max_bytes' => ['text' => 4]] + $limits, 10, 1);

        try {
            $this->store()->add(self::USER, 'long.txt', $this->tempFile('more than four bytes'));
            self::fail('too large was accepted');
        } catch (AiException $error) {
            self::assertSame('attachment_too_large', $error->reason());
        }
    }

    public function testTheEngineGetsTheFilesWhileTheTranscriptKeepsOnlyTheirNames(): void
    {
        $engine = new FileReadingEngine();
        $kit = $this->kit($engine);
        $store = $kit->attachments();
        $text = $store->add(self::USER, 'notes.txt', $this->tempFile('Revenue grew 12%.'));
        $image = $store->add(self::USER, 'chart.png', $this->tempFile(base64_decode(self::PNG)));

        $view = $kit->assistant()->chat(self::USER, 'Summarise these', '', [], [$text['id'], $image['id']]);

        self::assertArrayNotHasKey('error', $view, (string) ($view['error'] ?? ''));
        $parts = $engine->calls[0]['messages'][0]['parts'];
        self::assertSame('Summarise these', $parts[0]['text']);
        self::assertStringContainsString('Revenue grew 12%.', $parts[1]['text']);
        self::assertStringContainsString('[[UNTRUSTED_USER_INPUT]]', $parts[1]['text'], 'file text is fenced as data');
        self::assertSame(['image', 'image/png', self::PNG], [$parts[2]['type'], $parts[2]['mime'], $parts[2]['data']]);

        self::assertSame([['id' => $text['id'], 'name' => 'notes.txt', 'kind' => 'text', 'size' => 17], ['id' => $image['id'], 'name' => 'chart.png', 'kind' => 'image', 'size' => \strlen(base64_decode(self::PNG))]], $view['messages'][0]['attachments']);
        $stored = (string) wp_json_encode(get_user_meta(self::USER, 'bit_demo_ai_conversation', true));
        self::assertStringNotContainsString('Revenue grew', $stored, 'the transcript keeps no file content');
        self::assertStringNotContainsString(self::PNG, $stored);
        self::assertStringContainsString('chart.png', $stored);

        $store->delete(self::USER, $image['id']);
        $files = $kit->assistant()->view(self::USER)['messages'][0]['attachments'];
        self::assertArrayNotHasKey('expired', $files[0]);
        self::assertTrue($files[1]['expired'], 'a file no longer kept shows as expired');

        $kit->assistant()->forget(self::USER, 'all');
        self::assertNull($store->get(self::USER, $text['id']), 'deleting the chats deletes their files');
    }

    public function testEarlierImagesGoAlongOnlyWhileTheyFitOneMessagesBudget(): void
    {
        $size = \strlen(base64_decode(self::PNG));
        // Room for one image per request.
        add_filter(Contract::attachmentLimitsHook('bit-demo'), static fn ($limits) => ['max_total_bytes' => $size + 10] + $limits, 10, 1);
        $engine = new FileReadingEngine();
        $kit = $this->kit($engine);
        $first = $kit->attachments()->add(self::USER, 'first.png', $this->tempFile(base64_decode(self::PNG)));
        $kit->assistant()->chat(self::USER, 'Make a form like this', '', [], [$first['id']]);
        $second = $kit->attachments()->add(self::USER, 'second.png', $this->tempFile(base64_decode(self::PNG)));

        $kit->assistant()->chat(self::USER, 'And now like this one', '', [], [$second['id']]);

        $sent = end($engine->calls)['messages'];
        self::assertSame('image', $sent[2]['parts'][1]['type'], 'the newest image is sent');
        self::assertSame('text', $sent[0]['parts'][1]['type']);
        self::assertStringContainsString('"first.png" was shared earlier in this chat and is not sent again', $sent[0]['parts'][1]['text']);

        // A follow-up without files still sends the last image: it fits.
        $kit->assistant()->chat(self::USER, 'Make the header blue');
        $sent = end($engine->calls)['messages'];
        self::assertSame('image', $sent[2]['parts'][1]['type']);
    }

    public function testAnEngineThatCannotReadImagesIsRefusedBeforeTheTurnCounts(): void
    {
        $engine = new ScriptedEngine();
        $kit = $this->kit($engine);
        $image = $kit->attachments()->add(self::USER, 'chart.png', $this->tempFile(base64_decode(self::PNG)));

        $view = $kit->assistant()->chat(self::USER, 'What is this?', '', [], [$image['id']]);

        self::assertSame('attachment_unsupported', $view['reason']);
        self::assertSame([], $engine->calls);

        $text = $kit->attachments()->add(self::USER, 'a.csv', $this->tempFile("a,b\n1,2"));
        $ok = $kit->assistant()->chat(self::USER, '', '', [], [$text['id']]);
        self::assertArrayNotHasKey('error', $ok, 'text files work with any engine, and a message may be only files');
        self::assertSame('Please look at the attached file.', $ok['messages'][0]['text']);

        self::assertSame('attachment_missing', $kit->assistant()->chat(self::USER, 'x', '', [], ['0123456789abcdef0123456789abcdef'])['reason']);
    }

    public function testWithoutTheFeatureThereIsNoStoreAndAttachmentsAreRefused(): void
    {
        $kit = Kit::boot($this->context());
        $kit->useEngineFactory(static fn () => new ScriptedEngine());

        self::assertNull($kit->attachments());
        self::assertSame('attachments_off', $kit->assistant()->chat(self::USER, 'Hi', '', [], ['0123456789abcdef0123456789abcdef'])['reason']);
        self::assertFalse($kit->uiBootstrap()['features']['attachments']);
        self::assertNull($kit->uiBootstrap()['attachments']);
    }

    public function testEachEngineSendsFilesInItsOwnFormat(): void
    {
        $message = ['role' => 'user', 'content' => 'Look', 'parts' => [
            ['type' => 'text', 'text' => 'Look'],
            ['type' => 'image', 'mime' => 'image/png', 'name' => 'a.png', 'data' => 'AAA'],
            ['type' => 'pdf', 'mime' => 'application/pdf', 'name' => 'b.pdf', 'data' => 'BBB'],
        ]];
        $reply = ['response' => ['code' => 200]];

        \AiKitTestState::$httpResponses[ClaudeProvider::ENDPOINT] = $reply + ['body' => json_encode(['content' => [['type' => 'text', 'text' => 'ok']]])];
        (new ClaudeProvider('k'))->send([$message], [], 'S', ['model' => 'm']);
        $claude = json_decode(\AiKitTestState::$httpRequests[0]['args']['body'], true)['messages'][0]['content'];
        self::assertSame(['text', 'image', 'document'], array_column($claude, 'type'));
        self::assertSame(['type' => 'base64', 'media_type' => 'application/pdf', 'data' => 'BBB'], $claude[2]['source']);

        $geminiUrl = GeminiProvider::ENDPOINT . 'm:generateContent';
        \AiKitTestState::$httpResponses[$geminiUrl] = $reply + ['body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]])];
        (new GeminiProvider('k'))->send([$message], [], 'S', ['model' => 'm']);
        $gemini = json_decode(\AiKitTestState::$httpRequests[1]['args']['body'], true)['contents'][0]['parts'];
        self::assertSame(['mimeType' => 'application/pdf', 'data' => 'BBB'], $gemini[2]['inlineData']);

        $core = CoreAiClientEngine::toMessages([$message])[0]['parts'];
        self::assertSame(['fileType' => 'inline', 'mimeType' => 'image/png', 'base64Data' => 'AAA'], $core[1]['file']);
        self::assertSame(15 * 1024 * 1024, (new GeminiProvider('k'))->maxAttachmentBytes(), 'Gemini takes less inline');
    }

    public function testTheRestRoutesUploadAndDelete(): void
    {
        $kit = $this->kit(new FileReadingEngine(), true);
        do_action('rest_api_init');

        $upload = $this->rest('POST', '/assistant/attachments', ['file' => ['name' => 'notes.txt', 'tmp_name' => $this->tempFile('hello'), 'error' => 0]]);
        self::assertSame(201, $upload->get_status());
        $id = $upload->get_data()['id'];

        $bad = $this->rest('POST', '/assistant/attachments', ['file' => ['name' => 'run.php', 'tmp_name' => $this->tempFile('<?php'), 'error' => 0]]);
        self::assertSame('attachment_type', $bad->get_error_code());

        \AiKitTestState::$capabilities[self::USER]['upload_files'] = false;
        self::assertSame(403, $this->rest('POST', '/assistant/attachments', ['file' => ['name' => 'a.txt', 'tmp_name' => $this->tempFile('x'), 'error' => 0]])->get_error_data()['status']);

        self::assertSame(200, $this->rest('DELETE', '/assistant/attachments/' . $id)->get_status());
        self::assertSame(404, $this->rest('DELETE', '/assistant/attachments/' . $id)->get_error_data()['status']);

        $trail = array_map(static fn ($row) => [$row['tool'], $row['status'], $row['resource_id'], $row['error_code']], array_reverse($kit->auditLogger()->recent(10)));
        self::assertSame([
            ['attachment.uploaded', 'ok', $id, null],
            ['attachment.uploaded', 'error', null, 'attachment_type'],
            ['attachment.deleted', 'ok', $id, null],
        ], $trail, 'uploads, refusals and removals are in the audit trail');
        self::assertStringNotContainsString('notes.txt', (string) wp_json_encode($kit->auditLogger()->recent(10)), 'file names are not');

        $bootstrap = $kit->uiBootstrap();
        self::assertTrue($bootstrap['features']['attachments']);
        self::assertNull($bootstrap['attachments'], 'no upload rules for a user who may not upload');
        \AiKitTestState::$capabilities[self::USER]['upload_files'] = true;
        $rules = $kit->uiBootstrap()['attachments'];
        self::assertStringContainsString('.png', $rules['accept'], 'the engine reads images');
        self::assertSame(5, $rules['maxFiles']);
    }

    private function store(): AttachmentStore
    {
        return new AttachmentStore($this->context(['features' => [PluginContext::FEATURE_ATTACHMENTS => true]]));
    }

    private function kit(EngineInterface $engine, bool $restUi = false): Kit
    {
        $kit = Kit::boot($this->context([
            'features' => [PluginContext::FEATURE_ATTACHMENTS => true, PluginContext::FEATURE_REST_UI => $restUi],
            'can_use'  => static fn ($userId) => self::USER === (int) $userId,
        ]));
        $kit->useEngineFactory(static fn () => $engine);

        return $kit;
    }

    /**
     * @param array<string, mixed> $files
     *
     * @return \WP_Error|\WP_REST_Response
     */
    private function rest(string $method, string $path, array $files = [])
    {
        foreach (\AiKitRest::$routes as $route => $endpoints) {
            $pattern = '#^' . $route . '$#';
            if (!preg_match($pattern, '/bit-demo/v1' . Contract::REST_BASE . $path, $matches)) {
                continue;
            }
            foreach ($endpoints as $endpoint) {
                if ($method !== $endpoint['methods']) {
                    continue;
                }
                $request = new \WP_REST_Request($method, $route);
                $request->set_url_params(isset($matches['id']) ? ['id' => $matches['id']] : []);
                $request->set_file_params($files);
                $allowed = ($endpoint['permission_callback'])($request);

                return true === $allowed ? ($endpoint['callback'])($request) : $allowed;
            }
        }
        self::fail("No route for {$method} {$path}");
    }

    private function tempFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'aikit');
        file_put_contents($file, $contents);

        return $this->temp[] = $file;
    }

    /** @param string[] $paragraphs */
    private function docx(array $paragraphs): string
    {
        $file = tempnam(sys_get_temp_dir(), 'aikit');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);
        $body = implode('', array_map(static fn ($text) => '<w:p><w:r><w:t>' . htmlspecialchars($text) . '</w:t></w:r></w:p>', $paragraphs));
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '</w:body></w:document>');
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->close();

        return $this->temp[] = $file;
    }
}

/** A scripted model that reads images and PDFs. */
final class FileReadingEngine implements EngineInterface, SupportsAttachments
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    public function key(): string
    {
        return 'files';
    }

    public function label(): string
    {
        return 'File reader';
    }

    public function attachmentKinds(): array
    {
        return ['image', 'pdf'];
    }

    public function maxAttachmentBytes(): int
    {
        return 0;
    }

    public function send(array $messages, array $tools, string $system, array $options): array
    {
        $this->calls[] = ['messages' => $messages];

        return ['text' => 'Done.', 'tool_calls' => [], 'stop_reason' => 'end_turn', 'usage' => [], 'raw' => null];
    }
}
