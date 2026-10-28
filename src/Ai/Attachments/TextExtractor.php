<?php

namespace BitApps\AiKit\Ai\Attachments;

use BitApps\AiKit\Ai\AiException;

/**
 * Text out of the attachments every engine can read as text: plain text in any
 * common encoding, and the body of a Word document (.docx is a zip of XML; only
 * `word/document.xml` is read, paragraphs and tabs kept, nothing executed).
 */
final class TextExtractor
{
    /** A .docx larger than this once unzipped is refused (zip bombs). */
    private const MAX_DOCUMENT_XML_BYTES = 30 * 1024 * 1024;

    public static function docx(string $path): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new AiException('Word documents cannot be read on this server (the PHP zip extension is missing).', 'attachment_unsupported');
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            throw new AiException('The file is not a valid Word document.', 'attachment_invalid');
        }

        $index = $zip->locateName('word/document.xml');
        $stat = false !== $index ? $zip->statIndex($index) : false;
        if (false === $index || !\is_array($stat) || (int) $stat['size'] > self::MAX_DOCUMENT_XML_BYTES) {
            $zip->close();

            throw new AiException('The file is not a valid Word document.', 'attachment_invalid');
        }
        $xml = (string) $zip->getFromIndex($index);
        $zip->close();

        // Paragraph and line breaks become newlines, tabs tabs; every other tag goes.
        $xml = preg_replace(['#</w:p>#', '#<w:br[^>]*/>#', '#<w:tab[^>]*/>#'], ["\n", "\n", "\t"], $xml) ?? '';
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    /** Plain text as UTF-8, whatever common encoding it came in; a byte-order mark dropped. */
    public static function utf8(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $converted = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');

            return trim(ltrim($converted, "\u{FEFF}"));
        }

        if (!mb_check_encoding($raw, 'UTF-8')) {
            $from = mb_detect_encoding($raw, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true) ?: 'ISO-8859-1';
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', $from);
        }

        return trim(str_replace("\r\n", "\n", $raw));
    }
}
