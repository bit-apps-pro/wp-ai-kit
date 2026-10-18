<?php

namespace BitApps\AiKit\Tests;

use BitApps\AiKit\Contract;

/**
 * @internal
 */
final class ContractTest extends TestCase
{
    public function testNamesAreStableTokens(): void
    {
        self::assertSame('bitapps_ai_tool_providers_bit-form', Contract::toolProvidersHook('bit-form'));
        self::assertSame('bitapps-bit-form', Contract::abilityCategory('Bit_Form'));
        self::assertSame('bitapps-bit-crm', Contract::adapterServerId('bit-crm'));
    }

    public function testAbilityNamesSatisfyCoresPattern(): void
    {
        foreach (['list_flows', 'Create Invoice', 'get-form', 'search__entries'] as $tool) {
            $name = Contract::abilityName('bit-pi', $tool);
            self::assertMatchesRegularExpression('/^[a-z0-9-]+\/[a-z0-9-]+$/', $name, $tool);
        }
        self::assertSame('bit-pi/list-flows', Contract::abilityName('bit-pi', 'list_flows'));
    }
}
