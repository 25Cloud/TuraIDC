<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Constants\InvoiceStatus;
use App\Constants\InvoiceType;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Support\AdminPrivacy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class DashboardService
{
    private const OVERVIEW_RECENT_INVOICES_CACHE_KEY = 'dashboard:overview:recent_invoices';

    private const OVERVIEW_RECENT_INVOICES_CACHE_TTL_SECONDS = 30; // 30秒：发票列表

    private const OVERVIEW_STATS_CACHE_KEY = 'dashboard:overview:stats';

    private const OVERVIEW_STATS_CACHE_TTL_SECONDS = 60;

    public function overview(): array
    {
        return array_merge(
            $this->stats(),
            [
                'recent_invoices' => $this->recentInvoices(),
            ]
        );
    }

    public function stats(): array
    {
        return Cache::remember(
            self::OVERVIEW_STATS_CACHE_KEY,
            now()->addSeconds(self::OVERVIEW_STATS_CACHE_TTL_SECONDS),
            fn () => $this->buildOverviewStats()
        );
    }

    public function recentInvoices(): array
    {
        $privacy = AdminPrivacy::current();

        return Cache::remember(
            self::OVERVIEW_RECENT_INVOICES_CACHE_KEY.':raw:'.($privacy->allowsRaw() ? '1' : '0'),
            now()->addSeconds(self::OVERVIEW_RECENT_INVOICES_CACHE_TTL_SECONDS),
            fn () => $this->buildRecentInvoices()
        );
    }

    private function buildOverviewStats(): array
    {
        $today = now()->startOfDay();
        $month = now()->startOfMonth();

        $userStats = User::query()
            ->selectRaw('COUNT(*) as total_users')
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) as today_new_users', [$today])
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) as month_new_users', [$month])
            ->first();

        $invoiceCountStats = Invoice::query()
            ->selectRaw('COUNT(*) as total_invoices')
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) as today_new_invoices', [$today])
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) as month_new_invoices', [$month])
            ->first();

        $serviceStats = Service::query()
            ->selectRaw('COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) as active_services')
            ->first();

        $ticketStats = Ticket::query()
            ->selectRaw('COALESCE(SUM(CASE WHEN status IN (0, 1, 2) THEN 1 ELSE 0 END), 0) as open_tickets')
            ->first();

        $invoiceIncomeStats = $this->salesIncomeInvoices()
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? AND paid_at >= ? THEN paid_amount ELSE 0 END), 0) as today_income', [InvoiceStatus::PAID, $today])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? AND paid_at >= ? THEN paid_amount ELSE 0 END), 0) as month_income', [InvoiceStatus::PAID, $month])
            ->first();

        return [
            'counts' => [
                'total_users' => (int) ($userStats?->total_users ?? 0),
                'total_invoices' => (int) ($invoiceCountStats?->total_invoices ?? 0),
                'active_services' => (int) ($serviceStats?->active_services ?? 0),
                'open_tickets' => (int) ($ticketStats?->open_tickets ?? 0),
            ],
            'today' => [
                'new_users' => (int) ($userStats?->today_new_users ?? 0),
                'new_invoices' => (int) ($invoiceCountStats?->today_new_invoices ?? 0),
                'income' => (float) ($invoiceIncomeStats?->today_income ?? 0),
            ],
            'month' => [
                'income' => (float) ($invoiceIncomeStats?->month_income ?? 0),
                'new_users' => (int) ($userStats?->month_new_users ?? 0),
                'new_invoices' => (int) ($invoiceCountStats?->month_new_invoices ?? 0),
            ],
        ];
    }

    private function buildRecentInvoices(): array
    {
        $privacy = AdminPrivacy::current();

        return Invoice::query()
            ->with('user:id,email,nickname')
            ->select([
                'id',
                'invoice_no',
                'user_id',
                'product_id',
                'service_id',
                'type',
                'amount',
                'discount',
                'paid_amount',
                'billing_cycle',
                'status',
                'paid_at',
                'created_at',
            ])
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(function (Invoice $invoice) use ($privacy) {
                return [
                    'id' => (int) $invoice->id,
                    'invoice_no' => (string) $invoice->invoice_no,
                    'amount' => number_format((float) $invoice->amount, 2, '.', ''),
                    'status' => (int) $invoice->status,
                    'status_label' => InvoiceStatus::$labels[$invoice->status] ?? (string) $invoice->status,
                    'type' => (string) ($invoice->type ?? ''),
                    'created_at' => $invoice->created_at?->format('Y-m-d H:i:s'),
                    'user' => [
                        'nickname' => (string) ($invoice->user?->nickname ?? ''),
                        'email' => $privacy->email($invoice->user?->email ?? ''),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    public function monthlyRevenue(): array
    {
        return Cache::remember(
            'dashboard:overview:monthly_revenue',
            now()->addSeconds(self::OVERVIEW_STATS_CACHE_TTL_SECONDS),
            fn () => $this->buildMonthlyRevenue()
        );
    }

    private function buildMonthlyRevenue(): array
    {
        $month = now()->startOfMonth();
        $today = now()->startOfDay();
        $daysInMonth = (int) now()->format('t');

        // 本月各产品营收占比（已付账单，Top 8 + 其他）
        $allProducts = $this->salesIncomeInvoices()
            ->where('created_at', '>=', $month)
            ->where('status', InvoiceStatus::PAID)
            ->selectRaw('
                COALESCE(NULLIF(product_spec_snapshot, ""), "未知产品") as product_name,
                COALESCE(SUM(paid_amount), 0) as total_amount,
                COUNT(*) as count
            ')
            ->groupBy('product_spec_snapshot')
            ->orderByDesc('total_amount')
            ->get();

        $topProducts = $allProducts->take(8);
        $otherAmount = $allProducts->skip(8)->sum('total_amount');

        $revenueByProduct = $topProducts
            ->map(function ($row) {
                return [
                    'label' => (string) ($row->product_name ?: '未知产品'),
                    'amount' => (float) $row->total_amount,
                    'count' => (int) $row->count,
                ];
            })
            ->values()
            ->all();

        if ($otherAmount > 0) {
            $revenueByProduct[] = [
                'label' => '其他',
                'amount' => (float) $otherAmount,
                'count' => (int) $allProducts->skip(8)->sum('count'),
            ];
        }

        // 本月每日已付金额趋势
        $dailyPaid = $this->salesIncomeInvoices()
            ->where('status', InvoiceStatus::PAID)
            ->where('paid_at', '>=', $month)
            ->selectRaw('DATE(paid_at) as date, COALESCE(SUM(paid_amount), 0) as daily_amount, COUNT(*) as daily_count')
            ->groupByRaw('DATE(paid_at)')
            ->get()
            ->keyBy('date');

        $dailyRevenue = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dateStr = $month->copy()->addDays($d - 1)->format('Y-m-d');
            $dailyRevenue[] = [
                'date' => $dateStr,
                'day' => $d,
                'amount' => (float) ($dailyPaid[$dateStr]->daily_amount ?? 0),
                'count' => (int) ($dailyPaid[$dateStr]->daily_count ?? 0),
            ];
        }

        return [
            'revenue_by_product' => $revenueByProduct,
            'daily_revenue' => $dailyRevenue,
            'month_label' => now()->format('Y年n月'),
        ];
    }

    /**
     * 商品销售收入不包含充值、后台扣款和推荐奖励等资金流水；退款红字账单保留，用于冲抵销售收入。
     *
     * @return Builder<Invoice>
     */
    private function salesIncomeInvoices(): Builder
    {
        return Invoice::query()->where(function (Builder $query): void {
            $query->whereNull('type')->orWhereNotIn('type', [
                InvoiceType::RECHARGE,
                InvoiceType::DEDUCTION,
                InvoiceType::REFERRAL_CREDIT,
            ]);
        });
    }
}
