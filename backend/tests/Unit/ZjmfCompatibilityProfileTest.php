<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfCompatibilityProfile;

/**
 * 魔方财务版本档位：3.7.6 必须保持原行为，3.8.0 起不能再打 /v1。
 */
class ZjmfCompatibilityProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(PluginFileLoader::class)->ensureLoaded(
            app(PluginScanner::class)->requireManifest('upstream', 'zjmf_finance')
        );
    }

    private function supplier(array $providerConfig = []): Supplier
    {
        $supplier = (new Supplier)->forceFill([
            'id' => 907,
            'api_url' => 'https://zjmf.example.test',
        ]);
        $supplier->setAttribute('provider_config', $providerConfig);

        return $supplier;
    }

    public function test_missing_settings_default_to_auto_with_prodetail_first(): void
    {
        $profile = ZjmfCompatibilityProfile::fromSupplier($this->supplier());

        $this->assertSame(ZjmfCompatibilityProfile::VERSION_AUTO, $profile->version());
        $this->assertSame(ZjmfCompatibilityProfile::V1_AUTO, $profile->v1Mode());
        $this->assertSame(
            [ZjmfCompatibilityProfile::PRODUCT_CONFIG_PRODETAIL, ZjmfCompatibilityProfile::PRODUCT_CONFIG_CART],
            $profile->productConfigOrder()
        );
        // auto 不预先封死 /v1：老站必须还能用，新站靠 404 回退
        $this->assertTrue($profile->allowsV1());
    }

    public function test_legacy_profile_prefers_cart_and_v1_fund(): void
    {
        $profile = ZjmfCompatibilityProfile::fromSupplier($this->supplier([
            'version_profile' => 'legacy_376',
            'use_v1_api' => 'always',
        ]));

        $this->assertSame(
            [ZjmfCompatibilityProfile::PRODUCT_CONFIG_CART, ZjmfCompatibilityProfile::PRODUCT_CONFIG_PRODETAIL],
            $profile->productConfigOrder()
        );
        $this->assertSame(
            [ZjmfCompatibilityProfile::INVOICE_PAY_V1_FUND, ZjmfCompatibilityProfile::INVOICE_PAY_APPLY_CREDIT],
            $profile->invoicePayOrder()
        );
    }

    public function test_modern_profile_prefers_prodetail_and_apply_credit(): void
    {
        $profile = ZjmfCompatibilityProfile::fromSupplier($this->supplier([
            'version_profile' => 'modern_380',
        ]));

        $this->assertSame(
            [ZjmfCompatibilityProfile::PRODUCT_CONFIG_PRODETAIL, ZjmfCompatibilityProfile::PRODUCT_CONFIG_CART],
            $profile->productConfigOrder()
        );
        // 3.8.0 没有 /v1/invoices，直接走 apply_credit，不发注定 404 的请求
        $this->assertSame(
            [ZjmfCompatibilityProfile::INVOICE_PAY_APPLY_CREDIT],
            $profile->invoicePayOrder()
        );
    }

    public function test_auto_profile_keeps_legacy_fund_first_for_376_sites(): void
    {
        // auto 必须和老版本行为一致：先 fund，3.8.0 站点靠 404 回退 apply_credit
        $this->assertSame(
            [ZjmfCompatibilityProfile::INVOICE_PAY_V1_FUND, ZjmfCompatibilityProfile::INVOICE_PAY_APPLY_CREDIT],
            ZjmfCompatibilityProfile::fromSupplier($this->supplier())->invoicePayOrder()
        );
    }

    public function test_disabling_v1_drops_v1_from_every_candidate_list(): void
    {
        $profile = ZjmfCompatibilityProfile::fromSupplier($this->supplier([
            'version_profile' => 'legacy_376',
            'use_v1_api' => 'never',
        ]));

        $this->assertFalse($profile->allowsV1());
        $this->assertSame(
            [ZjmfCompatibilityProfile::INVOICE_PAY_APPLY_CREDIT],
            $profile->invoicePayOrder()
        );
    }

    public function test_unknown_values_fall_back_to_auto(): void
    {
        $profile = ZjmfCompatibilityProfile::fromSupplier($this->supplier([
            'version_profile' => '3.7.6',
            'use_v1_api' => 'yes',
        ]));

        $this->assertSame(ZjmfCompatibilityProfile::VERSION_AUTO, $profile->version());
        $this->assertSame(ZjmfCompatibilityProfile::V1_AUTO, $profile->v1Mode());
    }

    public function test_unavailable_message_is_actionable(): void
    {
        $message = ZjmfCompatibilityProfile::fromValues('modern_380', 'never')
            ->v1UnavailableMessage('升级套餐')
            ->getMessage();

        $this->assertStringContainsString('不走 /v1', $message);
        $this->assertStringContainsString('升级套餐', $message);
        $this->assertStringContainsString('手工', $message);
    }
}
