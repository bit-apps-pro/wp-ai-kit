<?php

namespace BitApps\AiKit\Ai\Attachments;

use BitApps\AiKit\Ai\AiException;
use BitApps\AiKit\Contract;
use BitApps\AiKit\PluginContext;

/**
 * Files a user attaches to an assistant message: validated on upload, kept in a
 * private folder for a day, read back only on the server when a turn is sent to
 * the AI provider, never served by URL.
 *
 * `{uploads}/{option_prefix}aikit_attachments/{user_id}/{id}.{ext}` plus a JSON
 * sidecar with what the screens and the engines need (and the extracted text of
 * text files and Word documents). The folder blocks web access (.htaccess,
 * web.config, index.html); ownership is the user folder; ids are 32 random hex
 * characters. On multisite every site has its own uploads folder.
 */
final class AttachmentStore
{
    public const ID_PATTERN = '/^[a-f0-9]{32}$/';

    public const KIND_IMAGE = 'image';

    public const KIND_PDF = 'pdf';

    public const KIND_TEXT = 'text';

    public const KIND_DOCX = 'docx';

    /** Extension => what it is and the MIME types its content may have. Nothing else is accepted. */
    public const TYPES = [
        'png'  => ['kind' => self::KIND_IMAGE, 'mime' => 'image/png', 'accept' => ['image/png']],
        'jpg'  => ['kind' => self::KIND_IMAGE, 'mime' => 'image/jpeg', 'accept' => ['image/jpeg']],
        'jpeg' => ['kind' => self::KIND_IMAGE, 'mime' => 'image/jpeg', 'accept' => ['image/jpeg']],
        'gif'  => ['kind' => self::KIND_IMAGE, 'mime' => 'image/gif', 'accept' => ['image/gif']],
        'webp' => ['kind' => self::KIND_IMAGE, 'mime' => 'image/webp', 'accept' => ['image/webp']],
        'pdf'  => ['kind' => self::KIND_PDF, 'mime' => 'application/pdf', 'accept' => ['application/pdf']],
        'txt'  => ['kind' => self::KIND_TEXT, 'mime' => 'text/plain', 'accept' => []],
        'md'   => ['kind' => self::KIND_TEXT, 'mime' => 'text/markdown', 'accept' => []],
        'csv'  => ['kind' => self::KIND_TEXT, 'mime' => 'text/csv', 'accept' => []],
        'docx' => ['kind' => self::KIND_DOCX, 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'accept' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream',
        ]],
    ];

    /**
     * Defaults, filtered by `bitapps_ai_attachment_limits_<slug>`. A request to the
     * provider carries the files base64-encoded (a third larger), so the total stays
     * under the providers' request limits.
     */
    public const DEFAULT_LIMITS = [
        'max_files'       => 5,
        'max_total_bytes' => 20 * 1024 * 1024,
        'max_bytes'       => [
            self::KIND_IMAGE => 5 * 1024 * 1024,
            self::KIND_PDF   => 15 * 1024 * 1024,
            self::KIND_DOCX  => 10 * 1024 * 1024,
            self::KIND_TEXT  => 1024 * 1024,
        ],
        'max_text_chars'     => 200000,
        'ttl'                => DAY_IN_SECONDS,
        'uploads_per_window' => 30,
        'window'             => 600,
    ];

    /** Text that has no place in an attached text file, refused on upload. */
    private const SCRIPT_MARKERS = ['<?php', '<?=', '<script'];

    public function __construct(private PluginContext $context) {}

    /** @return array<string, mixed> DEFAULT_LIMITS after the plugin's filter */
    public function limits(): array
    {
        $limits = self::DEFAULT_LIMITS;
        if (\function_exists('apply_filters')) {
            $filtered = apply_filters(Contract::attachmentLimitsHook($this->context->slug()), $limits);
            if (\is_array($filtered)) {
                $limits = array_replace_recursive($limits, $filtered);
            }
        }

        return $limits;
    }

    /** The `accept` list for a file picker: `.png,.jpg,…`. */
    public static function acceptList(): string
    {
        return implode(',', array_map(static fn (string $ext) => '.' . $ext, array_keys(self::TYPES)));
    }

    /**
     * Validate an uploaded file and keep it for this user.
     *
     * @param string $tmpPath where PHP put the upload (REST file params)
     *
     * @return array{id: string, name: string, mime: string, kind: string, size: int, created: int}
     *
     * @throws AiException with a reason the screens can show
     */
    public function add(int $userId, string $name, string $tmpPath): array
    {
        if ($userId <= 0) {
            throw new AiException('Sign in to attach files.', 'forbidden');
        }
        if ('' === $tmpPath || !is_file($tmpPath) || !self::isTemporaryUpload($tmpPath)) {
            throw new AiException('The upload did not arrive. Try again.', 'attachment_missing');
        }

        $name = self::cleanName($name);
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $type = self::TYPES[$ext] ?? null;
        if (null === $type) {
            throw new AiException('This file type cannot be attached. Use an image (PNG, JPG, GIF, WebP), a PDF, a Word document (.docx; save .doc files as .docx) or a text file (TXT, MD, CSV).', 'attachment_type');
        }

        $limits = $this->limits();
        $size = (int) filesize($tmpPath);
        $max = (int) ($limits['max_bytes'][$type['kind']] ?? 0);
        if ($size <= 0) {
            throw new AiException('The file is empty.', 'attachment_empty');
        }
        if ($max > 0 && $size > $max) {
            throw new AiException(\sprintf('The file is too large (%s at most for this type).', self::readableBytes($max)), 'attachment_too_large');
        }

        $text = $this->inspect($tmpPath, $name, $ext, $type, (int) $limits['max_text_chars']);

        $this->purgeExpiredOfUser($userId);
        $dir = $this->userDir($userId, true);
        $id = bin2hex(random_bytes(16));
        $stem = $dir . '/' . self::stem($id);
        $target = $stem . '.' . $ext;
        $moved = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $target) : copy($tmpPath, $target); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a private file outside the media library.
        if (!$moved) {
            throw new AiException('The file could not be stored on this site.', 'attachment_storage');
        }
        if ('image' === $type['kind'] && (bool) apply_filters(Contract::stripImageMetadataHook($this->context->slug()), true, $ext)) {
            // A photo's GPS position and camera details stay on the user's device.
            $size = ImageMetadata::strip($target, $ext) ?? $size;
        }

        $meta = ['id' => $id, 'name' => $name, 'ext' => $ext, 'mime' => $type['mime'], 'kind' => $type['kind'], 'size' => $size, 'created' => time(), 'user_id' => $userId];
        file_put_contents($stem . '.json', (string) wp_json_encode($meta + (null === $text ? [] : ['text' => $text]))); // phpcs:ignore WordPress.WP.AlternativeFunctions

        return self::publicMeta($meta);
    }

    /**
     * One of the user's files, or null when it is not theirs, gone or expired.
     *
     * @return null|array<string, mixed> the stored meta (with `text` for text and Word files)
     */
    public function get(int $userId, string $id): ?array
    {
        if ($userId <= 0 || 1 !== preg_match(self::ID_PATTERN, $id)) {
            return null;
        }
        $sidecar = $this->userDir($userId) . '/' . self::stem($id) . '.json';
        if (!is_file($sidecar)) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($sidecar), true); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if (!\is_array($meta) || (int) ($meta['user_id'] ?? 0) !== $userId || !isset(self::TYPES[$meta['ext'] ?? ''])) {
            return null;
        }
        if ((int) ($meta['created'] ?? 0) + (int) $this->limits()['ttl'] < time()) {
            $this->delete($userId, $id);

            return null;
        }

        return $meta;
    }

    /** The file's bytes base64-encoded, for an image or PDF the engine sends; null when gone. */
    public function base64(int $userId, string $id): ?string
    {
        $meta = $this->get($userId, $id);
        if (null === $meta) {
            return null;
        }
        $bytes = file_get_contents($this->userDir($userId) . '/' . self::stem($id) . '.' . $meta['ext']); // phpcs:ignore WordPress.WP.AlternativeFunctions

        return false === $bytes ? null : base64_encode($bytes);
    }

    /** Remove one of the user's files; false when there was none. */
    public function delete(int $userId, string $id): bool
    {
        if ($userId <= 0 || 1 !== preg_match(self::ID_PATTERN, $id)) {
            return false;
        }
        $removed = false;
        foreach (glob($this->userDir($userId) . '/' . self::stem($id) . '.*') ?: [] as $file) {
            $removed = self::remove($file) || $removed;
        }

        return $removed;
    }

    /** Remove every file of a user (their chats were deleted, or the user was). */
    public function deleteAllOfUser(int $userId): void
    {
        if ($userId > 0) {
            self::removeTree($this->userDir($userId));
        }
    }

    /** Remove expired files of every user; returns how many attachments went. */
    public function purgeExpired(): int
    {
        $count = 0;
        foreach (glob($this->baseDir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $count += $this->purgeExpiredIn($dir);
        }

        return $count;
    }

    /** Remove the whole attachments folder of this plugin (uninstall). */
    public function removeAll(): void
    {
        self::removeTree($this->baseDir());
    }

    /**
     * What the screens may see of a file.
     *
     * @param array<string, mixed> $meta
     *
     * @return array{id: string, name: string, mime: string, kind: string, size: int, created: int}
     */
    public static function publicMeta(array $meta): array
    {
        return [
            'id'      => (string) $meta['id'],
            'name'    => (string) $meta['name'],
            'mime'    => (string) $meta['mime'],
            'kind'    => (string) $meta['kind'],
            'size'    => (int) $meta['size'],
            'created' => (int) ($meta['created'] ?? 0),
        ];
    }

    public function baseDir(): string
    {
        $uploads = \function_exists('wp_upload_dir') ? wp_upload_dir(null, false) : [];
        $root = \is_array($uploads) && !empty($uploads['basedir']) ? (string) $uploads['basedir'] : sys_get_temp_dir();

        return rtrim($root, '/\\') . '/' . $this->context->optionPrefix() . 'aikit_attachments';
    }

    /**
     * Check the file is what its extension says; returns the text to keep for text
     * and Word files, null for images and PDFs.
     *
     * @param array{kind: string, mime: string, accept: string[]} $type
     */
    private function inspect(string $path, string $name, string $ext, array $type, int $maxChars): ?string
    {
        $detected = self::detectedMime($path);

        if (self::KIND_IMAGE === $type['kind']) {
            $info = @getimagesize($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a broken image is an answer, not an error.
            if (!\is_array($info) || !\in_array((string) ($info['mime'] ?? ''), $type['accept'], true) || (null !== $detected && !\in_array($detected, $type['accept'], true))) {
                throw new AiException('The file is not a valid ' . strtoupper($ext) . ' image.', 'attachment_invalid');
            }
            $this->checkWithWordPress($path, $name);

            return null;
        }

        if (self::KIND_PDF === $type['kind']) {
            $head = (string) file_get_contents($path, false, null, 0, 5); // phpcs:ignore WordPress.WP.AlternativeFunctions
            if ('%PDF-' !== $head || (null !== $detected && 'application/pdf' !== $detected)) {
                throw new AiException('The file is not a valid PDF.', 'attachment_invalid');
            }
            $this->checkWithWordPress($path, $name);

            return null;
        }

        if (self::KIND_DOCX === $type['kind']) {
            if (null !== $detected && !\in_array($detected, $type['accept'], true)) {
                throw new AiException('The file is not a valid Word document.', 'attachment_invalid');
            }

            return self::cap(TextExtractor::docx($path), $maxChars);
        }

        // Text: any plain text, never markup or code that a browser or PHP would run.
        if (null !== $detected && (!str_starts_with($detected, 'text/') || \in_array($detected, ['text/html', 'text/x-php', 'text/javascript', 'text/xml'], true))
            && !\in_array($detected, ['application/csv', 'application/octet-stream', 'inode/x-empty'], true)) {
            throw new AiException('The file is not plain text.', 'attachment_invalid');
        }
        $raw = (string) file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $lower = strtolower($raw);
        foreach (self::SCRIPT_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                throw new AiException('The file contains code and cannot be attached.', 'attachment_invalid');
            }
        }

        return self::cap(TextExtractor::utf8($raw), $maxChars);
    }

    /** WordPress's own check (extension against content) for the types it knows. */
    private function checkWithWordPress(string $path, string $name): void
    {
        if (!\function_exists('wp_check_filetype_and_ext')) {
            return;
        }
        $checked = wp_check_filetype_and_ext($path, $name);
        if (!\is_array($checked) || empty($checked['ext']) || empty($checked['type'])) {
            throw new AiException('WordPress does not accept this file.', 'attachment_invalid');
        }
    }

    private static function detectedMime(string $path): ?string
    {
        if (!\function_exists('finfo_open')) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (false === $finfo) {
            return null;
        }
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return \is_string($mime) && '' !== $mime ? strtolower($mime) : null;
    }

    private function purgeExpiredOfUser(int $userId): void
    {
        $this->purgeExpiredIn($this->userDir($userId));
    }

    private function purgeExpiredIn(string $dir): int
    {
        $ttl = (int) $this->limits()['ttl'];
        $count = 0;
        foreach (glob($dir . '/*.json') ?: [] as $sidecar) {
            $meta = json_decode((string) file_get_contents($sidecar), true); // phpcs:ignore WordPress.WP.AlternativeFunctions
            $created = \is_array($meta) ? (int) ($meta['created'] ?? 0) : 0;
            if ($created + $ttl < time()) {
                $stem = basename($sidecar, '.json');
                foreach (glob($dir . '/' . $stem . '.*') ?: [] as $file) {
                    self::remove($file);
                }
                ++$count;
            }
        }

        return $count;
    }

    /**
     * A file's name on disk: a keyed hash of its id. Web servers that ignore the
     * folder's guards (nginx) would serve a file to anyone who knows its path; the
     * id reaches the browser and the stored chat, the path does not.
     */
    private static function stem(string $id): string
    {
        $key = \function_exists('wp_salt') ? wp_salt('auth') : (\defined('AUTH_SALT') ? (string) AUTH_SALT : __FILE__);

        return substr(hash_hmac('sha256', $id, 'aikit-attachment|' . $key), 0, 40);
    }

    private function userDir(int $userId, bool $create = false): string
    {
        $base = $this->baseDir();
        $dir = $base . '/' . $userId;
        if ($create) {
            self::protect($base);
            if (!is_dir($dir)) {
                \function_exists('wp_mkdir_p') ? wp_mkdir_p($dir) : mkdir($dir, 0755, true); // phpcs:ignore WordPress.WP.AlternativeFunctions
            }
            if (!is_file($dir . '/index.html')) {
                file_put_contents($dir . '/index.html', ''); // phpcs:ignore WordPress.WP.AlternativeFunctions
            }
        }

        return $dir;
    }

    /** No listing, no direct download: the files are only read by PHP. */
    private static function protect(string $base): void
    {
        if (!is_dir($base)) {
            \function_exists('wp_mkdir_p') ? wp_mkdir_p($base) : mkdir($base, 0755, true); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
        $guards = [
            '.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
            'index.html' => '',
        ];
        foreach ($guards as $file => $content) {
            if (!is_file($base . '/' . $file)) {
                file_put_contents($base . '/' . $file, $content); // phpcs:ignore WordPress.WP.AlternativeFunctions
            }
        }
    }

    /** A path PHP wrote an upload to: the upload temp folder or the system temp folder. */
    private static function isTemporaryUpload(string $path): bool
    {
        if (is_uploaded_file($path)) {
            return true;
        }
        $real = realpath($path);
        if (false === $real) {
            return false;
        }
        foreach (array_filter([(string) ini_get('upload_tmp_dir'), sys_get_temp_dir()]) as $dir) {
            $root = realpath($dir);
            if (false !== $root && str_starts_with($real, rtrim($root, '/\\') . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = \function_exists('sanitize_file_name') ? sanitize_file_name($name) : preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);

        return mb_substr((string) $name, -120) ?: 'file';
    }

    private static function cap(string $text, int $maxChars): string
    {
        return $maxChars > 0 && mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars) . "\n[…truncated]" : $text;
    }

    private static function readableBytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }

    private static function remove(string $file): bool
    {
        if (!is_file($file)) {
            return false;
        }
        if (\function_exists('wp_delete_file')) {
            wp_delete_file($file);

            return !is_file($file);
        }

        return unlink($file); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : self::remove($path);
        }
        rmdir($dir); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }
}
