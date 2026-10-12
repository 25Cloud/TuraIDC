<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Exceptions\UpstreamStockShortageException;
use App\Models\Supplier;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use Mockery;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfAuthManager;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfConsoleService;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfFinanceTransport;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfNetworkService;

/**
 * 上游「库存不足」拒绝的分类回归。
 *
 * 上游的加购/结算是唯一的真实库存闸门，返回的 qty 只是展示口径。
 * 被这类文案拒绝时必须抛出分类异常，编排层才能校准本地库存快照；
 * 否则用户会继续按虚高的陈旧快照付款，付完才发现开不出来。
 */
class UpstreamStockShortageClassificationTest extends TestCase
{
    public function test_matches_covers_the_documented_upstream_rejection_messages(): void
    {
        foreach ([
            '产品 VPS-1 库存不足',
            '该商品已售罄',
            '商品售完下架',
            '商品缺货',
            '商品不存在',
            '产品不存在',
            '超出库存',
            'Out of stock',
            'OUT OF STOCK',
        ] as $message) {
            $this->assertTrue(
                UpstreamStockShortageException::matches($message),
                '「'.$message.'」应被判为上游库存不足'
            );
        }
    }

    public function test_matches_ignores_unrelated_failures_and_empty_messages(): void
    {
        foreach ([
            '',
            '用户余额不足',
            '登录已失效，请重新登录',
            '订单已开通,请勿重新开通',
            '系统繁忙，请稍后重试',
        ] as $message) {
            $this->assertFalse(
                UpstreamStockShortageException::matches($message),
                '「'.$message.'」不应被判为上游库存不足'
            );
        }
    }

    public function test_upgrade_rejected_for_stock_shortage_throws_the_classified_exception(): void
    {
        $supplier = $this->makeSupplier();
        $hostingTransport = Mockery::mock(HostingPanelApiTransport::class);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->andReturn(['status' => 200, 'data' => []]);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->andReturn(['status' => 200, 'data' => ['invoiceid' => 990]]);
        // 上游换品时目标商品库存为 0，加购/结算被拒
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->andReturn(['status' => 400, 'msg' => '产品88库存不足']);

        $transport = $this->makeTransport($hostingTransport);
        $networkService = new ZjmfNetworkService($transport, new ZjmfConsoleService($transport));

        try {
            $networkService->purchaseHostUpgrade($supplier, 77, 88, 'monthly', '', 'jwt-token');
            $this->fail('上游以库存不足拒绝时必须抛出分类异常');
        } catch (UpstreamStockShortageException $exception) {
            // 分类异常继承 BusinessException，既有重试/退款链路语义不变
            $this->assertInstanceOf(BusinessException::class, $exception);
            $this->assertNotSame('', trim($exception->getMessage()));
        }
    }

    public function test_upgrade_rejected_for_other_reasons_keeps_the_plain_business_exception(): void
    {
        $supplier = $this->makeSupplier();
        $hostingTransport = Mockery::mock(HostingPanelApiTransport::class);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->andReturn(['status' => 200, 'data' => []]);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->andReturn(['status' => 200, 'data' => ['invoiceid' => 990]]);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->andReturn(['status' => 401, 'msg' => '登录已失效，请重新登录']);

        $transport = $this->makeTransport($hostingTransport);
        $networkService = new ZjmfNetworkService($transport, new ZjmfConsoleService($transport));

        $this->expectException(BusinessException::class);

        $networkService->purchaseHostUpgrade($supplier, 77, 88, 'monthly', '', 'jwt-token');
    }

    private function makeTransport(HostingPanelApiTransport $hostingTransport): ZjmfFinanceTransport
    {
        return new ZjmfFinanceTransport($hostingTransport, new ZjmfAuthManager($hostingTransport));
    }

    private function makeSupplier(): Supplier
    {
        $supplier = new Supplier;
        $supplier->forceFill([
            'id' => 10,
            'api_url' => 'https://upstream.example/api',
            'interface_type' => 'zjmf_finance_api',
        ]);

        return $supplier;
    }
}
