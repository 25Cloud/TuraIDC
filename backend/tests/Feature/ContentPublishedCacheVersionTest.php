<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ContentPublishedCacheVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContentPublishedCacheVersionTest extends TestCase
{
    public function test_content_published_cache_version_persists_after_runtime_cache_is_cleared(): void
    {
        $original = DB::table('settings')
            ->where('group_key', 'content')
            ->where('item_key', 'published_cache_version')
            ->first();

        Cache::forget('content:published:version');

        try {
            DB::table('settings')
                ->where('group_key', 'content')
                ->where('item_key', 'published_cache_version')
                ->delete();

            $this->assertSame(1, ContentPublishedCacheVersion::current());
            $this->assertSame(2, ContentPublishedCacheVersion::bump());

            $persistedValue = DB::table('settings')
                ->where('group_key', 'content')
                ->where('item_key', 'published_cache_version')
                ->value('item_value');

            $this->assertSame('2', (string) $persistedValue);

            Cache::forget('content:published:version');

            $this->assertSame(2, ContentPublishedCacheVersion::current());
        } finally {
            if ($original) {
                DB::table('settings')->updateOrInsert(
                    [
                        'group_key' => 'content',
                        'item_key' => 'published_cache_version',
                    ],
                    [
                        'item_value' => (string) ($original->item_value ?? '1'),
                    ]
                );
            } else {
                DB::table('settings')
                    ->where('group_key', 'content')
                    ->where('item_key', 'published_cache_version')
                    ->delete();
            }

            Cache::forget('content:published:version');
        }
    }
}
