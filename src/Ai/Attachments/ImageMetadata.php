<?php

namespace BitApps\AiKit\Ai\Attachments;

/**
 * Removes what a photo says about where and with what it was taken (EXIF with
 * its GPS position, XMP, IPTC, comments) before it is kept and sent to the AI
 * provider. The pixels are not touched: segments and chunks are dropped, nothing
 * is re-encoded. A JPEG keeps its orientation, so it still shows upright.
 *
 * Works on JPEG, PNG and WebP; anything it cannot read is left as it is.
 */
final class ImageMetadata
{
    /** JPEG segments dropped: APP1 (EXIF, XMP), APP13 (IPTC), COM. */
    private const JPEG_DROP = [0xE1, 0xED, 0xFE];

    /** PNG chunks dropped: EXIF, text (author, software…), modification time. */
    private const PNG_DROP = ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'];

    /**
     * Strip the file in place.
     *
     * @return null|int the new size, or null when nothing was changed
     */
    public static function strip(string $path, string $ext): ?int
    {
        $data = @file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a private file the store just wrote.
        if (!\is_string($data) || '' === $data) {
            return null;
        }

        $clean = match ($ext) {
            'jpg', 'jpeg' => self::jpeg($data),
            'png'         => self::png($data),
            'webp'        => self::webp($data),
            default       => null,
        };
        if (null === $clean || $clean === $data) {
            return null;
        }

        $temp = $path . '.tmp';
        if (false === file_put_contents($temp, $clean) || !rename($temp, $path)) { // phpcs:ignore WordPress.WP.AlternativeFunctions
            @unlink($temp);

            return null;
        }

        return \strlen($clean);
    }

    public static function jpeg(string $data): ?string
    {
        if (!str_starts_with($data, "\xFF\xD8")) {
            return null;
        }
        $out = "\xFF\xD8";
        $kept = false;
        $length = \strlen($data);
        $at = 2;

        while ($at + 4 <= $length) {
            if ("\xFF" !== $data[$at]) {
                return null;
            }
            $marker = \ord($data[$at + 1]);
            if (0xFF === $marker) {
                // Fill byte before a marker.
                ++$at;

                continue;
            }
            if (0xDA === $marker) {
                // Start of scan: the image data and everything after it stay as they are.
                return $out . substr($data, $at);
            }
            if (0x01 === $marker || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $out .= substr($data, $at, 2);
                $at += 2;

                continue;
            }
            $size = unpack('n', substr($data, $at + 2, 2))[1];
            if ($size < 2 || $at + 2 + $size > $length) {
                return null;
            }
            $segment = substr($data, $at, 2 + $size);
            if (0xE1 === $marker && !$kept && str_starts_with(substr($segment, 4), "Exif\0\0")) {
                // In the EXIF block's place: only the orientation, so the photo still shows upright.
                $out .= self::orientationSegment(self::exifOrientation(substr($segment, 10)));
                $kept = true;
            } elseif (!\in_array($marker, self::JPEG_DROP, true)) {
                $out .= $segment;
            }
            $at += 2 + $size;
        }

        return null;
    }

    public static function png(string $data): ?string
    {
        $signature = "\x89PNG\r\n\x1A\n";
        if (!str_starts_with($data, $signature)) {
            return null;
        }
        $out = $signature;
        $length = \strlen($data);
        $at = 8;

        while ($at + 12 <= $length) {
            $size = unpack('N', substr($data, $at, 4))[1];
            $type = substr($data, $at + 4, 4);
            if ($at + 12 + $size > $length) {
                return null;
            }
            if (!\in_array($type, self::PNG_DROP, true)) {
                $out .= substr($data, $at, 12 + $size);
            }
            $at += 12 + $size;
            if ('IEND' === $type) {
                return $out;
            }
        }

        return null;
    }

    public static function webp(string $data): ?string
    {
        if (\strlen($data) < 12 || !str_starts_with($data, 'RIFF') || 'WEBP' !== substr($data, 8, 4)) {
            return null;
        }
        $body = 'WEBP';
        $length = \strlen($data);
        $at = 12;

        while ($at + 8 <= $length) {
            $type = substr($data, $at, 4);
            $size = unpack('V', substr($data, $at + 4, 4))[1];
            $padded = $size + ($size % 2);
            if ($at + 8 + $size > $length) {
                return null;
            }
            $chunk = substr($data, $at, 8 + $padded);
            if ('VP8X' === $type && $size >= 1) {
                // Flags: clear "has EXIF" (0x08) and "has XMP" (0x04).
                $chunk[8] = \chr(\ord($chunk[8]) & ~0x0C);
            }
            if ('EXIF' !== $type && 'XMP ' !== $type) {
                $body .= $chunk;
            }
            $at += 8 + $padded;
        }

        return 'RIFF' . pack('V', \strlen($body)) . $body;
    }

    /** The orientation tag (1–8) of an EXIF block's first image, or null. */
    private static function exifOrientation(string $tiff): ?int
    {
        $order = substr($tiff, 0, 2);
        if ('II' !== $order && 'MM' !== $order) {
            return null;
        }
        $short = 'II' === $order ? 'v' : 'n';
        $long = 'II' === $order ? 'V' : 'N';
        $read = (static fn (string $format, int $offset, int $bytes): ?int => $offset + $bytes <= \strlen($tiff) ? unpack($format, substr($tiff, $offset, $bytes))[1] : null);

        $ifd = $read($long, 4, 4);
        $count = null === $ifd ? null : $read($short, $ifd, 2);
        for ($i = 0; null !== $count && $i < $count; ++$i) {
            $entry = $ifd + 2 + 12 * $i;
            if (0x0112 === $read($short, $entry, 2)) {
                $value = $read($short, $entry + 8, 2);

                return null !== $value && $value >= 1 && $value <= 8 ? $value : null;
            }
        }

        return null;
    }

    /** A minimal EXIF segment with only the orientation, when it is not the default. */
    private static function orientationSegment(?int $orientation): string
    {
        if (null === $orientation || 1 === $orientation) {
            return '';
        }
        // Big-endian TIFF: header, one IFD with one SHORT entry, no next IFD.
        $tiff = 'MM' . pack('n', 42) . pack('N', 8) . pack('n', 1) . pack('nnN', 0x0112, 3, 1) . pack('nn', $orientation, 0) . pack('N', 0);
        $payload = "Exif\0\0" . $tiff;

        return "\xFF\xE1" . pack('n', \strlen($payload) + 2) . $payload;
    }
}
