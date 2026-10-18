<?php

namespace BitApps\AiKit\Security;

/**
 * Encrypts secrets the kit must keep (a provider API key for the HTTP engine).
 *
 * XSalsa20-Poly1305 (`sodium_crypto_secretbox`, available on every WordPress
 * through sodium_compat) with a key derived from the site's auth salt and the
 * plugin slug — not from a value stored next to the ciphertext, which is what
 * Bit PI's and Bit CRM's `Hash` helpers do. Changing the salts makes stored
 * secrets unreadable; the admin then enters the key again.
 */
final class SecretBox
{
    private const PREFIX = 'sb1:';

    public function __construct(private string $slug) {}

    public function encrypt(string $plain): string
    {
        if ('' === $plain) {
            return '';
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    /** The plaintext, or '' when the value is empty, foreign or cannot be opened. */
    public function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return '';
        }

        $raw = base64_decode(substr($stored, \strlen(self::PREFIX)), true);
        if (!\is_string($raw) || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        try {
            $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());
        } catch (\Throwable $error) {
            return '';
        }

        return \is_string($plain) ? $plain : '';
    }

    private function key(): string
    {
        return hash('sha256', wp_salt('auth') . '|bitapps-ai|' . $this->slug, true);
    }
}
