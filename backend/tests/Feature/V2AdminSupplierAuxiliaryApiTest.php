<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\Supplier;
use App\Services\Upstream\Contracts\ProvidesSupplierFormSchema;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\ProviderRegistry;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminSupplierAuxiliaryApiTest extends TestCase
{
    public function test_supplier_summary_requires_permission_rejects_per_page_and_returns_counts(): void
    {
        Supplier::query()->create([
            'name' => 'V2 Summary Active '.bin2hex(random_bytes(3)),
            'code' => 'v2-summary-active-'.bin2hex(random_bytes(3)),
            'status' => 1,
            'sort_order' => 1,
        ]);
        Supplier::query()->create([
            'name' => 'V2 Summary Inactive '.bin2hex(random_bytes(3)),
            'code' => 'v2-summary-inactive-'.bin2hex(random_bytes(3)),
            'status' => 0,
            'sort_order' => 2,
        ]);

        $this->getJson('/api/v2/admin/suppliers/summary')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW]));

        $this->getJson('/api/v2/admin/suppliers/summary')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SUPPLIER_LIST]));

        $this->getJson('/api/v2/admin/suppliers/summary?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->getJson('/api/v2/admin/suppliers/summary')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', Supplier::query()->count())
            ->assertJsonPath('data.active', Supplier::query()->where('status', 1)->count())
            ->assertJsonPath('data.inactive', Supplier::query()->where('status', 0)->count());

        $this->assertSame(['total', 'active', 'inactive'], array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_supplier_provider_types_are_whitelisted_and_strip_secret_metadata(): void
    {
        $this->app->instance(ProviderRegistry::class, new ProviderRegistry([
            new class implements ProvidesSupplierFormSchema, UpstreamDriver
            {
                public function key(): string
                {
                    return 'fake_provider';
                }

                public function label(): string
                {
                    return 'Fake Provider';
                }

                public function capabilities(): array
                {
                    return [];
                }

                public function supports(string $capability): bool
                {
                    return false;
                }

                public function resolve(string $capability): ?object
                {
                    return null;
                }

                public function supplierFormSchema(): array
                {
                    return [
                        'help' => 'Fake provider help',
                        'fields' => [
                            [
                                'key' => 'api_key',
                                'label' => 'API Key',
                                'type' => 'password',
                                'required' => true,
                                'secret' => true,
                                'placeholder' => 'keep empty',
                                'description' => 'Sensitive value is never returned.',
                                'raw_response' => 'must-not-leak',
                            ],
                        ],
                    ];
                }
            },
        ]));

        $this->getJson('/api/v2/admin/suppliers/provider-types')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW]));

        $this->getJson('/api/v2/admin/suppliers/provider-types')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SUPPLIER_LIST]));

        $this->getJson('/api/v2/admin/suppliers/provider-types?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->getJson('/api/v2/admin/suppliers/provider-types')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.value', 'fake_provider')
            ->assertJsonPath('data.list.0.supplier_form.fields.0.key', 'api_key')
            ->assertJsonMissingPath('data.list.0.supplier_form.fields.0.secret')
            ->assertJsonMissingPath('data.list.0.supplier_form.fields.0.raw_response');

        $this->assertSame(['list'], array_keys($response->json('data')));
        $this->assertSame(['value', 'label', 'supplier_form'], array_keys($response->json('data.list.0')));
        $this->assertSame(['fields', 'help'], array_keys($response->json('data.list.0.supplier_form')));
        $this->assertSame([
            'key',
            'label',
            'type',
            'required',
            'placeholder',
            'description',
        ], array_keys($response->json('data.list.0.supplier_form.fields.0')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-supplier-aux-'.$suffix,
            'label' => 'V2 Supplier Aux',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-supplier-aux-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Supplier Aux',
            'email' => 'v2-supplier-aux-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'raw_response', 'third_party_response', 'token'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
