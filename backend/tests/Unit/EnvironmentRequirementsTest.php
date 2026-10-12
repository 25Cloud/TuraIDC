<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\EnvironmentRequirements;
use Tests\TestCase;

class EnvironmentRequirementsTest extends TestCase
{
    public function test_it_inspects_php_version_required_and_optional_extensions(): void
    {
        $items = collect(EnvironmentRequirements::inspect());
        $names = $items->pluck('name');

        $this->assertTrue($names->contains('PHP 版本 >= '.EnvironmentRequirements::MIN_PHP_VERSION));

        foreach (array_keys(EnvironmentRequirements::REQUIRED_EXTENSIONS) as $extension) {
            $this->assertTrue(
                $names->contains('PHP 扩展 '.$extension),
                "Missing required extension check for [{$extension}]."
            );
        }

        foreach (EnvironmentRequirements::REQUIRED_EXTENSIONS as $extension => $purpose) {
            $item = $items->firstWhere('name', 'PHP 扩展 '.$extension);
            $this->assertTrue($item['required']);
            // message 写的是该扩展的用途，供未安装时说明缺它会失去什么能力。
            $this->assertSame($purpose, $item['message']);
        }

        foreach (array_keys(EnvironmentRequirements::OPTIONAL_EXTENSIONS) as $extension) {
            $item = $items->firstWhere('name', 'PHP 扩展 '.$extension.'（建议）');
            $this->assertNotNull($item);
            $this->assertFalse($item['required'], "Optional extension [{$extension}] must not block install.");
        }
    }

    public function test_it_checks_required_functions_are_not_disabled(): void
    {
        $items = collect(EnvironmentRequirements::inspect());

        foreach (EnvironmentRequirements::REQUIRED_FUNCTIONS as $function) {
            $item = $items->firstWhere('name', '可调用函数 '.$function);
            $this->assertNotNull($item, "Missing disabled-function check for [{$function}].");
            $this->assertTrue($item['required']);
            $this->assertTrue(
                $item['passed'],
                "Function [{$function}] should be reported as available when it is not in disable_functions."
            );
        }
    }

    public function test_it_returns_unique_items_without_duplicate_extension_entries(): void
    {
        $names = collect(EnvironmentRequirements::inspect())->pluck('name');

        $this->assertSame(
            $names->unique()->count(),
            $names->count(),
            'Environment requirement items must not contain duplicate names.'
        );
    }

    public function test_disabled_functions_parsing_normalizes_and_filters_empty_entries(): void
    {
        $disabled = EnvironmentRequirements::disabledFunctions();

        $this->assertIsArray($disabled);
        $this->assertSame(
            $disabled,
            collect($disabled)->unique()->values()->all(),
            'Disabled function list should be deduplicated and re-indexed.'
        );

        foreach ($disabled as $name) {
            $this->assertSame(strtolower($name), $name);
            $this->assertNotSame('', trim($name));
        }
    }

    public function test_passed_reports_true_when_every_required_item_is_available(): void
    {
        $failed = collect(EnvironmentRequirements::inspect())
            ->filter(fn (array $item): bool => $item['required'] && ! $item['passed'])
            ->pluck('name');

        $this->assertSame(
            EnvironmentRequirements::passed(),
            $failed->isEmpty(),
            'EnvironmentRequirements::passed() must agree with the per-item required flags.'
        );
    }
}
