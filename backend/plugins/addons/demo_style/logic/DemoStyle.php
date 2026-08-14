<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\DemoStyle\Logic;

class DemoStyle
{
    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $config = is_array($request['config'] ?? null) ? $request['config'] : [];

        return match ($action) {
            'addon.metadata' => $this->metadata($action, $config),
            'addon.admin.index' => $this->adminIndex($action, $config),
            'addon.client.index' => $this->clientIndex($action, $config),
            'addon.public.assets' => $this->publicAssets($action, $config),
            'addon.health_check' => [
                'success' => true,
                'action' => $action,
                'data' => $this->healthPayload($config),
                'message' => 'Demo Style 功能扩展加载正常',
            ],
            default => [
                'success' => false,
                'action' => $action,
                'message' => '不支持的 Addon 动作',
                'data' => [],
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        return [
            'healthy' => true,
            'message' => 'Demo Style 功能扩展加载正常',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function metadata(string $action, array $config): array
    {
        return [
            'success' => true,
            'action' => $action,
            'data' => array_merge($this->healthPayload($config), [
                'entries' => [
                    'admin' => 'addon.admin.index',
                    'client' => 'addon.client.index',
                    'public' => 'addon.public.assets',
                ],
                'zjmf_shape' => [
                    'config',
                    'controller',
                    'controller/clientarea',
                    'lang',
                    'template/admin',
                    'template/clientarea',
                    'template/public',
                    'validate',
                ],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function adminIndex(string $action, array $config): array
    {
        return [
            'success' => true,
            'action' => $action,
            'data' => [
                'title' => '样式演示后台页',
                'cards' => [
                    [
                        'label' => '主题',
                        'value' => $this->themeName($config),
                    ],
                    [
                        'label' => '强调色',
                        'value' => $this->accentColor($config),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function clientIndex(string $action, array $config): array
    {
        return [
            'success' => true,
            'action' => $action,
            'data' => [
                'title' => '样式演示用户页',
                'theme_name' => $this->themeName($config),
                'accent_color' => $this->accentColor($config),
                'widgets' => [
                    ['type' => 'banner', 'text' => 'Demo Style Addon'],
                    ['type' => 'link', 'text' => '返回控制台', 'target' => '/client'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function publicAssets(string $action, array $config): array
    {
        return [
            'success' => true,
            'action' => $action,
            'data' => [
                'css_variables' => [
                    '--addon-demo-style-accent' => $this->accentColor($config),
                    '--addon-demo-style-name' => $this->themeName($config),
                ],
                'assets' => [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function healthPayload(array $config): array
    {
        return [
            'healthy' => true,
            'key' => 'demo_style',
            'theme_name' => $this->themeName($config),
            'accent_color' => $this->accentColor($config),
            'enabled' => (bool) ($config['enabled'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function themeName(array $config): string
    {
        $themeName = trim((string) ($config['theme_name'] ?? ''));

        return $themeName !== '' ? $themeName : 'classic-blue';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function accentColor(array $config): string
    {
        $accentColor = trim((string) ($config['accent_color'] ?? ''));

        return $accentColor !== '' ? $accentColor : '#2563eb';
    }
}
