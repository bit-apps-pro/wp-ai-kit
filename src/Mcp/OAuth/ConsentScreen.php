<?php

namespace BitApps\AiKit\Mcp\OAuth;

use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Tools\Scopes;

/**
 * The consent screen an AI client sends the user to.
 *
 * Served from admin-post.php (see OAuthEndpoints::consentUrl()). GET shows the
 * screen; the decision is a POST protected by a WordPress nonce. The first GET
 * cannot carry a nonce: it is a cross-site redirect from the client, which the
 * client's own `state` protects. The page refuses to be framed (clickjacking).
 *
 * The user picks the access level here, capped by the site's MCP setting, and
 * whether personal data may be shown to this client. Returns what to do instead
 * of printing, so it can be tested; OAuthServer sends it.
 */
final class ConsentScreen
{
    /** The consent screen's colours when the plugin's `brand` service gives none. */
    public const DEFAULT_PALETTE = [
        'color'           => '#1d4ed8',
        'color_dark'      => '#60a5fa',
        'background'      => '#f8fafc',
        'background_dark' => '#0f172a',
    ];

    private const NONCE_FIELD = '_bitapps_oauth_nonce';

    /**
     * @param callable(): string $ceiling            the highest level the site allows (read|write|destructive)
     * @param callable(): bool   $offersPersonalData whether any tool returns personal data
     * @param callable(): int    $toolCount          tools the current user can use
     */
    public function __construct(
        private PluginContext $context,
        private OAuthEndpoints $endpoints,
        private ClientRegistry $clients,
        private AuthorizationCodes $codes,
        private $ceiling,
        private $offersPersonalData,
        private $toolCount
    ) {}

    /**
     * @param array<string, mixed> $query $_GET, unslashed
     * @param array<string, mixed> $post  $_POST, unslashed
     *
     * @return array{type: string, location?: string, status?: int, html?: string}
     */
    public function respond(string $method, array $query, array $post, int $userId): array
    {
        $isPost = 'POST' === strtoupper($method);
        $params = self::params($isPost ? $post : $query);

        // Sign-in first: an anonymous visitor gets nothing from this endpoint, not
        // even a metadata fetch or an error redirect.
        if ($userId <= 0) {
            return ['type' => 'redirect', 'location' => wp_login_url($this->endpoints->consentUrl($params))];
        }

        // Errors before the user decided are shown here, never redirected: a
        // redirect URI registered by anyone through open registration would
        // otherwise turn this site into a redirector.
        $request = AuthorizeRequest::validate($params, $this->clients, $this->endpoints, $this->context);
        if ($request instanceof \WP_Error) {
            return $this->page($this->message('This connection could not be set up', $request->get_error_message()), 400);
        }

        if (!$this->context->canUse($userId)) {
            return $this->page($this->message(
                'This account cannot connect AI clients',
                sprintf('Your WordPress account is not allowed to use %s with AI clients. Ask a site administrator.', $this->context->name())
            ), 403);
        }

        if (!$isPost) {
            return $this->page($this->form($request, $userId));
        }

        if (!wp_verify_nonce((string) ($post[self::NONCE_FIELD] ?? ''), $this->nonceAction($request))) {
            return $this->page($this->message('The page expired', 'Start the connection again from your AI app.'), 403);
        }

        if (empty($post['approve'])) {
            return $this->toClient($request['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'The user declined the connection.'], $request['state']);
        }

        $ceiling = $this->ceiling();
        $level = \is_string($post['access'] ?? null) && \in_array($post['access'], Scopes::levels(), true) ? $post['access'] : Scopes::READ;
        $level = AuthorizeRequest::lowerLevel($level, $ceiling);

        $code = $this->codes->issue([
            'client_id'      => $request['client_id'],
            'client_name'    => $request['client_name'],
            'client_kind'    => $request['client_kind'],
            'client_host'    => $request['client_host'],
            'redirect_uri'   => $request['redirect_uri'],
            'code_challenge' => $request['code_challenge'],
            'scope'          => $level,
            'user_id'        => $userId,
            'resource'       => $request['resource'],
            'personal_data'  => !empty($post['personal_data']) && ($this->offersPersonalData)(),
        ]);

        return $this->toClient($request['redirect_uri'], ['code' => $code], $request['state']);
    }

    /** Headers every consent response carries. */
    public static function headers(): array
    {
        return [
            'Content-Type'            => 'text/html; charset=utf-8',
            'Cache-Control'           => 'no-store',
            'X-Frame-Options'         => 'DENY',
            'Content-Security-Policy' => "frame-ancestors 'none'",
            'Referrer-Policy'         => 'no-referrer',
            'X-Robots-Tag'            => 'noindex, nofollow',
        ];
    }

    /**
     * Only the OAuth parameters, unslashed and length-capped. Not run through
     * sanitize_text_field(): it strips %-encoded octets, which would corrupt a
     * redirect URI or state. They are matched exactly and escaped on output.
     *
     * @param array<string, mixed> $source
     *
     * @return array<string, string>
     */
    public static function params(array $source): array
    {
        $params = [];
        foreach (AuthorizeRequest::PARAMS as $key) {
            $value = $source[$key] ?? '';
            $value = \is_string($value) ? trim($value) : '';
            $params[$key] = \strlen($value) <= 2000 && !preg_match('/[\x00-\x1F\x7F]/', $value) ? $value : '';
        }

        return $params;
    }

    private function ceiling(): string
    {
        $ceiling = ($this->ceiling)();

        return \in_array($ceiling, Scopes::levels(), true) ? $ceiling : Scopes::READ;
    }

    /** @param array<string, mixed> $request */
    private function nonceAction(array $request): string
    {
        return $this->endpoints->consentAction() . '|' . $request['client_id'];
    }

    /**
     * @param array<string, string> $args
     *
     * @return array{type: string, location: string}
     */
    private function toClient(string $redirectUri, array $args, string $state): array
    {
        if ('' !== $state) {
            $args['state'] = $state;
        }
        $args['iss'] = $this->endpoints->issuer();

        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return ['type' => 'redirect', 'location' => $redirectUri . $separator . http_build_query($args, '', '&', PHP_QUERY_RFC3986)];
    }

    /** @return array{type: string, status: int, html: string} */
    private function page(string $body, int $status = 200): array
    {
        return ['type' => 'page', 'status' => $status, 'html' => $this->document($body)];
    }

    private function message(string $title, string $text): string
    {
        return '<h1>' . esc_html($title) . '</h1><p class="lead">' . esc_html($text) . '</p>';
    }

    /** @param array<string, mixed> $request */
    private function form(array $request, int $userId): string
    {
        $ceiling = $this->ceiling();
        $default = AuthorizeRequest::lowerLevel($request['requested'] ?? Scopes::READ, $ceiling);
        $user = get_userdata($userId);
        $login = \is_object($user) ? (string) ($user->user_login ?? $user->display_name ?? '') : '';
        $name = '' !== $request['client_name'] ? $request['client_name'] : 'An AI app';

        $html = '<h1>' . esc_html(sprintf('Connect %s to %s?', $name, $this->context->name())) . '</h1>';
        $html .= '<p class="lead">' . esc_html(sprintf('%s will use %s on this site as you, with the access you choose below.', $name, $this->context->name())) . '</p>';

        if (ClientRegistry::KIND_CIMD === $request['client_kind'] && '' !== $request['client_host']) {
            $html .= '<p class="note ok">' . esc_html(sprintf('This app’s details are published at %s.', $request['client_host'])) . '</p>';
            if ('' !== $request['redirect_host'] && !self::sameSite($request['redirect_host'], $request['client_host'])) {
                $html .= '<p class="note warn">' . esc_html(sprintf('It sends you back to a different site (%s). Continue only if you trust both.', $request['redirect_host'])) . '</p>';
            }
        } else {
            $html .= '<p class="note warn">' . esc_html('The app chose this name itself; it is not verified. Continue only if you just started this connection from your AI app.') . '</p>';
        }

        $html .= '<dl>';
        $html .= '<dt>WordPress account</dt><dd>' . esc_html($login) . '</dd>';
        $html .= '<dt>Site</dt><dd>' . esc_html((string) parse_url(home_url(), PHP_URL_HOST)) . '</dd>';
        $html .= '<dt>Sends you back to</dt><dd>' . esc_html(self::destination((string) $request['redirect_uri'])) . '</dd>';
        $html .= '<dt>Tools you can use</dt><dd>' . esc_html((string) (int) ($this->toolCount)()) . '</dd>';
        $html .= '</dl>';

        $html .= '<form method="post" action="' . esc_url($this->endpoints->consentUrl()) . '">';
        foreach ($request as $key => $value) {
            if (\in_array($key, AuthorizeRequest::PARAMS, true)) {
                $html .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string) $value) . '">';
            }
        }
        $html .= '<input type="hidden" name="response_type" value="code"><input type="hidden" name="code_challenge_method" value="S256">';
        $html .= '<input type="hidden" name="' . self::NONCE_FIELD . '" value="' . esc_attr(wp_create_nonce($this->nonceAction($request))) . '">';

        $html .= '<fieldset><legend>What may it do?</legend>';
        foreach (self::levelLabels() as $level => [$label, $help]) {
            if (!Scopes::withinLevel($level, $ceiling)) {
                continue;
            }
            $html .= '<label class="choice"><input type="radio" name="access" value="' . esc_attr($level) . '"' . ($level === $default ? ' checked' : '') . '>'
                . '<span><strong>' . esc_html($label) . '</strong><small>' . esc_html($help) . '</small></span></label>';
        }
        $html .= '</fieldset>';

        if (($this->offersPersonalData)()) {
            $html .= '<label class="choice"><input type="checkbox" name="personal_data" value="1">'
                . '<span><strong>Show personal data</strong><small>Email addresses, phone numbers, names and addresses in entries. Off: they are masked before the AI app sees them.</small></span></label>';
        }

        $html .= '<p class="hint">' . esc_html(sprintf('You can disconnect this app at any time in the %s MCP server settings.', $this->context->name())) . '</p>';
        $html .= '<div class="actions"><button type="submit" name="deny" value="1" class="ghost">Cancel</button><button type="submit" name="approve" value="1" class="primary">Allow</button></div>';
        $html .= '</form>';

        return $html;
    }

    /** Where the browser goes back to: the host for https, scheme and host otherwise (`cursor://…`, `http://127.0.0.1`). */
    private static function destination(string $redirectUri): string
    {
        $scheme = strtolower((string) parse_url($redirectUri, PHP_URL_SCHEME));
        $host = (string) parse_url($redirectUri, PHP_URL_HOST);

        if ('https' === $scheme && '' !== $host) {
            return $host;
        }

        return '' !== $host ? $scheme . '://' . $host : $redirectUri;
    }

    /** Same registrable site, roughly: equal, or one is a subdomain of the other. */
    private static function sameSite(string $a, string $b): bool
    {
        $a = strtolower($a);
        $b = strtolower($b);

        return $a === $b || str_ends_with($a, '.' . $b) || str_ends_with($b, '.' . $a);
    }

    /** @return array<string, array{0: string, 1: string}> */
    private static function levelLabels(): array
    {
        return [
            Scopes::READ        => ['Read only', 'Look at data. Nothing changes.'],
            Scopes::WRITE       => ['Read and write', 'Also make changes that can be undone.'],
            Scopes::DESTRUCTIVE => ['Read, write and delete', 'Also actions that cannot be undone.'],
        ];
    }

    private function document(string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>' . esc_html($this->context->name() . ' · Connect an AI app') . '</title>'
            . '<style>' . self::styles($this->palette()) . '</style></head><body><main>'
            . '<div class="brand">' . esc_html($this->context->name()) . '</div>' . $body . '</main></body></html>';
    }

    /**
     * The page colours from the plugin's `brand` service. A key left out, or not
     * a hex colour, falls back: `color_dark` to the plugin's `color` when it gave
     * one, everything else to DEFAULT_PALETTE.
     *
     * @return array{color: string, color_dark: string, background: string, background_dark: string}
     */
    private function palette(): array
    {
        $brand = $this->context->service('brand');
        $pick = static function (string $key) use ($brand): ?string {
            $value = \is_array($brand) ? ($brand[$key] ?? null) : null;

            return \is_string($value) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value) ? $value : null;
        };
        $color = $pick('color');

        return [
            'color'           => $color ?? self::DEFAULT_PALETTE['color'],
            'color_dark'      => $pick('color_dark') ?? $color ?? self::DEFAULT_PALETTE['color_dark'],
            'background'      => $pick('background') ?? self::DEFAULT_PALETTE['background'],
            'background_dark' => $pick('background_dark') ?? self::DEFAULT_PALETTE['background_dark'],
        ];
    }

    /** Black or white, whichever reads better on the given colour (WCAG relative luminance). */
    private static function textOn(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (3 === \strlen($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $channel = static function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $luminance = 0.2126 * $channel(substr($hex, 0, 2)) + 0.7152 * $channel(substr($hex, 2, 2)) + 0.0722 * $channel(substr($hex, 4, 2));

        // Contrast with white is 1.05 / (L + 0.05), with black (L + 0.05) / 0.05; they cross at L ≈ 0.179.
        return $luminance > 0.179 ? '#0f172a' : '#fff';
    }

    /** @param array{color: string, color_dark: string, background: string, background_dark: string} $palette */
    private static function styles(array $palette): string
    {
        return ':root{--c:' . $palette['color'] . ';--on-c:' . self::textOn($palette['color']) . ';--fg:#0f172a;--mute:#64748b;--line:#e2e8f0;--bg:' . $palette['background'] . ';--card:#fff}'
            . '@media(prefers-color-scheme:dark){:root{--c:' . $palette['color_dark'] . ';--on-c:' . self::textOn($palette['color_dark'])
            . ';--fg:#e2e8f0;--mute:#94a3b8;--line:#334155;--bg:' . $palette['background_dark'] . ';--card:#111827}}'
            . '*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;background:var(--bg);color:var(--fg);'
            . 'font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
            . 'main{width:100%;max-width:460px;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:24px}'
            . '.brand{font-weight:700;color:var(--c);margin-bottom:12px}h1{font-size:19px;margin:0 0 6px}.lead{margin:0 0 14px;color:var(--mute)}'
            . '.note{padding:8px 12px;border-radius:8px;font-size:13px;margin:0 0 14px}.ok{background:#dcfce7;color:#166534}.warn{background:#fef3c7;color:#92400e}'
            . 'dl{display:grid;grid-template-columns:auto 1fr;gap:6px 16px;margin:0 0 16px;font-size:14px}dt{color:var(--mute)}dd{margin:0;text-align:right;word-break:break-word}'
            . 'fieldset{border:0;padding:0;margin:0 0 8px}legend{font-weight:600;margin-bottom:6px}'
            . '.choice{display:flex;gap:10px;align-items:flex-start;padding:8px 0;cursor:pointer}.choice small{display:block;color:var(--mute)}.choice input{margin-top:4px;accent-color:var(--c)}'
            . '.hint{font-size:12.5px;color:var(--mute)}.actions{display:flex;gap:10px;justify-content:flex-end}'
            . 'button{cursor:pointer;border-radius:8px;padding:9px 20px;font:inherit;border:1px solid var(--line)}'
            . '.ghost{background:transparent;color:var(--fg)}.primary{background:var(--c);border-color:var(--c);color:var(--on-c)}'
            . 'button:focus-visible{outline:2px solid var(--c);outline-offset:2px}';
    }
}
