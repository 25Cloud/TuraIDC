<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfAuthManager;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfCapabilityMemory;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfFinanceTransport;

/**
 * 3.8.0 起没有 /v1/*：账单支付要能自动落到 /apply_credit，
 * 升级类操作要给出能照着做的提示，而不是甩一个「异常页面 404」。
 */
class ZjmfInvoicePayFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(PluginFileLoader::class)->ensureLoaded(
            app(PluginScanner::class)->requireManifest('upstream', 'zjmf_finance')
        );

        config(['idc.hosting_panel_api.jwt_cache_store' => 'array']);
        Cache::flush();
    }

    private function supplier(array $providerConfig = []): Supplier
    {
        $supplier = (new Supplier)->forceFill([
            'id' => 962,
            'interface_type' => 'zjmf_finance_api',
            'api_url' => 'https://zjmf.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
        ]);
        $supplier->setAttribute('provider_config', $providerConfig);

        return $supplier;
    }

    /**
     * @param  array<string, array<string, mixed>|\Closure>  $canned
     */
    private function transport(array $canned, array &$calls): ZjmfFinanceTransport
    {
        $inner = new class($canned, $calls) extends HostingPanelApiTransport
        {
            /** @var array<string, array<string, mixed>|\Closure> */
            private array $canned;

            /** @var array<int, array<string, mixed>> */
            private array $calls;

            public function __construct(array $canned, array &$calls)
            {
                $this->canned = $canned;
                $this->calls = &$calls;
            }

            public function request(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                $this->calls[] = compact('method', 'uri', 'payload', 'query', 'headers');

                if ($method === 'POST' && $uri === '/zjmf_api_login') {
                    return ['status' => 200, 'jwt' => 'zjmf-test-jwt'];
                }

                $handler = $this->canned[$method.' '.$uri] ?? null;
                if ($handler === null) {
                    throw new BusinessException('供应商接口返回异常页面，未解析到有效数据（HTTP 404）');
                }

                return is_callable($handler) ? $handler($method, $uri, $payload, $query) : $handler;
            }

            public function requestWithMeta(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                return [
                    'response' => $this->request($supplier, $method, $uri, $payload, $jwt, $headers, $query),
                    'headers' => [],
                    'http_code' => 200,
                    'content_type' => 'application/json',
                ];
            }
        };

        return new ZjmfFinanceTransport($inner, new ZjmfAuthManager($inner), null, new ZjmfCapabilityMemory);
    }

    public function test_legacy_profile_pays_through_v1_fund(): void
    {
        $calls = [];
        $transport = $this->transport([
            'POST /v1/invoices/88/fund' => ['status' => 1001, 'msg' => '支付成功'],
        ], $calls);

        $response = $transport->payInvoiceWithCredit($this->supplier(['version_profile' => 'legacy_376']), 88);

        $this->assertSame(1001, $response['status']);
        $this->assertSame(1, collect($calls)->where('uri', '/v1/invoices/88/fund')->count());
        $this->assertNull(collect($calls)->firstWhere('uri', '/apply_credit'));
    }

    public function test_modern_profile_pays_through_apply_credit(): void
    {
        $calls = [];
        $transport = $this->transport([
            'POST /apply_credit' => ['status' => 200, 'msg' => '请求成功'],
        ], $calls);

        $response = $transport->payInvoiceWithCredit($this->supplier(['version_profile' => 'modern_380']), 88);

        $this->assertSame(200, $response['status']);
        $creditCall = collect($calls)->firstWhere('uri', '/apply_credit');
        $this->assertSame(88, $creditCall['payload']['invoiceid']);
        $this->assertSame(1, $creditCall['payload']['use_credit']);
        $this->assertNull(collect($calls)->firstWhere('uri', '/v1/invoices/88/fund'));
    }

    public function test_v1_disabled_never_calls_v1_at_all(): void
    {
        $calls = [];
        $transport = $this->transport([
            'POST /apply_credit' => ['status' => 200, 'msg' => '请求成功'],
        ], $calls);

        $transport->payInvoiceWithCredit($this->supplier([
            'version_profile' => 'legacy_376',
            'use_v1_api' => 'never',
        ]), 88);

        $this->assertSame(0, collect($calls)->filter(fn ($call) => str_starts_with($call['uri'], '/v1/'))->count());
    }

    public function test_already_paid_invoice_is_normalized_to_success(): void
    {
        $calls = [];
        $transport = $this->transport([
            'POST /apply_credit' => ['status' => 406, 'msg' => '账单已处理或不存在'],
        ], $calls);

        $response = $transport->payInvoiceWithCredit($this->supplier(['version_profile' => 'modern_380']), 88);

        $this->assertTrue($response['already_paid']);
        $this->assertSame(200, $response['status']);
    }

    public function test_apply_credit_failure_surfaces_upstream_message(): void
    {
        $calls = [];
        $transport = $this->transport([
            'POST /apply_credit' => ['status' => 400, 'msg' => '余额不足'],
            'POST /v1/invoices/88/fund' => ['status' => 400, 'msg' => '余额不足'],
        ], $calls);

        try {
            $transport->payInvoiceWithCredit($this->supplier(), 88);
            $this->fail('expected BusinessException');
        } catch (BusinessException $exception) {
            $this->assertStringContainsString('余额不足', $exception->getMessage());
        }
    }

    public function test_fetch_invoice_skips_v1_when_disabled(): void
    {
        $calls = [];
        $transport = $this->transport([], $calls);

        $this->assertNull($transport->fetchInvoice($this->supplier(['use_v1_api' => 'never']), 88));
        $this->assertSame([], collect($calls)->filter(fn ($call) => str_starts_with($call['uri'], '/v1/'))->all());
    }

    public function test_upgrade_on_v1_less_supplier_throws_actionable_error(): void
    {
        $calls = [];
        $transport = $this->transport([], $calls);

        try {
            $transport->post($this->supplier(['use_v1_api' => 'never']), '/v1/hosts/12/actions/upgrade', []);
            $this->fail('expected BusinessException');
        } catch (BusinessException $exception) {
            $this->assertStringContainsString('升级套餐', $exception->getMessage());
            $this->assertStringContainsString('不走 /v1', $exception->getMessage());
        }
    }

    public function test_v1_404_is_remembered_and_skipped_next_time(): void
    {
        $calls = [];
        $transport = $this->transport([], $calls);
        $supplier = $this->supplier();

        try {
            $transport->get($supplier, '/v1/user');
            $this->fail('expected BusinessException');
        } catch (BusinessException $exception) {
            $this->assertStringContainsString('会员资料', $exception->getMessage());
        }

        $this->assertSame(1, collect($calls)->where('uri', '/v1/user')->count());

        try {
            $transport->get($supplier, '/v1/user');
            $this->fail('expected BusinessException');
        } catch (BusinessException) {
            // 第二次直接按「无 /v1」拦下，不再打上游
        }

        $this->assertSame(1, collect($calls)->where('uri', '/v1/user')->count());
    }
}
