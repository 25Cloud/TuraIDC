<?php

namespace App\Models;

use App\Constants\PaymentGatewayCode;
use App\Models\Concerns\EnsuresTraceId;
use App\Models\Concerns\NormalizesTraceId;
use App\Support\VersionedJson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Payment extends Model
{
    use EnsuresTraceId, NormalizesTraceId, SoftDeletes;

    /**
     * 允许创建非第三方网关的 Payment 审计记录（管理端手动入账专用，保留 trade_no 等凭证）。
     * 默认保持“仅第三方真实资金流入”约束；仅显式开启时才放行 manual 网关。
     */
    public bool $allowNonThirdPartyGateway = false;

    protected $fillable = [
        'payment_no', 'user_id',
        /**
         * @deprecated order_id 是冗余字段，推荐通过 payment → invoice → order 链路追溯订单。
         *             仅在创建时由 PaymentService 按 Invoice.order_id 回填，不保证与实际订单一致。
         */
        'order_id',
        'invoice_id', 'gateway',
        'plugin_id', 'gateway_key',
        'trade_no', 'amount', 'currency', 'status', 'callback_raw', 'paid_at',
        'trace_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'callback_raw' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            $gateway = $payment->gatewayKey();

            if (! $payment->allowNonThirdPartyGateway && ! PaymentGatewayCode::isThirdParty($gateway)) {
                throw new InvalidArgumentException('Payment 仅允许记录第三方真实资金流入，余额、手工、免费流程请使用账单和账户流水表达。');
            }

            if ($gateway !== '') {
                if (static::paymentColumnExists('gateway_key') && trim((string) ($payment->gateway_key ?? '')) === '') {
                    $payment->gateway_key = $gateway;
                }
            }
        });

        static::deleting(function (self $payment): void {
            if ($payment->isForceDeleting()) {
                return;
            }

            DB::transaction(function () use ($payment): void {
                $payment->callbacks()->each(fn ($callback) => $callback->delete());
            });
        });
    }

    protected function gateway(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): string => $this->resolveGatewayKeyFromAttributes($attributes),
            set: function (mixed $value): array {
                $gateway = PaymentGatewayCode::normalize((string) $value);

                return ['gateway_key' => $gateway !== '' ? $gateway : null];
            }
        );
    }

    public function gatewayKey(): string
    {
        return $this->resolveGatewayKeyFromAttributes($this->getAttributes());
    }

    public function isThirdPartyGateway(): bool
    {
        return PaymentGatewayCode::isThirdParty($this->gatewayKey());
    }

    public function scopeWhereGatewayKey(Builder $query, string $gateway): Builder
    {
        return $query->where(static::gatewayStorageColumn(), PaymentGatewayCode::normalize($gateway));
    }

    public function scopeWhereGatewayKeyIn(Builder $query, array $gateways): Builder
    {
        $gatewayKeys = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $gateway): string => PaymentGatewayCode::normalize((string) $gateway),
                $gateways
            ),
            static fn (string $gateway): bool => $gateway !== ''
        )));

        return $query->whereIn(static::gatewayStorageColumn(), $gatewayKeys);
    }

    public function getCallbackRawAttribute(mixed $value): array
    {
        $decoded = VersionedJson::decodeToArray($value);
        if (($this->isDirty('callback_raw') || $this->wasChanged('callback_raw')) && $decoded !== null) {
            return VersionedJson::paymentCallback($decoded, 'payment');
        }

        $callbacks = $this->resolveCallbackPayloadFromStructure();
        if ($callbacks !== null) {
            return $callbacks;
        }

        return $decoded === null ? [] : VersionedJson::paymentCallback($decoded, 'payment');
    }

    public function setCallbackRawAttribute(mixed $value): void
    {
        $payload = VersionedJson::decodeToArray($value);
        $this->attributes['callback_raw'] = $payload === null
            ? null
            : json_encode(VersionedJson::paymentCallback($payload, 'payment'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function callbackPayload(string $callbackType = 'payment'): array
    {
        if (! Schema::hasTable('payment_callbacks') || ! $this->exists) {
            return [];
        }

        if (! $this->relationLoaded('callbacks')) {
            $this->loadMissing('callbacks');
        }

        /** @var Collection<int, PaymentCallback> $callbacks */
        $callbacks = $this->getRelation('callbacks');
        $payload = $callbacks->firstWhere('callback_type', $callbackType)?->payload_json;

        return is_array($payload)
            ? VersionedJson::paymentCallback($payload, $callbackType)
            : [];
    }

    public function refundCallbackPayload(): array
    {
        return $this->callbackPayload('refund');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function rechargeRecord(): HasOne
    {
        return $this->hasOne(RechargeRecord::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(IntegrationPlugin::class, 'plugin_id');
    }

    public function callbacks(): HasMany
    {
        return $this->hasMany(PaymentCallback::class, 'payment_id');
    }

    public static function generatePaymentNo(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $no = 'PAY'.now()->format('YmdHisv').Str::upper(Str::random(8));

            if (! static::query()->where('payment_no', $no)->exists()) {
                return $no;
            }
        }

        return 'PAY'.now()->format('YmdHisv').Str::upper(Str::random(12));
    }

    /**
     * Build a payment select list that keeps gatewayKey() hydrated from
     * the normalized payments.gateway_key column.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    public static function gatewayProjectionColumns(array $columns): array
    {
        $result = array_values(array_filter(
            $columns,
            static fn (string $column): bool => $column !== 'gateway' && $column !== 'gateway_key'
        ));

        if (static::paymentColumnExists('gateway_key')) {
            $result[] = 'gateway_key';
        }

        return array_values(array_unique($result));
    }

    private static function gatewayStorageColumn(): string
    {
        return 'gateway_key';
    }

    private static function paymentColumnExists(string $column): bool
    {
        return Schema::hasTable('payments') && Schema::hasColumn('payments', $column);
    }

    private function resolveGatewayKeyFromAttributes(array $attributes): string
    {
        $gatewayKey = trim((string) ($attributes['gateway_key'] ?? ''));

        return PaymentGatewayCode::normalize($gatewayKey);
    }

    private function resolveCallbackPayloadFromStructure(): ?array
    {
        if (! Schema::hasTable('payment_callbacks') || ! $this->exists) {
            return null;
        }

        if (! $this->relationLoaded('callbacks')) {
            $this->loadMissing('callbacks');
        }

        /** @var Collection<int, PaymentCallback> $callbacks */
        $callbacks = $this->getRelation('callbacks');
        if ($callbacks->isEmpty()) {
            return null;
        }

        $paymentPayload = VersionedJson::paymentCallback(
            $callbacks->firstWhere('callback_type', 'payment')?->payload_json ?? [],
            'payment'
        );
        $refundPayload = VersionedJson::paymentCallback(
            $callbacks->firstWhere('callback_type', 'refund')?->payload_json ?? [],
            'refund'
        );

        if ($callbacks->firstWhere('callback_type', 'payment') === null && $callbacks->firstWhere('callback_type', 'refund') === null) {
            return null;
        }

        if ($refundPayload !== []) {
            $paymentPayload['refund'] = $refundPayload;
        }

        return $paymentPayload;
    }
}
