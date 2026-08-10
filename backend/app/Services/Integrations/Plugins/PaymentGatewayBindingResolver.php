<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Constants\PaymentGatewayCode;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentGatewayBindingResolver
{
    /**
     * @return array{plugin_id: int|null, gateway_key: string|null}
     */
    public function contextForGateway(string $gateway): array
    {
        $gatewayKey = $this->normalizeGatewayKey($gateway);

        return [
            'plugin_id' => $this->pluginIdForGatewayKey($gatewayKey),
            'gateway_key' => $gatewayKey !== '' ? $gatewayKey : null,
        ];
    }

    /**
     * @return array{plugin_id: int|null, gateway_key: string|null}
     */
    public function contextForPayment(Payment $payment): array
    {
        $gatewayKey = $this->normalizeGatewayKey($payment->gatewayKey());
        $pluginId = (int) ($payment->plugin_id ?? 0);

        return [
            'plugin_id' => $pluginId > 0 ? $pluginId : $this->pluginIdForGatewayKey($gatewayKey),
            'gateway_key' => $gatewayKey !== '' ? $gatewayKey : null,
        ];
    }

    public function normalizeGatewayKey(string $gateway): string
    {
        return PaymentGatewayCode::normalize($gateway);
    }

    private function pluginIdForGatewayKey(string $gatewayKey): ?int
    {
        if ($gatewayKey === '' || ! PaymentGatewayCode::isThirdParty($gatewayKey)) {
            return null;
        }

        if (! Schema::hasTable('integration_plugins')) {
            return null;
        }

        $plugin = DB::table('integration_plugins')
            ->where('domain', PluginDomain::PAYMENT)
            ->where(static function ($query) use ($gatewayKey): void {
                $query->where('plugin_key', $gatewayKey)
                    ->orWhere('slug', $gatewayKey);
            })
            ->orderByDesc('status')
            ->orderBy('id')
            ->first(['id']);

        return $plugin === null ? null : (int) $plugin->id;
    }
}
