<?php

namespace BitApps\AiKit\Mcp\Auth;

/**
 * `WWW-Authenticate` challenges for the MCP endpoint (RFC 6750 §3, RFC 9728 §5.1).
 *
 * A 401 points the client at the protected-resource metadata so it can discover
 * the authorization server; a 403 `insufficient_scope` names the scope to request
 * on step-up. Without OAuth (Application Passwords only) a Basic challenge tells
 * clients to send credentials.
 */
final class WwwAuthenticate
{
    public static function bearer(?string $resourceMetadataUrl, ?string $scope = null, ?string $error = null, ?string $description = null): string
    {
        $params = [];
        if (null !== $error) {
            $params[] = 'error="' . self::quote($error) . '"';
        }
        if (null !== $scope && '' !== $scope) {
            $params[] = 'scope="' . self::quote($scope) . '"';
        }
        if (null !== $resourceMetadataUrl) {
            $params[] = 'resource_metadata="' . self::quote($resourceMetadataUrl) . '"';
        }
        if (null !== $description) {
            $params[] = 'error_description="' . self::quote($description) . '"';
        }

        return 'Bearer' . ([] !== $params ? ' ' . implode(', ', $params) : '');
    }

    public static function basic(string $realm): string
    {
        return 'Basic realm="' . self::quote($realm) . '"';
    }

    private static function quote(string $value): string
    {
        return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\"', '', ''], $value);
    }
}
