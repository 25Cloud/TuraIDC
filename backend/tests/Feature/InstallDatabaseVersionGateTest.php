<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Install\InstallException;
use App\Services\Install\InstallService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 安装期数据库版本闸门的判定口径。
 *
 * 直接喂各发行版真实版本串，覆盖两条易错边界：MariaDB 的 `5.5.5-` 假前缀
 * （直接取第一个版本号会把 10.3 误读成 5.5.5 而拒装），以及 MySQL 5.7.8 这个
 * 由 json 列 / 虚拟生成列唯一索引决定的硬下限。
 */
class InstallDatabaseVersionGateTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function supportedMariaDbVersions(): array
    {
        return [
            '握手包假前缀形态' => ['5.5.5-10.3.39-MariaDB-1:10.3.39+maria~focal'],
            'SELECT VERSION() 常见形态' => ['10.3.39-MariaDB-1:10.3.39+maria~focal'],
            '11.x 已取消前缀' => ['11.4.2-MariaDB-ubu2404'],
        ];
    }

    /**
     * @dataProvider supportedMariaDbVersions
     */
    public function test_supported_mariadb_versions_pass_the_gate(string $rawVersion): void
    {
        $this->assertNull(
            $this->checkVersion($rawVersion),
            "MariaDB 版本串 {$rawVersion} 应判定为达标且无警示"
        );
    }

    public function test_mariadb_below_the_floor_is_rejected(): void
    {
        $this->expectException(InstallException::class);
        $this->expectExceptionMessageMatches('/MariaDB 版本过低/');

        $this->checkVersion('5.5.5-10.2.44-MariaDB-1:10.2.44+maria~bionic');
    }

    public function test_mysql_below_5_7_8_is_rejected(): void
    {
        $this->expectException(InstallException::class);
        $this->expectExceptionMessageMatches('/最低需要 5\.7\.8/');

        $this->checkVersion('5.6.51-log');
    }

    public function test_mysql_5_7_passes_with_compatibility_notice(): void
    {
        $notice = $this->checkVersion('5.7.44-log');

        $this->assertIsString($notice);
        $this->assertStringContainsString('兼容支持档', $notice);
        $this->assertStringContainsString('explicit_defaults_for_timestamp=ON', $notice);
    }

    public function test_mysql_8_passes_without_notice(): void
    {
        $this->assertNull($this->checkVersion('8.0.36-0ubuntu0.22.04.1'));
    }

    public function test_unparsable_version_is_allowed_through(): void
    {
        // 未知发行版不误拦：解析不出版本号时保守放行，把判断交给后续实际执行。
        $this->assertNull($this->checkVersion('unknown-build'));
    }

    private function checkVersion(string $rawVersion): ?string
    {
        $method = new ReflectionMethod(InstallService::class, 'checkDatabaseVersionString');
        $method->setAccessible(true);

        return $method->invoke(app(InstallService::class), $rawVersion);
    }
}
