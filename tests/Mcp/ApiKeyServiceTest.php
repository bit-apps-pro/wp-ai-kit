<?php

namespace BitApps\AiKit\Tests\Mcp;

use BitApps\AiKit\Kit;
use BitApps\AiKit\Mcp\Auth\ApiKeyService;
use BitApps\AiKit\Tests\TestCase;

/**
 * @internal
 */
final class ApiKeyServiceTest extends TestCase
{
    public function testKeysAreShownOnceStoredHashedAndScoped(): void
    {
        $service = new ApiKeyService($this->context());
        $created = $service->create(3, 'Claude Code', ['read'], 30, false);

        self::assertStringStartsWith('bitdemo_key_', $created['key']);
        self::assertArrayNotHasKey('hash', $created['record']);
        self::assertStringNotContainsString($created['key'], serialize(get_option('bit_demo_mcp_api_keys')));

        $grant = $service->resolve($created['key']);
        self::assertSame(3, $grant->userId());
        self::assertSame(['read'], $grant->scopes());
        self::assertSame('api_key', $grant->kind());
        self::assertNull($service->resolve($created['key'] . 'x'));
        self::assertNull($service->resolve('other_prefix_123'));
    }

    public function testRevokedExpiredAndDeletedUsersKeysStopWorking(): void
    {
        $service = new ApiKeyService($this->context());
        $a = $service->create(3, 'a');
        $b = $service->create(4, 'b', ['read'], 1);
        $c = $service->create(3, 'c');

        $service->revoke($a['record']['id']);
        self::assertNull($service->resolve($a['key']));

        $records = get_option('bit_demo_mcp_api_keys');
        $records[$b['record']['id']]['expires_at'] = time() - 1;
        update_option('bit_demo_mcp_api_keys', $records);
        self::assertNull($service->resolve($b['key']));
        self::assertSame('expired', $service->list(4)[0]['status']);

        Kit::boot($this->context());
        do_action('deleted_user', 3);
        self::assertNull($service->resolve($c['key']));
    }

    public function testKeysOnlyResolveWhenTheFeatureIsOn(): void
    {
        update_option('bit_demo_mcp_settings', ['enabled' => true]);
        $kit = Kit::boot($this->context());
        self::assertSame([], $kit->bearerResolvers());

        update_option('bit_demo_mcp_settings', ['enabled' => true, 'api_keys' => true]);
        self::assertCount(1, $kit->bearerResolvers());
    }

    public function testKeysFromTheSettingsScreenAreCappedAndValidated(): void
    {
        $this->grant(5, 'manage_options');
        update_option('bit_demo_mcp_settings', ['enabled' => true, 'access_level' => 'write', 'api_keys' => true]);
        $kit = \BitApps\AiKit\Kit::boot($this->context());

        $created = $kit->createApiKey(5, ' <b>CI deploy</b> ', 'destructive', 90, false);
        self::assertStringStartsWith('bitdemo_key_', $created['key']);
        self::assertSame(['write'], $created['record']['scopes'], 'never above the site level');
        self::assertSame('CI deploy', $created['record']['name']);

        self::assertSame('invalid_name', $kit->createApiKey(5, '  ', 'read', 30, false)->get_error_code());
        self::assertSame('invalid_level', $kit->createApiKey(5, 'x', 'admin', 30, false)->get_error_code());
        self::assertSame('invalid_expiry', $kit->createApiKey(5, 'x', 'read', 3650, false)->get_error_code());
        self::assertSame('forbidden', $kit->createApiKey(999, 'x', 'read', 30, false)->get_error_code());

        self::assertCount(1, $kit->adminState()['api_keys']['keys']);
        self::assertArrayNotHasKey('hash', $kit->adminState()['api_keys']['keys'][0]);
    }

    public function testRevokingCanBeLimitedToTheOwner(): void
    {
        $service = new \BitApps\AiKit\Mcp\Auth\ApiKeyService($this->context());
        $id = $service->create(5, 'Mine')['record']['id'];

        self::assertFalse($service->revoke($id, 6), 'another user cannot revoke it');
        self::assertTrue($service->revoke($id, 5));
    }

    public function testUsingAKeyNeverRewritesTheKeyList(): void
    {
        $service = new \BitApps\AiKit\Mcp\Auth\ApiKeyService($this->context());
        $created = $service->create(5, 'CI');
        $before = get_option('bit_demo_mcp_api_keys');

        self::assertNotNull($service->resolve($created['key']));
        self::assertSame($before, get_option('bit_demo_mcp_api_keys'), 'a racing write cannot restore a revoked key');
        self::assertNotNull($service->list()[0]['last_used_at']);
    }
}
