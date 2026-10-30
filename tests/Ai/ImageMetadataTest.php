<?php

namespace BitApps\AiKit\Tests\Ai;

use BitApps\AiKit\Ai\Attachments\ImageMetadata;
use PHPUnit\Framework\TestCase;

/**
 * Photos lose their EXIF (GPS, camera), XMP and text metadata, keep their pixels,
 * and a JPEG keeps its orientation.
 *
 * @internal
 */
final class ImageMetadataTest extends TestCase
{
    /** A 1×1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    public function testAJpegLosesItsExifButKeepsItsOrientation(): void
    {
        $plain = self::jpegFromGd();
        // SOI, then an EXIF block (orientation 6 and a GPS pointer), a comment, then the rest.
        $exif = "Exif\0\0" . 'II' . pack('v', 42) . pack('V', 8) . pack('v', 2)
            . pack('vvVvv', 0x0112, 3, 1, 6, 0)
            . pack('vvVV', 0x8825, 4, 1, 38) . pack('V', 0)
            . 'GPS 23.81N 90.41E';
        $jpeg = "\xFF\xD8\xFF\xE1" . pack('n', \strlen($exif) + 2) . $exif
            . "\xFF\xFE" . pack('n', 12) . 'Canon EOS!'
            . substr($plain, 2);

        $clean = ImageMetadata::jpeg($jpeg);

        self::assertNotNull($clean);
        self::assertStringNotContainsString('GPS 23.81N', $clean);
        self::assertStringNotContainsString('Canon', $clean);
        self::assertStringEndsWith(substr($plain, 2), $clean, 'the image itself is untouched');
        self::assertSame(6, self::orientationOf($clean), 'still shown upright');
        self::assertNotFalse(@imagecreatefromstring($clean), 'still a readable JPEG');
    }

    public function testAJpegWithoutExifIsUnchangedAndOtherFilesAreLeftAlone(): void
    {
        $plain = self::jpegFromGd();

        self::assertSame($plain, ImageMetadata::jpeg($plain));
        self::assertNull(ImageMetadata::jpeg('not a jpeg'));
        self::assertNull(ImageMetadata::png('not a png'));
        self::assertNull(ImageMetadata::webp('RIFF....WAVE'));
    }

    public function testAPngLosesItsTextAndExifChunks(): void
    {
        $png = base64_decode(self::PNG, true);
        $chunk = static fn (string $type, string $data): string => pack('N', \strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        // Insert after IHDR (8-byte signature + 25-byte IHDR chunk).
        $tagged = substr($png, 0, 33) . $chunk('tEXt', "Author\0Jane Doe") . $chunk('eXIf', 'MM*GPS') . substr($png, 33);

        $clean = ImageMetadata::png($tagged);

        self::assertSame($png, $clean);
    }

    public function testAWebpLosesItsExifAndXmpChunksAndFlags(): void
    {
        $vp8x = 'VP8X' . pack('V', 10) . \chr(0x0C) . str_repeat("\0", 9);
        $image = 'VP8L' . pack('V', 5) . "\x2F\0\0\0\0\0";
        $exif = 'EXIF' . pack('V', 3) . 'GPS' . "\0";
        $xmp = 'XMP ' . pack('V', 4) . '<x/>';
        $body = 'WEBP' . $vp8x . $image . $exif . $xmp;
        $webp = 'RIFF' . pack('V', \strlen($body)) . $body;

        $clean = ImageMetadata::webp($webp);

        $expectedBody = 'WEBPVP8X' . pack('V', 10) . "\0" . str_repeat("\0", 9) . $image;
        self::assertSame('RIFF' . pack('V', \strlen($expectedBody)) . $expectedBody, $clean);
    }

    public function testStripRewritesTheFileInPlace(): void
    {
        $plain = self::jpegFromGd();
        $comment = "\xFF\xFE" . pack('n', 7) . 'notes';
        $path = tempnam(sys_get_temp_dir(), 'aikit') . '.jpg';
        file_put_contents($path, "\xFF\xD8" . $comment . substr($plain, 2));

        try {
            self::assertSame(\strlen($plain), ImageMetadata::strip($path, 'jpg'));
            self::assertSame($plain, file_get_contents($path));
            self::assertNull(ImageMetadata::strip($path, 'jpg'), 'nothing left to remove');
            self::assertNull(ImageMetadata::strip($path, 'gif'));
        } finally {
            @unlink($path);
        }
    }

    private static function jpegFromGd(): string
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD is not available.');
        }
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($image);

        // GD signs its JPEGs with a comment; without it the file has no metadata at all.
        return (string) ImageMetadata::jpeg((string) ob_get_clean());
    }

    private static function orientationOf(string $jpeg): ?int
    {
        $path = tempnam(sys_get_temp_dir(), 'aikit');
        file_put_contents($path, $jpeg);
        $exif = \function_exists('exif_read_data') ? @exif_read_data($path) : false;
        @unlink($path);

        return \is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : null;
    }
}
