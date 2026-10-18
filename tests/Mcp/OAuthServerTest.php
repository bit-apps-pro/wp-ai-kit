<?php

namespace BitApps\AiKit\Tests\Mcp;

use BitApps\AiKit\Contract;
use BitApps\AiKit\Kit;
use BitApps\AiKit\Mcp\OAuth\AuthorizationCodes;
use BitApps\AiKit\Mcp\OAuth\ClientRegistry;
use BitApps\AiKit\Mcp\OAuth\ConsentScreen;
use BitApps\AiKit\Mcp\OAuth\OAuthServer;
use BitApps\AiKit\Mcp\OAuth\TokenService;
use BitApps\AiKit\Tests\TestCase;

/**
 * The authorization server end to end, plus the cases ported from Bit CRM's
 * tests/Settings/McpOAuthTest.php (discovery, registration, PKCE, codes, tokens,
 * rotation, revocation).
 *
 * @internal
 */
final class OAuthServerTest extends TestCase
{
    private const ADMIN = 5;

    private const RESOURCE = 'https://example.test/wp-json/bit-demo/v1/mcp';

    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    protected function setUp(): void
    {
        parent::setUp();
        $this->grant(self::ADMIN, 'manage_options');
        update_option('permalink_structure', '/%postname%/');
        update_option('bit_demo_mcp_settings', ['enabled' => true, 'oauth' => true, 'access_level' => 'write']);

        Kit::boot($this->context());
        add_filter(Contract::toolProvidersHook('bit-demo'), static function ($providers) {
            $providers[] = [
                ['name' => 'list-forms', 'description' => 'Forms.', 'annotations' => ['readonly' => true], 'handler' => static fn () => [['id' => 1]]],
                ['name' => 'rename-form', 'description' => 'Rename.', 'handler' => static fn () => ['ok' => true]],
                ['name' => 'get-entry', 'description' => 'Entry.', 'annotations' => ['readonly' => true], 'personal_data' => true, 'handler' => static fn () => ['email' => 'jane@example.com']],
            ];

            return $providers;
        }, 10, 1);
    }

    public function testDiscoveryDocumentsMeetTheMcpSpec(): void
    {
        $server = $this->server();

        $prm = $server->wellKnownDocument('/.well-known/oauth-protected-resource/wp-json/bit-demo/v1/mcp');
        self::assertSame(self::RESOURCE, $prm['resource']);
        self::assertSame([self::RESOURCE], $prm['authorization_servers']);
        self::assertSame(['header'], $prm['bearer_methods_supported']);

        $as = $server->wellKnownDocument('/.well-known/oauth-authorization-server/wp-json/bit-demo/v1/mcp/');
        self::assertSame(self::RESOURCE, $as['issuer']);
        self::assertSame(['S256'], $as['code_challenge_methods_supported']);
        self::assertSame(['none'], $as['token_endpoint_auth_methods_supported']);
        self::assertTrue($as['client_id_metadata_document_supported']);
        self::assertSame('https://example.test/wp-json/bit-demo/v1/mcp/oauth/revoke', $as['revocation_endpoint']);
        self::assertContains('offline_access', $as['scopes_supported']);

        self::assertNull($server->wellKnownDocument('/.well-known/oauth-authorization-server'), 'the bare root slot is never claimed');
        self::assertNull($server->wellKnownDocument('/.well-known/oauth-authorization-server/wp-json/other/v1/mcp'));
        self::assertSame('https://example.test/.well-known/oauth-protected-resource/wp-json/bit-demo/v1/mcp', $server->endpoints()->protectedResourceMetadataUrl());
    }

    public function testOAuthIsOffWithoutPrettyPermalinksOrTheSetting(): void
    {
        update_option('permalink_structure', '');
        self::assertNull(Kit::for('bit-demo')->oauth());

        update_option('permalink_structure', '/%postname%/');
        update_option('bit_demo_mcp_settings', ['enabled' => true, 'oauth' => false]);
        self::assertNull(Kit::for('bit-demo')->oauth());
    }

    public function testRegistrationRejectsUnusableRedirectUris(): void
    {
        foreach (['http://evil.example/cb', 'javascript:alert(1)', 'https://claude.ai/cb#frag', 'data:text/html,x', ''] as $uri) {
            $response = $this->server()->handleRegister($this->jsonRequest(['redirect_uris' => [$uri]]));
            self::assertSame(400, $response->get_status(), $uri);
            self::assertSame('invalid_redirect_uri', $response->get_data()['error']);
        }
    }

    public function testRegistrationAcceptsPrivateUseSchemesAndLoopback(): void
    {
        $response = $this->server()->handleRegister($this->jsonRequest(['client_name' => 'Cursor', 'redirect_uris' => ['cursor://anysphere.cursor-mcp/oauth/callback', 'http://127.0.0.1/callback']]));

        self::assertSame(201, $response->get_status());
        self::assertCount(2, $response->get_data()['redirect_uris']);
    }

    public function testRegistrationIssuesAPublicClient(): void
    {
        $client = $this->register();

        self::assertStringStartsWith('bitdemo_', $client['client_id']);
        self::assertSame('none', $client['token_endpoint_auth_method']);
        self::assertArrayNotHasKey('client_secret', $client);
        self::assertSame('no-store', $this->server()->handleRegister($this->jsonRequest(['redirect_uris' => [self::REDIRECT]]))->get_headers()['Cache-Control']);
    }

    public function testRegistrationIsCappedWithoutEvictingLiveConnections(): void
    {
        $first = $this->register();
        $this->connect($first['client_id']);

        for ($i = 0; $i < ClientRegistry::MAX_CLIENTS + 5; ++$i) {
            $_SERVER['REMOTE_ADDR'] = '10.0.' . intdiv($i, 200) . '.' . ($i % 200);
            $this->register();
        }

        $clients = get_option('bit_demo_mcp_oauth_clients');
        self::assertCount(ClientRegistry::MAX_CLIENTS, $clients);
        self::assertArrayHasKey($first['client_id'], $clients, 'a client with a grant is never evicted');
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function testLoopbackRedirectUrisMatchIgnoringPort(): void
    {
        $client = ['redirect_uris' => ['http://localhost/callback']];

        self::assertTrue(ClientRegistry::redirectUriRegistered($client, 'http://localhost:49152/callback'));
        self::assertFalse(ClientRegistry::redirectUriRegistered($client, 'http://localhost:49152/other'));
        self::assertFalse(ClientRegistry::redirectUriRegistered(['redirect_uris' => ['https://a.example/cb']], 'https://a.example:8443/cb'), 'only loopback ignores the port');
    }

    public function testClientMetadataDocumentsAreFetchedValidatedAndCached(): void
    {
        $id = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
        \AiKitTestState::$httpResponses[$id] = ['response' => ['code' => 200], 'body' => json_encode([
            'client_id'     => $id,
            'client_name'   => 'Claude',
            'redirect_uris' => [self::REDIRECT],
        ])];

        $client = $this->server()->clients()->find($id);
        self::assertSame(ClientRegistry::KIND_CIMD, $client['kind']);
        self::assertSame('claude.ai', $client['host']);
        self::assertSame(0, \AiKitTestState::$httpRequests[0]['args']['redirection'], 'redirects are not followed');

        $this->server()->clients()->find($id);
        self::assertCount(1, \AiKitTestState::$httpRequests, 'the document is cached');

        $spoof = 'https://evil.example/claude.json';
        \AiKitTestState::$httpResponses[$spoof] = ['response' => ['code' => 200], 'body' => json_encode(['client_id' => $id, 'redirect_uris' => [self::REDIRECT]])];
        self::assertNull($this->server()->clients()->find($spoof), 'client_id must equal the document URL');

        $secret = 'https://app.example/client.json';
        \AiKitTestState::$httpResponses[$secret] = ['response' => ['code' => 200], 'body' => json_encode(['client_id' => $secret, 'redirect_uris' => [self::REDIRECT], 'token_endpoint_auth_method' => 'client_secret_basic'])];
        self::assertNull($this->server()->clients()->find($secret), 'confidential clients are refused');

        self::assertFalse(ClientRegistry::isMetadataDocumentId('https://claude.ai/'), 'a metadata document URL needs a path');
    }

    public function testChatGptsDocumentIsAcceptedThroughItsSupportedMethods(): void
    {
        // ChatGPT's published document (https://chatgpt.com/oauth/client.json): it prefers
        // private_key_jwt but supports none, which is what this server offers.
        $id = 'https://chatgpt.com/oauth/client.json';
        \AiKitTestState::$httpResponses[$id] = ['response' => ['code' => 200], 'body' => json_encode([
            'client_id'                             => $id,
            'client_name'                           => 'ChatGPT',
            'redirect_uris'                         => ['https://chatgpt.com/connector_platform_oauth_redirect'],
            'token_endpoint_auth_method'            => 'private_key_jwt',
            'token_endpoint_auth_methods_supported' => ['none', 'private_key_jwt'],
            'jwks_uri'                              => 'https://chatgpt.com/oauth/jwks.json',
        ])];

        $client = $this->server()->clients()->find($id);
        self::assertNotNull($client);
        self::assertSame('ChatGPT', $client['client_name']);
        self::assertSame('chatgpt.com', $client['host']);

        $jwtOnly = 'https://app.example/jwt-client.json';
        \AiKitTestState::$httpResponses[$jwtOnly] = ['response' => ['code' => 200], 'body' => json_encode([
            'client_id'                             => $jwtOnly,
            'redirect_uris'                         => [self::REDIRECT],
            'token_endpoint_auth_methods_supported' => ['private_key_jwt'],
        ])];
        self::assertNull($this->server()->clients()->find($jwtOnly), 'a client that cannot use none is refused');
    }

    public function testAuthorizeRequiresPkceS256(): void
    {
        $client = $this->register();

        $outcome = $this->consent('GET', $this->authorizeParams($client['client_id'], ['code_challenge_method' => 'plain']));

        self::assertSame('page', $outcome['type'], 'errors are shown, never redirected');
        self::assertStringContainsString('PKCE', $outcome['html']);
    }

    public function testAnAttackerRegisteredRedirectIsNeverFollowedBeforeConsent(): void
    {
        $evil = $this->server()->handleRegister($this->jsonRequest(['client_name' => 'Claude', 'redirect_uris' => ['https://evil.example/x']]))->get_data();
        $params = $this->authorizeParams($evil['client_id'], ['redirect_uri' => 'https://evil.example/x', 'response_type' => 'bad']);

        $anonymous = $this->consent('GET', $params, [], 0);
        self::assertStringStartsWith('https://example.test/wp-login.php', $anonymous['location'], 'anonymous visitors only reach the login page');

        $signedIn = $this->consent('GET', $params);
        self::assertSame('page', $signedIn['type']);
        self::assertSame(400, $signedIn['status']);
    }

    public function testAnonymousVisitorsTriggerNoMetadataFetch(): void
    {
        $this->consent('GET', $this->authorizeParams('https://attacker.example/client.json'), [], 0);

        self::assertSame([], \AiKitTestState::$httpRequests);
    }

    public function testUntrustedRedirectUriErrorsAreNotRedirectable(): void
    {
        $client = $this->register();

        $outcome = $this->consent('GET', $this->authorizeParams($client['client_id'], ['redirect_uri' => 'https://evil.example/cb']));
        self::assertSame('page', $outcome['type']);
        self::assertSame(400, $outcome['status']);

        $unknown = $this->consent('GET', $this->authorizeParams('nope'));
        self::assertSame('page', $unknown['type']);
    }

    public function testAuthorizeRedirectsTheBrowserToTheConsentScreen(): void
    {
        $request = new \WP_REST_Request('GET');
        $request->query = $this->authorizeParams('bitdemo_abc');

        $response = $this->server()->handleAuthorize($request);

        self::assertSame(302, $response->get_status());
        self::assertStringStartsWith('https://example.test/wp-admin/admin-post.php?action=bitdemo_mcp_authorize&client_id=bitdemo_abc', $response->get_headers()['Location']);
    }

    public function testLoggedOutUsersAreSentToLogin(): void
    {
        $client = $this->register();
        $outcome = $this->consent('GET', $this->authorizeParams($client['client_id']), [], 0);

        self::assertSame('redirect', $outcome['type']);
        self::assertStringStartsWith('https://example.test/wp-login.php?redirect_to=', $outcome['location']);
        self::assertStringContainsString(rawurlencode('action=bitdemo_mcp_authorize'), $outcome['location']);
    }

    public function testConsentScreenShowsTheChoicesAndRefusesFraming(): void
    {
        $client = $this->register();
        $outcome = $this->consent('GET', $this->authorizeParams($client['client_id']));

        self::assertSame('page', $outcome['type']);
        self::assertStringContainsString('Connect Claude to Bit Demo?', $outcome['html']);
        self::assertStringContainsString('not verified', $outcome['html']);
        self::assertStringContainsString('value="write" checked', $outcome['html']);
        self::assertStringNotContainsString('value="destructive"', $outcome['html'], 'levels above the site setting are not offered');
        self::assertStringContainsString('name="personal_data"', $outcome['html']);
        self::assertSame("frame-ancestors 'none'", ConsentScreen::headers()['Content-Security-Policy']);
        self::assertSame('DENY', ConsentScreen::headers()['X-Frame-Options']);
    }

    public function testConsentNeedsAValidNonceAndCanBeDeclined(): void
    {
        $client = $this->register();
        $params = $this->authorizeParams($client['client_id']);

        $forged = $this->consent('POST', [], $params + ['approve' => '1', '_bitapps_oauth_nonce' => 'forged']);
        self::assertSame(403, $forged['status']);

        $declined = $this->consent('POST', [], $params + ['deny' => '1', '_bitapps_oauth_nonce' => $this->nonce($client['client_id'])]);
        self::assertStringContainsString('error=access_denied', $declined['location']);
    }

    public function testFullFlowIssuesATokenThatActsAsTheApprovingUser(): void
    {
        $client = $this->register();
        $tokens = $this->connect($client['client_id'], 'read');

        self::assertSame('Bearer', $tokens['token_type']);
        self::assertSame('read', $tokens['scope']);
        self::assertSame(3600, $tokens['expires_in']);

        $grant = $this->server()->resolve($tokens['access_token']);
        self::assertSame(self::ADMIN, $grant->userId());
        self::assertSame(['read'], $grant->scopes());
        self::assertSame('Claude', $grant->clientRef());
        self::assertFalse($grant->personalDataAllowed());

        $stored = wp_json_encode(get_option('bit_demo_mcp_oauth_grants'));
        self::assertStringNotContainsString($tokens['access_token'], $stored, 'only digests are stored');
        self::assertStringNotContainsString($tokens['refresh_token'], $stored);
    }

    public function testConsentCannotExceedTheSiteAccessLevel(): void
    {
        $client = $this->register();
        $tokens = $this->connect($client['client_id'], 'destructive');

        self::assertSame('write', $tokens['scope']);
    }

    public function testPersonalDataNeedsExplicitConsent(): void
    {
        $client = $this->register();
        $tokens = $this->connect($client['client_id'], 'read', true);

        self::assertTrue($this->server()->resolve($tokens['access_token'])->personalDataAllowed());
    }

    public function testCodeExchangeChecksClientRedirectAndVerifier(): void
    {
        $client = $this->register();

        foreach ([['client_id' => 'other'], ['redirect_uri' => 'https://claude.ai/other'], ['code_verifier' => str_repeat('a', 43)]] as $override) {
            $code = $this->approve($client['client_id']);
            $response = $this->token(array_merge(['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client['client_id'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER], $override));
            self::assertSame('invalid_grant', $response->get_data()['error'], wp_json_encode($override));
        }
    }

    public function testPkceVerificationMatchesTheRfc7636Vector(): void
    {
        self::assertSame(self::CHALLENGE, AuthorizationCodes::challengeFor(self::VERIFIER));
        self::assertTrue(AuthorizationCodes::verifierMatches(self::VERIFIER, self::CHALLENGE));
        self::assertFalse(AuthorizationCodes::verifierMatches('short', self::CHALLENGE));
    }

    public function testAuthorizationCodeIsSingleUse(): void
    {
        $client = $this->register();
        $code = $this->approve($client['client_id']);
        $body = ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client['client_id'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER];

        self::assertSame(200, $this->token($body)->get_status());
        self::assertSame('invalid_grant', $this->token($body)->get_data()['error']);
    }

    public function testAccessTokenIsBoundToItsResource(): void
    {
        $client = $this->register();
        $tokens = $this->connect($client['client_id']);

        self::assertNull($this->server()->tokens()->validateAccess($tokens['access_token'], 'https://example.test/wp-json/other/v1/mcp'));
        self::assertNull($this->server()->resolve('unknown-token'));
    }

    public function testRefreshTokenRotatesAndReuseRevokesTheGrant(): void
    {
        $client = $this->register();
        $first = $this->connect($client['client_id']);

        $second = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => $client['client_id']])->get_data();
        self::assertNotSame($first['access_token'], $second['access_token']);
        self::assertNull($this->server()->resolve($first['access_token']), 'the old access token dies with the rotation');
        self::assertNotNull($this->server()->resolve($second['access_token']));

        $connections = $this->server()->tokens()->connections();
        self::assertCount(1, $connections, 'rotation keeps one connection with a stable id');

        $replay = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => $client['client_id']]);
        self::assertSame('invalid_grant', $replay->get_data()['error']);
        self::assertNull($this->server()->resolve($second['access_token']), 'replaying a rotated refresh token revokes the grant');
    }

    public function testAStaleWriteCannotReviveARevokedGrant(): void
    {
        $client = $this->register();
        $tokens = $this->connect($client['client_id']);
        $snapshot = get_option('bit_demo_mcp_oauth_grants');

        Kit::for('bit-demo')->revokeOAuthConnection(array_key_first($this->server()->tokens()->connections()));
        update_option('bit_demo_mcp_oauth_grants', $snapshot, false); // a racing request writes back what it read

        self::assertNull($this->server()->resolve($tokens['access_token']));
        self::assertSame('invalid_grant', $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client['client_id']])->get_data()['error']);
    }

    public function testAStaleWriteCannotReviveARotatedRefreshToken(): void
    {
        $client = $this->register();
        $first = $this->connect($client['client_id']);
        $snapshot = get_option('bit_demo_mcp_oauth_grants');

        $second = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => $client['client_id']])->get_data();
        update_option('bit_demo_mcp_oauth_grants', $snapshot + get_option('bit_demo_mcp_oauth_grants'), false);

        self::assertNull($this->server()->resolve($first['access_token']), 'the rotated-away access token stays dead');
        $replay = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => $client['client_id']]);
        self::assertSame('invalid_grant', $replay->get_data()['error']);
        self::assertNull($this->server()->resolve($second['access_token']), 'replaying the old refresh token revokes the grant');
    }

    public function testUseIsRecordedWithoutRewritingGrants(): void
    {
        $client = $this->register();
        $tokens = $this->connect($client['client_id']);
        $before = get_option('bit_demo_mcp_oauth_grants');

        $this->server()->resolve($tokens['access_token']);

        self::assertSame($before, get_option('bit_demo_mcp_oauth_grants'));
        self::assertNotNull(current($this->server()->tokens()->connections())['last_used']);
    }

    public function testGrantsCannotOutliveTheirMaximumLifetime(): void
    {
        $client = $this->register();
        $tokens = $this->connect($client['client_id']);
        $grants = get_option('bit_demo_mcp_oauth_grants');
        foreach ($grants as &$grant) {
            $grant['created'] = time() - TokenService::MAX_LIFETIME + 60;
        }
        update_option('bit_demo_mcp_oauth_grants', $grants, false);

        $next = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client['client_id']])->get_data();
        $stored = current(get_option('bit_demo_mcp_oauth_grants'));

        self::assertArrayHasKey('access_token', $next);
        self::assertLessThanOrEqual(time() + 60, $stored['refresh_expires'], 'rotation does not extend past created + MAX_LIFETIME');
    }

    public function testConsentDefaultsToReadWhenNoScopeIsRequested(): void
    {
        $client = $this->register();
        $outcome = $this->consent('GET', $this->authorizeParams($client['client_id'], ['scope' => '']));

        self::assertStringContainsString('value="read" checked', $outcome['html']);
        self::assertStringContainsString('<dd>claude.ai</dd>', $outcome['html']);
    }

    public function testRefreshTokenRejectsAMismatchedClient(): void
    {
        $client = $this->register();
        $tokens = $this->connect($client['client_id']);

        $response = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => 'someone-else']);
        self::assertSame('invalid_grant', $response->get_data()['error']);
    }

    public function testRevocationEndsTheConnection(): void
    {
        $client = $this->register();
        $tokens = $this->connect($client['client_id']);

        $request = new \WP_REST_Request('POST');
        $request->bodyParams = ['token' => $tokens['refresh_token']];
        self::assertSame(200, $this->server()->handleRevoke($request)->get_status());
        self::assertNull($this->server()->resolve($tokens['access_token']));

        $again = $this->connect($client['client_id']);
        $id = array_key_first($this->server()->tokens()->connections());
        self::assertTrue(Kit::for('bit-demo')->revokeOAuthConnection($id));
        self::assertNull($this->server()->resolve($again['access_token']));
    }

    public function testBitCrmRowsWithoutAnIdStillWork(): void
    {
        $access = str_repeat('a', 64);
        $refresh = str_repeat('b', 64);
        update_option('bit_demo_mcp_oauth_grants', [hash('sha256', $access) => [
            'refresh_hash'    => hash('sha256', $refresh),
            'client_id'       => 'bitcrm_legacy',
            'scope'           => 'read',
            'user_id'         => self::ADMIN,
            'resource'        => self::RESOURCE,
            'access_expires'  => time() + 600,
            'refresh_expires' => time() + 6000,
            'created'         => time() - 60,
            'last_used'       => null,
        ]]);

        self::assertSame(self::ADMIN, $this->server()->resolve($access)->userId());

        $id = array_key_first($this->server()->tokens()->connections());
        self::assertSame(substr(hash('sha256', $access), 0, 16), $id);

        $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => 'bitcrm_legacy']);
        self::assertSame([$id], array_keys($this->server()->tokens()->connections()), 'the derived id survives rotation');
        self::assertTrue($this->server()->tokens()->revoke($id));
    }

    public function testDeletingAUserRevokesTheirConnections(): void
    {
        $client = $this->register();
        $tokens = $this->connect($client['client_id']);

        Kit::for('bit-demo')->onUserDeleted(self::ADMIN);

        self::assertNull($this->server()->resolve($tokens['access_token']));
    }

    public function testTheTransportEnforcesTheGrantedScope(): void
    {
        $client = $this->register();
        $tokens = $this->connect($client['client_id'], 'read');
        \AiKitTestState::$currentUser = 0;

        $kit = Kit::for('bit-demo');
        $request = new \WP_REST_Request('POST', '/bit-demo/v1/mcp');
        $request->set_header('Authorization', 'Bearer ' . $tokens['access_token']);
        $request->set_body(wp_json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'rename-form', 'arguments' => []]]));

        self::assertTrue($kit->transport()->authorize($request));
        $response = $kit->transport()->handle($request);

        self::assertSame(403, $response->get_status());
        self::assertStringContainsString('insufficient_scope', $response->get_headers()['WWW-Authenticate']);
        self::assertStringContainsString('scope="write"', $response->get_headers()['WWW-Authenticate']);

        $list = new \WP_REST_Request('POST', '/bit-demo/v1/mcp');
        $list->set_header('Authorization', 'Bearer ' . $tokens['access_token']);
        $list->set_body(wp_json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']));
        $names = array_column($kit->transport()->handle($list)->get_data()['result']['tools'], 'name');
        self::assertSame(['list-forms', 'get-entry'], $names, 'a read grant does not even list write tools');
    }

    public function testMissingTokenChallengePointsAtTheMetadata(): void
    {
        $kit = Kit::for('bit-demo');
        $request = new \WP_REST_Request('POST', '/bit-demo/v1/mcp');

        $error = $kit->transport()->authorize($request);
        $response = $kit->transport()->addChallenge(new \WP_REST_Response(['code' => $error->get_error_code()], 401), null, $request);

        self::assertSame('Bearer resource_metadata="https://example.test/.well-known/oauth-protected-resource/wp-json/bit-demo/v1/mcp"', $response->get_headers()['WWW-Authenticate']);
    }

    public function testAdminStateListsConnections(): void
    {
        $client = $this->register();
        $this->connect($client['client_id']);

        $state = Kit::for('bit-demo')->adminState()['oauth'];

        self::assertTrue($state['enabled']);
        self::assertCount(1, $state['connections']);
        self::assertSame('Claude', $state['connections'][0]['client_name']);
        self::assertSame('User 5', $state['connections'][0]['user']);
        self::assertArrayNotHasKey('refresh_hash', $state['connections'][0]);
    }

    private function server(): OAuthServer
    {
        return Kit::for('bit-demo')->oauth();
    }

    /** @return array<string, mixed> */
    private function register(): array
    {
        return $this->server()->handleRegister($this->jsonRequest(['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT]]))->get_data();
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function authorizeParams(string $clientId, array $overrides = []): array
    {
        return array_merge([
            'client_id'             => $clientId,
            'redirect_uri'          => self::REDIRECT,
            'response_type'         => 'code',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope'                 => 'read write offline_access',
            'state'                 => 'xyz',
            'resource'              => self::RESOURCE,
        ], $overrides);
    }

    private function consent(string $method, array $query, array $post = [], int $userId = self::ADMIN): array
    {
        \AiKitTestState::$currentUser = $userId;

        return $this->server()->consentScreen()->respond($method, $query, $post, $userId);
    }

    private function nonce(string $clientId): string
    {
        \AiKitTestState::$currentUser = self::ADMIN;

        return wp_create_nonce('bitdemo_mcp_authorize|' . $clientId);
    }

    private function approve(string $clientId, string $access = 'write', bool $personal = false): string
    {
        $post = $this->authorizeParams($clientId) + ['approve' => '1', 'access' => $access, '_bitapps_oauth_nonce' => $this->nonce($clientId)];
        if ($personal) {
            $post['personal_data'] = '1';
        }

        $outcome = $this->consent('POST', [], $post);
        self::assertSame('redirect', $outcome['type'], $outcome['html'] ?? '');
        parse_str((string) parse_url($outcome['location'], PHP_URL_QUERY), $query);
        self::assertSame('xyz', $query['state']);
        self::assertSame(self::RESOURCE, $query['iss']);

        return $query['code'];
    }

    /** @return array<string, mixed> */
    private function connect(string $clientId, string $access = 'write', bool $personal = false): array
    {
        $code = $this->approve($clientId, $access, $personal);
        $response = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER, 'resource' => self::RESOURCE]);
        self::assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));

        return $response->get_data();
    }

    private function token(array $body)
    {
        $request = new \WP_REST_Request('POST');
        $request->bodyParams = $body;

        return $this->server()->handleToken($request);
    }

    private function jsonRequest(array $body): \WP_REST_Request
    {
        $request = new \WP_REST_Request('POST');
        $request->set_body(wp_json_encode($body));

        return $request;
    }
}
