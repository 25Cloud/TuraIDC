<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Constants\PaymentGatewayCode;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class PluginDataBackfillService
{
    public const DEFAULT_BATCH_ID = 'plugin-bindings-v1';

    private const NON_THIRD_PARTY_GATEWAYS = [
        PaymentGatewayCode::BALANCE,
        PaymentGatewayCode::MANUAL,
        PaymentGatewayCode::FREE,
    ];

    /**
     * @return array<string, mixed>
     */
    public function inspect(?string $batchId = null, int $chunkSize = 500): array
    {
        return $this->run(false, $this->normalizeBatchId($batchId), $chunkSize);
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(?string $batchId = null, int $chunkSize = 500): array
    {
        $resolvedBatchId = $this->normalizeBatchId($batchId);
        $inspection = $this->inspect($resolvedBatchId, $chunkSize);

        if ($this->hasBlockingUnknowns($inspection)) {
            throw new RuntimeException('Backfill has unresolved provider/gateway/driver keys; run dry-run with --json for details.');
        }

        return DB::transaction(fn (): array => $this->run(true, $resolvedBatchId, $chunkSize), 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function run(bool $execute, string $batchId, int $chunkSize): array
    {
        $chunkSize = max(1, $chunkSize);
        $plugins = $this->loadPlugins();
        $report = $this->baseReport($execute ? 'execute' : 'dry_run', $batchId, $chunkSize);

        $this->backfillGlobalBindings($plugins, $report, $execute, $batchId);
        $supplierBindings = $this->backfillSupplierBindings($plugins, $report, $execute, $batchId);
        $productBindings = $this->backfillProductBindings($plugins, $supplierBindings, $report, $execute, $batchId);
        $serviceBindings = $this->backfillServiceBindings($plugins, $supplierBindings, $productBindings, $report, $execute, $batchId);
        $this->backfillServiceSnapshotsAndAttempts($serviceBindings, $report, $execute, $batchId);
        $paymentContext = $this->backfillPayments($plugins, $report, $execute);
        $this->backfillPaymentCallbacks($paymentContext, $report, $execute);
        $this->backfillGatewayLogs($plugins, $paymentContext, $report, $execute);
        $this->backfillNotificationLogs($plugins, $report, $execute);

        return $this->finalizeReport($report);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadPlugins(): array
    {
        $plugins = [];

        foreach (DB::table('integration_plugins')->orderBy('id')->get() as $plugin) {
            $domain = trim((string) $plugin->domain);
            $pluginKey = trim((string) $plugin->plugin_key);
            $slug = trim((string) $plugin->slug);
            $payload = [
                'id' => (int) $plugin->id,
                'domain' => $domain,
                'slug' => $slug,
                'plugin_key' => $pluginKey,
                'status' => (int) $plugin->status,
                'name' => (string) $plugin->name,
            ];

            if ($pluginKey !== '') {
                $plugins["{$domain}:{$pluginKey}"] = $payload;
            }
            if ($slug !== '') {
                $plugins["{$domain}:{$slug}"] = $payload;
            }
        }

        return $plugins;
    }

    /**
     * @param  array<string, array<string, mixed>>  $plugins
     */
    private function backfillGlobalBindings(array $plugins, array &$report, bool $execute, string $batchId): void
    {
        $rows = [];
        $uniquePlugins = [];

        foreach ($plugins as $plugin) {
            $uniquePlugins[(int) $plugin['id']] = $plugin;
        }

        foreach ($uniquePlugins as $plugin) {
            $this->increment($report, 'integration_plugin_bindings', 'total');
            $rows[] = [
                'domain' => (string) $plugin['domain'],
                'plugin_id' => (int) $plugin['id'],
                'binding_type' => 'global',
                'bindable_type' => 'global',
                'bindable_id' => 0,
                'binding_key' => (string) $plugin['plugin_key'],
                'provider_key' => (string) $plugin['plugin_key'],
                'priority' => 0,
                'status' => (int) $plugin['status'],
                'config_json' => $this->encodeJson([
                    'slug' => (string) $plugin['slug'],
                    'source' => 'plugin_registry',
                ]),
                'secret_json' => null,
                'has_secret_json' => null,
                'runtime_policy_json' => null,
                'backfill_batch_id' => $batchId,
            ];
            $this->increment($report, 'integration_plugin_bindings', 'success');
        }

        foreach ($this->selectionBindings($plugins, $report) as $row) {
            $this->increment($report, 'integration_plugin_bindings', 'total');
            $rows[] = array_merge($row, ['backfill_batch_id' => $batchId]);
            $this->increment($report, 'integration_plugin_bindings', 'success');
        }

        if ($execute) {
            foreach ($rows as $row) {
                $this->updateOrInsertWithTimestamps(
                    'integration_plugin_bindings',
                    [
                        'domain' => $row['domain'],
                        'binding_type' => $row['binding_type'],
                        'bindable_type' => $row['bindable_type'],
                        'bindable_id' => $row['bindable_id'],
                        'binding_key' => $row['binding_key'],
                    ],
                    $row
                );
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $plugins
     * @return array<int, array<string, mixed>>
     */
    private function selectionBindings(array $plugins, array &$report): array
    {
        $settings = [
            ['domain' => PluginDomain::MAIL, 'group' => 'notification', 'key' => 'mail_driver', 'binding_key' => 'mail_driver'],
            ['domain' => PluginDomain::SMS, 'group' => 'notification', 'key' => 'sms_driver', 'binding_key' => 'sms_driver'],
            ['domain' => PluginDomain::CAPTCHA, 'group' => 'system', 'key' => 'captcha_driver', 'binding_key' => 'captcha_driver'],
            ['domain' => PluginDomain::VERIFICATION, 'group' => 'verification', 'key' => 'verification_driver', 'binding_key' => 'verification_driver'],
        ];

        $rows = [];
        foreach ($settings as $setting) {
            $driverKey = trim((string) Setting::getValue($setting['group'], $setting['key'], ''));
            if ($driverKey === '' && $setting['key'] === 'sms_driver') {
                $driverKey = trim((string) Setting::getValue('notification', 'sms_provider', ''));
            }
            if ($driverKey === '') {
                $this->addWarning($report, 'missing_selection_driver', [
                    'group' => $setting['group'],
                    'key' => $setting['key'],
                ]);

                continue;
            }

            $plugin = $this->pluginByKey($plugins, $setting['domain'], $driverKey);
            if ($plugin === null) {
                $this->addUnknown($report, 'drivers', 'settings.'.$setting['group'].'.'.$setting['key'], $driverKey);

                continue;
            }

            $rows[] = [
                'domain' => $setting['domain'],
                'plugin_id' => (int) $plugin['id'],
                'binding_type' => $setting['domain'] === PluginDomain::MAIL || $setting['domain'] === PluginDomain::SMS
                    ? 'notification'
                    : 'global',
                'bindable_type' => 'setting',
                'bindable_id' => 0,
                'binding_key' => $setting['binding_key'],
                'provider_key' => $driverKey,
                'priority' => 0,
                'status' => (int) $plugin['status'],
                'config_json' => $this->encodeJson([
                    'setting_group' => $setting['group'],
                    'setting_key' => $setting['key'],
                    'source' => 'settings_selection',
                ]),
                'secret_json' => null,
                'has_secret_json' => null,
                'runtime_policy_json' => null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $plugins
     * @return array<string, array<string, mixed>>
     */
    private function backfillSupplierBindings(array $plugins, array &$report, bool $execute, string $batchId): array
    {
        $map = $this->loadSupplierBindingMap();
        foreach ($map as $row) {
            $this->increment($report, 'supplier_plugin_bindings', 'total');
            $this->increment($report, 'supplier_plugin_bindings', 'success');
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, mixed>>  $plugins
     * @param  array<string, array<string, mixed>>  $supplierBindings
     * @return array<int, array<string, mixed>>
     */
    private function backfillProductBindings(
        array $plugins,
        array $supplierBindings,
        array &$report,
        bool $execute,
        string $batchId
    ): array {
        $map = $this->loadProductBindingMap();
        foreach ($map as $row) {
            $this->increment($report, 'product_upstream_bindings', 'total');
            $this->increment($report, 'product_upstream_bindings', 'success');
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, mixed>>  $plugins
     * @param  array<string, array<string, mixed>>  $supplierBindings
     * @param  array<int, array<string, mixed>>  $productBindings
     * @return array<int, array<string, mixed>>
     */
    private function backfillServiceBindings(
        array $plugins,
        array $supplierBindings,
        array $productBindings,
        array &$report,
        bool $execute,
        string $batchId
    ): array {
        $map = $this->loadServiceBindingMap();
        foreach ($map as $row) {
            $this->increment($report, 'service_upstream_bindings', 'total');
            $this->increment($report, 'service_upstream_bindings', 'success');
        }

        return $map;
    }

    /**
     * @param  array<int, array<string, mixed>>  $serviceBindings
     */
    private function backfillServiceSnapshotsAndAttempts(
        array $serviceBindings,
        array &$report,
        bool $execute,
        string $batchId
    ): void {
        $runtimeRows = [];
        $connectionRows = [];
        $attemptRows = [];

        foreach (DB::table('services')->orderBy('id')->get() as $service) {
            $binding = $serviceBindings[(int) $service->id] ?? null;
            if ($binding === null) {
                continue;
            }

            $provisionData = $this->decodeJson($service->provision_data ?? null);
            $runtimeRows[] = [
                'service_id' => (int) $service->id,
                'service_upstream_binding_id' => $binding['id'],
                'plugin_id' => (int) $binding['plugin_id'],
                'provider_key' => (string) $binding['provider_key'],
                'status_key' => $this->nullableString($provisionData['runtime_status'] ?? ($provisionData['upstream_status'] ?? null), 60),
                'status_text' => $this->nullableString($provisionData['runtime_description'] ?? null, 120),
                'resource_json' => $this->encodeJson($this->serviceResourcePayload($provisionData)),
                'metrics_json' => $this->encodeJson($this->serviceMetricsPayload($provisionData)),
                'snapshot_json' => $this->encodeJson($this->serviceRuntimeSnapshotPayload($provisionData, (string) $binding['provider_key'])),
                'synced_at' => $this->dateOrNull($provisionData['last_synced_at'] ?? null),
                'backfill_batch_id' => $batchId,
            ];
            $this->increment($report, 'service_runtime_snapshots', 'total');
            $this->increment($report, 'service_runtime_snapshots', 'success');

            $connection = $this->serviceConnectionPayload($service, $provisionData);
            $connectionRows[] = [
                'service_id' => (int) $service->id,
                'service_upstream_binding_id' => $binding['id'],
                'plugin_id' => (int) $binding['plugin_id'],
                'provider_key' => (string) $binding['provider_key'],
                'connection_type' => 'default',
                'hostname' => $this->nullableString($connection['hostname'] ?? null, 255),
                'ip_address' => $this->nullableString($connection['ip_address'] ?? null, 120),
                'port' => $connection['port'] ?? null,
                'connection_json' => $this->encodeJson($connection),
                'secret_json' => $this->encryptSecrets([
                    'connection_secret' => $provisionData['connection_secret'] ?? null,
                    'password' => $provisionData['password'] ?? null,
                ]),
                'has_secret_json' => $this->encodeJson($this->hasSecretMap([
                    'connection_secret' => $provisionData['connection_secret'] ?? null,
                    'password' => $provisionData['password'] ?? null,
                ])),
                'checked_at' => $this->dateOrNull($provisionData['nat_remote_checked_at'] ?? ($provisionData['last_synced_at'] ?? null)),
                'backfill_batch_id' => $batchId,
            ];
            $this->increment($report, 'service_connection_snapshots', 'total');
            $this->increment($report, 'service_connection_snapshots', 'success');

            foreach ($this->serviceAttemptPayloads($service, $provisionData, $binding, $batchId) as $attempt) {
                $attemptRows[] = $attempt;
                $this->increment($report, 'service_provision_attempts', 'total');
                $this->increment($report, 'service_provision_attempts', 'success');
            }
        }

        if (! $execute) {
            return;
        }

        foreach ($runtimeRows as $row) {
            $this->updateOrInsertWithTimestamps('service_runtime_snapshots', ['service_id' => $row['service_id']], $row);
        }

        foreach ($connectionRows as $row) {
            $this->updateOrInsertWithTimestamps(
                'service_connection_snapshots',
                ['service_id' => $row['service_id'], 'connection_type' => $row['connection_type']],
                $row
            );
        }

        DB::table('service_provision_attempts')->where('backfill_batch_id', $batchId)->delete();
        foreach ($attemptRows as $row) {
            DB::table('service_provision_attempts')->insert(array_merge($row, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $plugins
     * @return array<int, array<string, mixed>>
     */
    private function backfillPayments(array $plugins, array &$report, bool $execute): array
    {
        $context = [];
        $gatewaySourceColumn = $this->paymentGatewaySourceColumn();

        foreach (DB::table('payments')->orderBy('id')->get() as $payment) {
            $this->increment($report, 'payments', 'total');
            $gatewayKey = $this->normalizeGatewayKey((string) ($payment->{$gatewaySourceColumn} ?? ''));
            if ($gatewayKey === '') {
                $this->increment($report, 'payments', 'failed');
                $this->addUnknown($report, 'gateways', 'payments.'.$gatewaySourceColumn, '', (int) $payment->id, 'blank_gateway');

                continue;
            }

            $plugin = $this->paymentPluginForGateway($plugins, $gatewayKey);
            if ($plugin === null && ! $this->isNonThirdPartyGateway($gatewayKey)) {
                $this->increment($report, 'payments', 'failed');
                $this->addUnknown($report, 'gateways', 'payments.'.$gatewaySourceColumn, $gatewayKey, (int) $payment->id);

                continue;
            }

            $pluginId = $plugin['id'] ?? null;
            $context[(int) $payment->id] = [
                'payment_no' => (string) $payment->payment_no,
                'trade_no' => (string) ($payment->trade_no ?? ''),
                'invoice_id' => $payment->invoice_id === null ? null : (int) $payment->invoice_id,
                'plugin_id' => $pluginId,
                'gateway_key' => $gatewayKey,
                'trace_id' => $this->nullableString($payment->trace_id ?? null, 64),
            ];

            if ($pluginId === null) {
                $this->increment($report, 'payments', 'skipped');
                $this->addWarning($report, 'non_third_party_payment_preserved', [
                    'payment_id' => (int) $payment->id,
                    'gateway_key' => $gatewayKey,
                ]);
            } else {
                $this->increment($report, 'payments', 'success');
            }

            if ($execute) {
                DB::table('payments')->where('id', (int) $payment->id)->update([
                    'plugin_id' => $pluginId,
                    'gateway_key' => $gatewayKey,
                ]);
            }
        }

        return $context;
    }

    private function paymentGatewaySourceColumn(): string
    {
        if (Schema::hasColumn('payments', 'gateway_key')) {
            return 'gateway_key';
        }

        return 'gateway';
    }

    /**
     * @param  array<int, array<string, mixed>>  $paymentContext
     */
    private function backfillPaymentCallbacks(array $paymentContext, array &$report, bool $execute): void
    {
        foreach (DB::table('payment_callbacks')->orderBy('id')->get() as $callback) {
            $this->increment($report, 'payment_callbacks', 'total');
            $payment = $paymentContext[(int) $callback->payment_id] ?? null;
            if ($payment === null) {
                $this->increment($report, 'payment_callbacks', 'failed');
                $this->addUnknown($report, 'gateways', 'payment_callbacks.payment_id', (string) $callback->payment_id, (int) $callback->id, 'missing_payment');

                continue;
            }

            $this->increment($report, 'payment_callbacks', 'success');
            if ($execute) {
                DB::table('payment_callbacks')->where('id', (int) $callback->id)->update([
                    'plugin_id' => $payment['plugin_id'],
                    'gateway_key' => $payment['gateway_key'],
                ]);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $plugins
     * @param  array<int, array<string, mixed>>  $paymentContext
     */
    private function backfillGatewayLogs(array $plugins, array $paymentContext, array &$report, bool $execute): void
    {
        $paymentByPaymentNo = [];
        $paymentByTradeNo = [];
        $paymentByInvoiceId = [];
        foreach ($paymentContext as $payment) {
            if (($payment['payment_no'] ?? '') !== '') {
                $paymentByPaymentNo[(string) $payment['payment_no']] = $payment;
            }
            if (($payment['trade_no'] ?? '') !== '') {
                $paymentByTradeNo[(string) $payment['trade_no']] = $payment;
            }
            if (($payment['invoice_id'] ?? null) !== null) {
                $paymentByInvoiceId[(int) $payment['invoice_id']] = $payment;
            }
        }

        foreach (DB::table('gateway_logs')->orderBy('id')->get() as $log) {
            $this->increment($report, 'gateway_logs', 'total');
            $gatewayKey = $this->normalizeGatewayKey((string) $log->gateway);
            $plugin = $this->paymentPluginForGateway($plugins, $gatewayKey);
            if ($gatewayKey === '' || ($plugin === null && ! $this->isNonThirdPartyGateway($gatewayKey))) {
                $this->increment($report, 'gateway_logs', 'failed');
                $this->addUnknown($report, 'gateways', 'gateway_logs.gateway', (string) $log->gateway, (int) $log->id);

                continue;
            }

            $matchedPayment = $paymentByTradeNo[(string) ($log->trade_no ?? '')]
                ?? $paymentByPaymentNo[(string) ($log->out_trade_no ?? '')]
                ?? ($log->invoice_id !== null ? ($paymentByInvoiceId[(int) $log->invoice_id] ?? null) : null);

            $this->increment($report, 'gateway_logs', 'success');
            if ($execute) {
                DB::table('gateway_logs')->where('id', (int) $log->id)->update([
                    'plugin_id' => $plugin['id'] ?? null,
                    'gateway_key' => $gatewayKey,
                    'trace_id' => $matchedPayment['trace_id'] ?? null,
                ]);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $plugins
     */
    private function backfillNotificationLogs(array $plugins, array &$report, bool $execute): void
    {
        $mail = $this->driverContext($plugins, PluginDomain::MAIL, 'notification', 'mail_driver');
        $sms = $this->driverContext($plugins, PluginDomain::SMS, 'notification', 'sms_driver', 'sms_provider');

        if (! Schema::hasTable('message_logs')) {
            return;
        }

        foreach (DB::table('message_logs')->orderBy('id')->get() as $row) {
            $this->increment($report, 'message_logs', 'total');
            $channel = trim((string) $row->channel);
            $context = match ($channel) {
                'email' => $mail,
                'sms' => $sms,
                default => null,
            };

            if ($context === null) {
                $this->increment($report, 'message_logs', 'failed');
                $this->addUnknown($report, 'drivers', 'message_logs.channel', $channel, (int) $row->id);

                continue;
            }

            $this->increment($report, 'message_logs', 'success');
            if ($execute) {
                DB::table('message_logs')->where('id', (int) $row->id)->update([
                    'plugin_id' => $context['plugin_id'],
                    'driver_key' => $context['driver_key'],
                    'trace_id' => $this->historicalTraceId('message_logs', (int) $row->id),
                ]);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $plugins
     * @return array{plugin_id:int,driver_key:string}|null
     */
    private function driverContext(array $plugins, string $domain, string $group, string $key, ?string $fallbackKey = null): ?array
    {
        $driverKey = trim((string) Setting::getValue($group, $key, ''));
        if ($driverKey === '' && $fallbackKey !== null) {
            $driverKey = trim((string) Setting::getValue($group, $fallbackKey, ''));
        }
        if ($driverKey === '') {
            return null;
        }

        $plugin = $this->pluginByKey($plugins, $domain, $driverKey);

        return $plugin === null ? null : [
            'plugin_id' => (int) $plugin['id'],
            'driver_key' => $driverKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $provisionData
     * @return array<string, mixed>
     */
    private function serviceRuntimeSnapshotPayload(array $provisionData, string $providerKey): array
    {
        return array_filter([
            'provider_key' => $providerKey !== '' ? $providerKey : ($provisionData['provider_key'] ?? null),
            'upstream_status' => $provisionData['upstream_status'] ?? null,
            'runtime_status' => $provisionData['runtime_status'] ?? null,
            'runtime_description' => $provisionData['runtime_description'] ?? null,
            'last_synced_at' => $provisionData['last_synced_at'] ?? null,
            'last_status_sync_at' => $provisionData['last_status_sync_at'] ?? null,
            'status_sync_error' => $provisionData['status_sync_error'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $provisionData
     * @return array<string, mixed>
     */
    private function serviceResourcePayload(array $provisionData): array
    {
        return array_filter([
            'supplier_id' => $provisionData['supplier_id'] ?? null,
            'upstream_product_id' => $provisionData['upstream_product_id'] ?? null,
            'upstream_product_name' => $provisionData['upstream_product_name'] ?? null,
            'upstream_host_id' => $provisionData['upstream_host_id'] ?? null,
            'upstream_host_ids' => $provisionData['upstream_host_ids'] ?? null,
            'upstream_invoice_id' => $provisionData['upstream_invoice_id'] ?? null,
            'server_id' => $provisionData['server_id'] ?? null,
            'old_host_id' => $provisionData['old_host_id'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $provisionData
     * @return array<string, mixed>
     */
    private function serviceMetricsPayload(array $provisionData): array
    {
        return array_filter([
            'bw_limit' => $provisionData['bw_limit'] ?? null,
            'bw_usage' => $provisionData['bw_usage'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $provisionData
     * @return array<string, mixed>
     */
    private function serviceConnectionPayload(object $service, array $provisionData): array
    {
        $assignedIps = is_array($provisionData['assigned_ips'] ?? null) ? $provisionData['assigned_ips'] : [];
        $hostname = $this->firstNonBlank($provisionData, [
            'dedicated_ip',
            'nat_remote_host',
            'nat_remote_address',
            'requested_host',
            'custom_hostname',
            'default_service_name',
        ]) ?? $this->nullableString($service->domain ?? null, 255);
        $ipAddress = $this->nullableString($provisionData['dedicated_ip'] ?? ($assignedIps[0] ?? null), 120);

        return array_filter([
            'hostname' => $hostname,
            'ip_address' => $ipAddress,
            'port' => is_numeric($provisionData['nat_remote_port'] ?? null) ? (int) $provisionData['nat_remote_port'] : null,
            'assigned_ips' => $assignedIps,
            'dedicated_ip' => $provisionData['dedicated_ip'] ?? null,
            'nat_remote_host' => $provisionData['nat_remote_host'] ?? null,
            'nat_remote_address' => $provisionData['nat_remote_address'] ?? null,
            'nat_remote_port' => $provisionData['nat_remote_port'] ?? null,
            'username' => $provisionData['username'] ?? null,
            'os' => $provisionData['os'] ?? null,
            'has_connection_secret' => trim((string) ($provisionData['connection_secret'] ?? '')) !== '',
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $provisionData
     * @param  array<string, mixed>  $binding
     * @return array<int, array<string, mixed>>
     */
    private function serviceAttemptPayloads(object $service, array $provisionData, array $binding, string $batchId): array
    {
        $rows = [];
        $traceId = $this->nullableString($provisionData['trace_id'] ?? ($service->trace_id ?? null), 64);
        $base = [
            'service_id' => (int) $service->id,
            'service_upstream_binding_id' => $binding['id'],
            'plugin_id' => (int) $binding['plugin_id'],
            'provider_key' => (string) $binding['provider_key'],
            'trace_id' => $traceId,
            'backfill_batch_id' => $batchId,
        ];

        if (trim((string) ($provisionData['provision_error'] ?? '')) !== '') {
            $rows[] = array_merge($base, [
                'action' => 'provision',
                'attempt_status' => 'failed',
                'request_meta_json' => $this->encodeJson([
                    'requested_host' => $provisionData['requested_host'] ?? null,
                    'created_from_order' => $provisionData['created_from_order'] ?? null,
                    'requested_config_keys' => array_keys((array) ($provisionData['requested_config'] ?? [])),
                ]),
                'response_meta_json' => null,
                'error_code' => 'legacy_provision_error',
                'error_message' => $this->nullableString($provisionData['provision_error'] ?? null, 500),
                'attempted_at' => $this->dateOrNull($provisionData['last_synced_at'] ?? ($service->created_at ?? null)),
            ]);
        } elseif (trim((string) ($provisionData['upstream_host_id'] ?? '')) !== '') {
            $rows[] = array_merge($base, [
                'action' => 'provision',
                'attempt_status' => 'success',
                'request_meta_json' => $this->encodeJson([
                    'requested_host' => $provisionData['requested_host'] ?? null,
                    'created_from_order' => $provisionData['created_from_order'] ?? null,
                ]),
                'response_meta_json' => $this->encodeJson([
                    'upstream_host_id' => $provisionData['upstream_host_id'] ?? null,
                    'upstream_invoice_id' => $provisionData['upstream_invoice_id'] ?? null,
                ]),
                'error_code' => null,
                'error_message' => null,
                'attempted_at' => $this->dateOrNull($service->created_at ?? null),
            ]);
        }

        if (trim((string) ($provisionData['renew_error'] ?? '')) !== '') {
            $rows[] = array_merge($base, [
                'action' => 'renew',
                'attempt_status' => 'failed',
                'request_meta_json' => $this->encodeJson([
                    'renew_invoice_id' => $provisionData['renew_invoice_id'] ?? null,
                    'last_renew_invoice_id' => $provisionData['last_renew_invoice_id'] ?? null,
                    'initiative_renew' => $provisionData['initiative_renew'] ?? null,
                ]),
                'response_meta_json' => null,
                'error_code' => 'legacy_renew_error',
                'error_message' => $this->nullableString($provisionData['renew_error'] ?? null, 500),
                'attempted_at' => $this->dateOrNull($provisionData['last_renewed_at'] ?? null),
            ]);
        }

        if (trim((string) ($provisionData['last_power_action'] ?? '')) !== '') {
            $rows[] = array_merge($base, [
                'action' => 'power.'.Str::slug((string) $provisionData['last_power_action'], '_'),
                'attempt_status' => 'recorded',
                'request_meta_json' => null,
                'response_meta_json' => $this->encodeJson([
                    'last_power_action' => $provisionData['last_power_action'] ?? null,
                    'last_power_action_at' => $provisionData['last_power_action_at'] ?? null,
                ]),
                'error_code' => null,
                'error_message' => null,
                'attempted_at' => $this->dateOrNull($provisionData['last_power_action_at'] ?? null),
            ]);
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $plugins
     * @return array<string, mixed>|null
     */
    private function paymentPluginForGateway(array $plugins, string $gatewayKey): ?array
    {
        return $this->pluginByKey($plugins, PluginDomain::PAYMENT, $gatewayKey);
    }

    /**
     * @param  array<string, array<string, mixed>>  $plugins
     * @return array<string, mixed>|null
     */
    private function pluginByKey(array $plugins, string $domain, string $key): ?array
    {
        $normalized = trim($key);
        if ($normalized === '') {
            return null;
        }

        return $plugins["{$domain}:{$normalized}"] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadSupplierBindingMap(): array
    {
        $map = [];
        foreach (DB::table('supplier_plugin_bindings')->get() as $row) {
            $map[$this->supplierBindingMapKey((int) $row->supplier_id, (string) $row->provider_key)] = [
                'id' => (int) $row->id,
                'supplier_id' => (int) $row->supplier_id,
                'plugin_id' => (int) $row->plugin_id,
                'provider_key' => (string) $row->provider_key,
            ];
        }

        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadProductBindingMap(): array
    {
        $map = [];
        foreach (DB::table('product_upstream_bindings')->get() as $row) {
            $map[(int) $row->product_id] = [
                'id' => (int) $row->id,
                'product_id' => (int) $row->product_id,
                'supplier_plugin_binding_id' => (int) $row->supplier_plugin_binding_id,
                'plugin_id' => (int) $row->plugin_id,
                'provider_key' => (string) $row->provider_key,
                'upstream_product_id' => (string) $row->upstream_product_id,
            ];
        }

        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadServiceBindingMap(): array
    {
        $map = [];
        foreach (DB::table('service_upstream_bindings')->get() as $row) {
            $map[(int) $row->service_id] = [
                'id' => (int) $row->id,
                'service_id' => (int) $row->service_id,
                'plugin_id' => (int) $row->plugin_id,
                'provider_key' => (string) $row->provider_key,
            ];
        }

        return $map;
    }

    private function supplierBindingMapKey(int $supplierId, string $providerKey): string
    {
        return $supplierId.':'.trim($providerKey);
    }

    private function normalizeGatewayKey(string $gateway): string
    {
        return match (trim($gateway)) {
            PaymentGatewayCode::ALIPAY_F2F_PLUGIN, 'ali_pay' => PaymentGatewayCode::ALIPAY,
            'yi_pay' => PaymentGatewayCode::YIPAY,
            default => trim($gateway),
        };
    }

    private function isNonThirdPartyGateway(string $gatewayKey): bool
    {
        return in_array($gatewayKey, self::NON_THIRD_PARTY_GATEWAYS, true);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function updateOrInsertWithTimestamps(string $table, array $unique, array $values): void
    {
        $exists = DB::table($table)->where($unique)->exists();
        $payload = array_merge($values, ['updated_at' => now()]);
        if (! $exists) {
            $payload['created_at'] = now();
        }

        DB::table($table)->updateOrInsert($unique, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseReport(string $mode, string $batchId, int $chunkSize): array
    {
        $tables = [
            'integration_plugin_bindings',
            'supplier_plugin_bindings',
            'product_upstream_bindings',
            'service_upstream_bindings',
            'service_runtime_snapshots',
            'service_connection_snapshots',
            'service_provision_attempts',
            'payments',
            'payment_callbacks',
            'gateway_logs',
            'message_logs',
        ];

        return [
            'mode' => $mode,
            'batch_id' => $batchId,
            'database' => DB::getDatabaseName(),
            'chunk_size' => $chunkSize,
            'generated_at' => now()->toISOString(),
            'tables' => array_fill_keys($tables, [
                'total' => 0,
                'success' => 0,
                'skipped' => 0,
                'failed' => 0,
            ]),
            'unknowns' => [
                'providers' => [],
                'gateways' => [],
                'drivers' => [],
            ],
            'warnings' => [],
            '_seen' => [
                'unknowns' => [],
            ],
        ];
    }

    private function increment(array &$report, string $table, string $field): void
    {
        if (! isset($report['tables'][$table])) {
            $report['tables'][$table] = ['total' => 0, 'success' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $report['tables'][$table][$field] = (int) ($report['tables'][$table][$field] ?? 0) + 1;
    }

    private function addUnknown(array &$report, string $bucket, string $source, string $key, int|string|null $id = null, string $reason = ''): void
    {
        $hash = $bucket.'|'.$source.'|'.$key.'|'.(string) $id.'|'.$reason;
        if (isset($report['_seen']['unknowns'][$hash])) {
            return;
        }

        $report['_seen']['unknowns'][$hash] = true;
        $report['unknowns'][$bucket][] = array_filter([
            'source' => $source,
            'key' => $key,
            'id' => $id,
            'reason' => $reason,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $sample
     */
    private function addWarning(array &$report, string $bucket, array $sample): void
    {
        if (! isset($report['warnings'][$bucket])) {
            $report['warnings'][$bucket] = [
                'count' => 0,
                'samples' => [],
            ];
        }

        $report['warnings'][$bucket]['count']++;
        if (count($report['warnings'][$bucket]['samples']) < 30) {
            $report['warnings'][$bucket]['samples'][] = $sample;
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function hasBlockingUnknowns(array $report): bool
    {
        foreach (['providers', 'gateways', 'drivers'] as $bucket) {
            if (($report['unknown_counts'][$bucket] ?? count((array) ($report['unknowns'][$bucket] ?? []))) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function finalizeReport(array $report): array
    {
        unset($report['_seen']);

        $report['unknown_counts'] = [
            'providers' => count((array) ($report['unknowns']['providers'] ?? [])),
            'gateways' => count((array) ($report['unknowns']['gateways'] ?? [])),
            'drivers' => count((array) ($report['unknowns']['drivers'] ?? [])),
        ];
        $report['has_blocking_unknowns'] = $this->hasBlockingUnknowns($report);

        return $report;
    }

    private function normalizeBatchId(?string $batchId): string
    {
        $normalized = trim((string) $batchId);

        return $normalized !== '' ? Str::limit($normalized, 64, '') : self::DEFAULT_BATCH_ID;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeJson(?array $payload): ?string
    {
        if ($payload === null || $payload === []) {
            return null;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $secrets
     */
    private function encryptSecrets(array $secrets): ?string
    {
        $filtered = array_filter($secrets, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
        if ($filtered === []) {
            return null;
        }

        return Crypt::encryptString((string) json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $secrets
     * @return array<string, bool>|null
     */
    private function hasSecretMap(array $secrets): ?array
    {
        $map = [];
        foreach ($secrets as $key => $value) {
            if ($value !== null && $value !== '' && $value !== []) {
                $map[$key] = true;
            }
        }

        return $map === [] ? null : $map;
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, $maxLength);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function firstNonBlank(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return null;
        }

        try {
            return Carbon::parse($normalized)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function historicalTraceId(string $table, int $id): string
    {
        return 'bf-'.substr(hash('sha256', $table.':'.$id), 0, 32);
    }
}
