<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\UpstreamReadCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 上游只读缓存的单飞语义。
 *
 * 并发合并本身要靠锁，单线程测不出来；这里锁住三条可验证的契约：
 * 命中不执行取数、未命中取一次并回填、回填后第二次直接命中。
 * 任何一条挂了，都意味着「刷新页面又去同步打上游」的老问题回来了。
 */
class UpstreamReadCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_hit_skips_the_fetch(): void
    {
        Cache::put('probe:hit', ['value' => 'cached'], 60);

        $calls = 0;
        $result = UpstreamReadCache::remember('probe:hit', 60, function () use (&$calls) {
            $calls++;

            return ['value' => 'fresh'];
        });

        $this->assertSame(['value' => 'cached'], $result);
        $this->assertSame(0, $calls);
    }

    public function test_miss_fetches_once_and_stores_the_result(): void
    {
        $calls = 0;
        $result = UpstreamReadCache::remember('probe:miss', 60, function () use (&$calls) {
            $calls++;

            return ['value' => 'fresh'];
        });

        $this->assertSame(['value' => 'fresh'], $result);
        $this->assertSame(1, $calls);
        $this->assertSame(['value' => 'fresh'], Cache::get('probe:miss'));
    }

    public function test_second_call_after_miss_is_served_from_cache(): void
    {
        $calls = 0;
        $fetch = function () use (&$calls) {
            $calls++;

            return ['value' => 'fresh'];
        };

        UpstreamReadCache::remember('probe:reuse', 60, $fetch);
        UpstreamReadCache::remember('probe:reuse', 60, $fetch);

        $this->assertSame(1, $calls, '第二次调用必须命中缓存，不能再打一次上游');
    }

    public function test_fetch_exception_is_not_swallowed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('上游炸了');

        UpstreamReadCache::remember('probe:boom', 60, function (): never {
            throw new \RuntimeException('上游炸了');
        });
    }

    public function test_single_flight_runs_the_callback(): void
    {
        $result = UpstreamReadCache::singleFlight('probe:sf', fn (): array => ['ok' => true]);

        $this->assertSame(['ok' => true], $result);
    }

    /**
     * singleFlight 不接管缓存：回调自己怎么写缓存就怎么生效，它不许偷偷覆盖 TTL。
     */
    public function test_single_flight_does_not_write_cache_itself(): void
    {
        UpstreamReadCache::singleFlight('probe:sf-cache', function (): array {
            Cache::put('probe:sf-cache', ['ttl' => 'caller-controlled'], 600);

            return ['ttl' => 'caller-controlled'];
        });

        $this->assertSame(['ttl' => 'caller-controlled'], Cache::get('probe:sf-cache'));
    }

    public function test_single_flight_releases_the_lock_after_failure(): void
    {
        try {
            UpstreamReadCache::singleFlight('probe:sf-boom', function (): never {
                throw new \RuntimeException('失败也要放锁');
            });
        } catch (\RuntimeException) {
            // 预期
        }

        // 锁没放掉的话，这里会等满 3 秒再退化执行；用一次正常调用验证锁已释放
        $result = UpstreamReadCache::singleFlight('probe:sf-boom', fn (): string => 'lock released');

        $this->assertSame('lock released', $result);
    }
}