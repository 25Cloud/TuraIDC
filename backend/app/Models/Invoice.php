<?php

namespace App\Models;

use App\Models\Concerns\EnsuresTraceId;
use App\Models\Concerns\HandlesProductSnapshot;
use App\Models\Concerns\NormalizesTraceId;
use App\Support\OrderInvoiceNoGenerator;
use App\Support\VersionedJson;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Invoice extends Model
{
    use EnsuresTraceId, HandlesProductSnapshot, NormalizesTraceId, SoftDeletes;

    protected $fillable = [
        'invoice_no', 'user_id', 'order_id', 'origin_invoice_id', 'type', 'currency',
        'product_id', 'product_spec_snapshot', 'product_type_snapshot',
        'service_id',
        'coupon_id', 'user_coupon_id', 'coupon_code',
        'amount', 'discount', 'paid_amount',
        'billing_cycle', 'quantity',
        'product_snapshot_json', 'config_snapshot', 'config_pricing_snapshot', 'coupon_snapshot',
        'status', 'due_date', 'paid_at',
        'refunded_at', 'refund_amount', 'refund_method', 'refund_trace_id',
        'remark', 'operator', 'trace_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'refund_amount' => 'decimal:2',
            'config_snapshot' => 'array',
            'config_pricing_snapshot' => 'array',
            'coupon_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $invoice): void {
            if ($invoice->isForceDeleting()) {
                return;
            }

            DB::transaction(function () use ($invoice): void {
                $invoice->items()->each(fn ($item) => $item->delete());
            });
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 关联的内部开通投影订单（Order）。
     *
     * Invoice 是财务主体；Order 是开通侧的内部投影，
     * 充值/扣款/返利类账单（type=recharge/deduction/referral_credit）不产生 Order，
     * 此关联对这类账单返回 null，查询前请先判断 invoice->order_id 是否非空。
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function originInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origin_invoice_id');
    }

    public function refundInvoices(): HasMany
    {
        return $this->hasMany(self::class, 'origin_invoice_id');
    }

    public function rechargeRecords(): HasMany
    {
        return $this->hasMany(RechargeRecord::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }

    public function getDisplayProductNameAttribute(): string
    {
        $snapshotPayload = $this->product_snapshot_json;
        $snapshotDisplayName = trim((string) ($snapshotPayload['product_name'] ?? $snapshotPayload['product_spec_snapshot'] ?? ''));
        if ($snapshotDisplayName !== '') {
            return $snapshotDisplayName;
        }

        $snapshot = (string) ($this->product_spec_snapshot ?? '');
        if ($snapshot !== '') {
            return $snapshot;
        }

        return '';
    }

    public function getProductSnapshotJsonAttribute(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        $configSnapshot = $this->config_snapshot;

        return is_array($configSnapshot) && $configSnapshot !== [] ? $configSnapshot : null;
    }

    public function getConfigSnapshotAttribute(mixed $value): ?array
    {
        return VersionedJson::tradeSnapshot($value, 'invoice.config_snapshot');
    }

    public function getConfigPricingSnapshotAttribute(mixed $value): ?array
    {
        return VersionedJson::tradeSnapshot($value, 'invoice.config_pricing_snapshot');
    }

    public function getCouponSnapshotAttribute(mixed $value): ?array
    {
        return VersionedJson::tradeSnapshot($value, 'invoice.coupon_snapshot');
    }

    /**
     * `product_snapshot_json` 是 `config_snapshot` 列的虚拟属性别名。
     *
     * 历史原因：旧版本以 config_snapshot 列同时承担"商品快照"与"账单配置"两种语义，
     * product_snapshot_json 提供了更具可读性的访问入口，底层写入仍指向 config_snapshot。
     * 读取时，getProductSnapshotJsonAttribute 优先解析字段自身值，
     * 若为空则回退读取 config_snapshot，保持向后兼容。
     *
     * @see getProductSnapshotJsonAttribute
     * @see getConfigSnapshotAttribute
     */
    public function setProductSnapshotJsonAttribute(mixed $value): void
    {
        $payload = is_array($value) ? $value : [];

        $this->attributes['config_snapshot'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function setConfigSnapshotAttribute(mixed $value): void
    {
        $this->attributes['config_snapshot'] = $this->encodeJson(
            VersionedJson::tradeSnapshot($value, 'invoice.config_snapshot')
        );
    }

    public function setConfigPricingSnapshotAttribute(mixed $value): void
    {
        $this->attributes['config_pricing_snapshot'] = $this->encodeJson(
            VersionedJson::tradeSnapshot($value, 'invoice.config_pricing_snapshot')
        );
    }

    public function setCouponSnapshotAttribute(mixed $value): void
    {
        $this->attributes['coupon_snapshot'] = $this->encodeJson(
            VersionedJson::tradeSnapshot($value, 'invoice.coupon_snapshot')
        );
    }

    public static function generateInvoiceNo(?CarbonInterface $time = null, ?string $suffix = null): string
    {
        if ($suffix !== null) {
            return OrderInvoiceNoGenerator::buildInvoiceNo($time, $suffix);
        }

        return OrderInvoiceNoGenerator::generatePair($time)['invoice_no'];
    }

    public static function generateInvoiceNoFromOrderNo(string $orderNo): string
    {
        return OrderInvoiceNoGenerator::deriveInvoiceNoFromOrderNo($orderNo) ?? self::generateInvoiceNo();
    }

    public function scopeUnpaid($query)
    {
        return $query->where('status', 0);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 3);
    }

    public function syncInvoiceItemProjection(): void
    {
        if (! $this->exists || ! Schema::hasTable('invoice_items')) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('invoice_items')->where('invoice_id', (int) $this->id)->delete();

            $itemName = $this->display_product_name;
            if ($itemName === '') {
                $this->loadMissing('order.product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires');
                $itemName = trim((string) ($this->order?->display_product_name ?? ''));
            }
            $quantity = max((int) ($this->quantity ?? $this->order?->quantity ?? 1), 1);
            $grossAmount = (float) ($this->amount ?? 0) + (float) ($this->discount ?? 0);
            $discountAmount = (float) ($this->discount ?? $this->order?->discount ?? 0);
            $unitPrice = $quantity > 0 ? $grossAmount / $quantity : $grossAmount;

            DB::table('invoice_items')->insert([
                'invoice_id' => (int) $this->id,
                'item_name' => $itemName !== '' ? $itemName : '账单项目',
                'item_type' => trim((string) ($this->type ?? 'normal')),
                'quantity' => $quantity,
                'unit_price' => $this->normalizeDecimal($unitPrice),
                'discount_amount' => $this->normalizeDecimal($discountAmount),
                'line_amount' => $this->normalizeDecimal($this->amount ?? 0),
                'meta_json' => $this->encodeJson([
                    'invoice_no' => $this->invoice_no,
                    'order_no' => $this->order?->order_no,
                    'product_name' => $itemName !== '' ? $itemName : null,
                    'quantity' => $quantity,
                ]),
                'created_at' => $this->created_at ?? now(),
                'updated_at' => $this->updated_at ?? now(),
            ]);
        });
    }

    private function normalizeDecimal(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function encodeJson(?array $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
