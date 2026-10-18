<?php

namespace BitApps\AiKit\Mcp\Auth;

/**
 * Turns a bearer token into a grant, or null when the token is not one of its own.
 * The transport asks each resolver in turn (OAuth, then API keys).
 */
interface BearerResolverInterface
{
    public function resolve(string $token): ?BearerGrant;
}
