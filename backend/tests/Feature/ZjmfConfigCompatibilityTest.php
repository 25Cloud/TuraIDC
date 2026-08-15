<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ZjmfConfigCompatibilityTest extends TestCase
{
    public function test_hosting_panel_config_reads_env_without_zjmf_config_file(): void
    {
        $this->assertFileDoesNotExist(base_path('config/zjmf.php'));
        $this->assertFalse(config()->has('zjmf.finance_api.ssl_verify'));

        $this->assertIsBool((bool) config('idc.hosting_panel_api.ssl_verify'));
        $this->assertNotSame('', (string) config('idc.hosting_panel_api.jwt_cache_store'));
    }

    public function test_idc_hosting_panel_config_uses_hosting_panel_env_directly(): void
    {
        $this->withEnvironmentValue('HOSTING_PANEL_API_JWT_CACHE_STORE', 'array', function (): void {
            $idc = require base_path('config/idc.php');

            $this->assertSame('array', $idc['hosting_panel_api']['jwt_cache_store']);
        });
    }

    private function withEnvironmentValue(string $key, string $value, callable $callback): void
    {
        $originalEnv = $_ENV[$key] ?? null;
        $originalServer = $_SERVER[$key] ?? null;
        $originalPutenv = getenv($key);

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key.'='.$value);

        try {
            $callback();
        } finally {
            if ($originalEnv === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $originalEnv;
            }

            if ($originalServer === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $originalServer;
            }

            if ($originalPutenv === false) {
                putenv($key);
            } else {
                putenv($key.'='.$originalPutenv);
            }
        }
    }
}
