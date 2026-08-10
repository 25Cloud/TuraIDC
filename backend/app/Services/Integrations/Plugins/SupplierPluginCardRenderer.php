<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Models\IntegrationPlugin;
use App\Models\Supplier;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SupplierPluginCardRenderer
{
    public function __construct(
        private readonly Container $container,
        private readonly PluginScanner $scanner,
        private readonly PluginFileLoader $fileLoader,
        private readonly PluginBindingResolver $bindingResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function render(Supplier $supplier, array $context = []): array
    {
        $binding = is_array($context['binding'] ?? null)
            ? (array) $context['binding']
            : $this->bindingResolver->supplierBindingProjection($supplier);

        if ($binding === [] || ! Schema::hasTable('integration_plugins')) {
            return $this->placeholderCard();
        }

        $plugin = IntegrationPlugin::query()->find((int) ($binding['plugin_id'] ?? 0));
        if (! $plugin instanceof IntegrationPlugin || ! $plugin->isEnabled()) {
            return $this->placeholderCard();
        }

        $manifest = $this->scanner->find((string) $plugin->domain, (string) $plugin->slug);
        if (! $manifest instanceof PluginManifest) {
            return $this->placeholderCard();
        }

        try {
            $this->fileLoader->ensureLoaded($manifest);
            $entry = $this->container->make($manifest->entryClass);

            if (! method_exists($entry, 'renderCard')) {
                return $this->placeholderCard();
            }

            $runtimeSupplier = clone $supplier;
            $this->bindingResolver->supplierWithRuntimeCredentials($runtimeSupplier, includeSecrets: false);
            $card = $entry->renderCard($runtimeSupplier, array_replace($context, [
                'binding' => $binding,
                'plugin' => [
                    'domain' => $manifest->domain,
                    'slug' => $manifest->slug,
                    'key' => $manifest->key,
                    'name' => $manifest->name,
                ],
            ]));

            return $this->normalizeCard(is_array($card) ? $card : []);
        } catch (\Throwable $exception) {
            Log::warning('[plugins] supplier card render failed', [
                'supplier_id' => (int) $supplier->id,
                'plugin_id' => (int) $plugin->id,
                'message' => $exception->getMessage(),
            ]);

            return $this->placeholderCard('插件信息加载失败');
        }
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    private function normalizeCard(array $card): array
    {
        $title = trim((string) ($card['title'] ?? ''));
        $subtitle = trim((string) ($card['subtitle'] ?? ''));
        $emptyText = trim((string) ($card['empty_text'] ?? ''));
        $fields = $this->normalizeFields($card['fields'] ?? []);
        $actions = $this->normalizeActions($card['actions'] ?? []);
        $status = $this->normalizeStatus($card['status'] ?? null);

        return array_filter([
            'provided' => true,
            'title' => $title,
            'subtitle' => $subtitle,
            'status' => $status,
            'fields' => $fields,
            'actions' => $actions,
            'empty_text' => $emptyText,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFields(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $field): ?array {
            if (! is_array($field)) {
                return null;
            }

            $label = trim((string) ($field['label'] ?? ''));
            if ($label === '') {
                return null;
            }

            return array_filter([
                'key' => trim((string) ($field['key'] ?? '')),
                'label' => $label,
                'value' => $field['value'] ?? '',
                'theme' => trim((string) ($field['theme'] ?? '')),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }, $fields)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeActions(mixed $actions): array
    {
        if (! is_array($actions)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $action): ?array {
            if (! is_array($action)) {
                return null;
            }

            $key = trim((string) ($action['key'] ?? ''));
            $label = trim((string) ($action['label'] ?? ''));
            $actionName = trim((string) ($action['action'] ?? ''));
            if ($key === '' || $label === '' || $actionName === '') {
                return null;
            }

            return array_filter([
                'key' => $key,
                'label' => $label,
                'action' => $actionName,
                'request_action' => trim((string) ($action['request_action'] ?? '')),
                'theme' => trim((string) ($action['theme'] ?? '')),
                'variant' => trim((string) ($action['variant'] ?? '')),
                'disabled' => (bool) ($action['disabled'] ?? false),
                'disabled_reason' => trim((string) ($action['disabled_reason'] ?? '')),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }, $actions)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeStatus(mixed $status): ?array
    {
        if (! is_array($status)) {
            return null;
        }

        $label = trim((string) ($status['label'] ?? ''));
        if ($label === '') {
            return null;
        }

        return array_filter([
            'label' => $label,
            'theme' => trim((string) ($status['theme'] ?? '')),
            'variant' => trim((string) ($status['variant'] ?? 'light')),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function placeholderCard(string $message = '插件未提供信息'): array
    {
        return [
            'provided' => false,
            'empty_text' => $message,
            'fields' => [],
            'actions' => [],
        ];
    }
}
