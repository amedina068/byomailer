<?php

namespace Tests\Feature;

use App\Exceptions\MissingTenantException;
use App\Models\ContactAttribute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactAttributeDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_with_default_persists_default_value(): void
    {
        $attribute = ContactAttribute::factory()->withDefault('Acme Corp')->create();

        $this->assertSame('Acme Corp', $attribute->fresh()->default_value);
    }

    public function test_default_value_is_nullable(): void
    {
        $attribute = ContactAttribute::factory()->create();

        $this->assertNull($attribute->fresh()->default_value);
    }

    public function test_defaults_for_names_without_client_id_throws_with_no_tenant(): void
    {
        ContactAttribute::factory()->withDefault('Acme')->create(['name' => 'company']);

        $this->expectException(MissingTenantException::class);

        ContactAttribute::defaultsForNames(['company']);
    }

    public function test_defaults_for_names_with_explicit_client_id_works_without_auth(): void
    {
        $attribute = ContactAttribute::factory()->withDefault('Acme')->create(['name' => 'company']);

        $this->assertSame(
            ['company' => 'Acme'],
            ContactAttribute::defaultsForNames(['company'], $attribute->client_id),
        );
    }

    public function test_zero_string_global_default_survives_the_pluck_chain(): void
    {
        $attribute = ContactAttribute::factory()->withDefault('0')->create(['name' => 'coupon']);

        $this->assertSame(
            ['coupon' => '0'],
            ContactAttribute::defaultsForNames(['coupon'], $attribute->client_id),
        );
    }

    public function test_merge_prefill_defaults_keeps_stored_zero_string(): void
    {
        $merged = ContactAttribute::mergePrefillDefaults(
            ['coupon'],
            ['coupon' => '0'],
            ['coupon' => 'FULL10'],
        );

        $this->assertSame(['coupon' => '0'], $merged);
    }

    public function test_merge_prefill_defaults_fills_zero_string_global(): void
    {
        $merged = ContactAttribute::mergePrefillDefaults(
            ['coupon'],
            ['coupon' => ''],
            ['coupon' => '0'],
        );

        $this->assertSame(['coupon' => '0'], $merged);
    }
}
