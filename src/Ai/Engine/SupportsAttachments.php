<?php

namespace BitApps\AiKit\Ai\Engine;

/**
 * An engine that can read files beside the text: images and PDFs, sent inline.
 * Text files and Word documents need nothing from the engine (they go as text),
 * so an engine without this interface still takes those.
 */
interface SupportsAttachments
{
    /**
     * Which non-text kinds the engine reads.
     *
     * @return string[] of AttachmentStore::KIND_IMAGE, KIND_PDF
     */
    public function attachmentKinds(): array;

    /** The most file bytes one request may carry, before base64; 0 = the kit's limit. */
    public function maxAttachmentBytes(): int;
}
