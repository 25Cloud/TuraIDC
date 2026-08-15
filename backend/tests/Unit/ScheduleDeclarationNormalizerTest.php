<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Automation\Heartbeat\ScheduleDeclarationNormalizer;
use PHPUnit\Framework\TestCase;

final class ScheduleDeclarationNormalizerTest extends TestCase
{
    public function test_single_string_declaration_becomes_one_item(): void
    {
        $this->assertSame(
            [NormalizerFixtureListener::class],
            ScheduleDeclarationNormalizer::list(NormalizerFixtureListener::class),
        );
    }

    public function test_callable_descriptor_stays_together(): void
    {
        $definition = [NormalizerFixtureListener::class, 'handle'];

        $this->assertSame([$definition], ScheduleDeclarationNormalizer::list($definition));
        $this->assertSame(NormalizerFixtureListener::class, ScheduleDeclarationNormalizer::className($definition));
        $this->assertSame('handle', ScheduleDeclarationNormalizer::methodName($definition));
    }

    public function test_callable_descriptor_allows_case_insensitive_php_method_names(): void
    {
        $definition = [NormalizerFixtureListener::class, 'HANDLE'];

        $this->assertSame([$definition], ScheduleDeclarationNormalizer::list($definition));
        $this->assertSame('HANDLE', ScheduleDeclarationNormalizer::methodName($definition));
    }

    public function test_two_class_names_are_not_mistaken_for_a_callable_descriptor(): void
    {
        $definitions = [NormalizerFixtureListener::class, NormalizerFixtureSecondListener::class];

        $this->assertSame($definitions, ScheduleDeclarationNormalizer::list($definitions));
    }

    public function test_two_lowercase_class_names_are_not_mistaken_for_a_callable_descriptor(): void
    {
        $definitions = [normalizer_lowercase_first::class, normalizer_lowercase_second::class];

        $this->assertSame($definitions, ScheduleDeclarationNormalizer::list($definitions));
    }

    public function test_named_descriptor_uses_explicit_class_and_method(): void
    {
        $definition = [
            'class' => NormalizerFixtureListener::class,
            'method' => 'handle',
        ];

        $this->assertSame([$definition], ScheduleDeclarationNormalizer::list($definition));
        $this->assertSame(NormalizerFixtureListener::class, ScheduleDeclarationNormalizer::className($definition));
        $this->assertSame('handle', ScheduleDeclarationNormalizer::methodName($definition));
    }
}

final class NormalizerFixtureListener
{
    public function handle(): void {}
}

final class NormalizerFixtureSecondListener
{
    public function handle(): void {}
}

final class normalizer_lowercase_first {}

final class normalizer_lowercase_second {}
