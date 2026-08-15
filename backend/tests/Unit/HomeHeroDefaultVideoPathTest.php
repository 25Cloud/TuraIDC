<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Content\HomeHeroService;
use ReflectionMethod;
use Tests\TestCase;

class HomeHeroDefaultVideoPathTest extends TestCase
{
    public function test_missing_default_hero_video_returns_empty_path(): void
    {
        $method = new ReflectionMethod(HomeHeroService::class, 'defaultHeroVideoPath');
        $filename = 'missing-hero-video-'.bin2hex(random_bytes(8)).'.mp4';

        $this->assertSame('', $method->invoke(new HomeHeroService, $filename));
    }
}
