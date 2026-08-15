<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContentArticle;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M2 — ContentArticle 软删除时 slug 后缀逻辑测试
 *
 * 不使用 RefreshDatabase（测试直连 idc，不可擦库）。
 * 每个测试用唯一前缀 slug，在 tearDown 中清理测试行。
 */
class ContentArticleSlugSoftDeleteTest extends TestCase
{
    /** @var int[] 测试过程中插入的文章 ID，tearDown 时清理 */
    private array $createdIds = [];

    protected function tearDown(): void
    {
        if ($this->createdIds !== []) {
            // 强制物理删除，包含软删除行
            DB::table('content_articles')
                ->whereIn('id', $this->createdIds)
                ->delete();
        }
        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────────────────

    public function test_soft_delete_appends_deleted_suffix_to_slug(): void
    {
        $article = $this->makeArticle('slug-softdel-append-test');

        $originalId = $article->id;
        $originalSlug = 'slug-softdel-append-test';

        $article->delete();
        $article->refresh();

        $this->assertSame(
            $originalSlug.'_deleted_'.$originalId,
            $article->slug,
            '软删除后 slug 应带 _deleted_{id} 后缀'
        );
        $this->assertNotNull($article->deleted_at, '软删除后 deleted_at 应非空');
    }

    public function test_restore_removes_deleted_suffix_from_slug(): void
    {
        $article = $this->makeArticle('slug-softdel-restore-test');
        $originalSlug = 'slug-softdel-restore-test';

        $article->delete();
        $article->refresh();
        $this->assertStringContainsString('_deleted_', (string) $article->slug, '软删除后 slug 含后缀');

        $article->restore();
        $article->refresh();

        $this->assertSame($originalSlug, $article->slug, '恢复后 slug 应还原为原始值');
        $this->assertNull($article->deleted_at, '恢复后 deleted_at 应为 NULL');
    }

    public function test_same_slug_can_be_used_after_soft_delete(): void
    {
        $sharedSlug = 'slug-softdel-reuse-test';

        $first = $this->makeArticle($sharedSlug);
        $first->delete();

        // 软删除后同 slug 可再次创建，不应触发唯一约束冲突
        $second = $this->makeArticle($sharedSlug);

        $this->assertDatabaseHas('content_articles', [
            'id' => $second->id,
            'slug' => $sharedSlug,
        ]);
    }

    // ── 工具方法 ──────────────────────────────────────────────────────────

    /**
     * 在 content_articles 表中插入一条测试文章，并记录 ID 供 tearDown 清理。
     */
    private function makeArticle(string $slug): ContentArticle
    {
        $article = ContentArticle::query()->forceCreate([
            'content_type' => ContentArticle::TYPE_NOTICE,
            'category_id' => null,
            'title' => 'Test Article '.$slug,
            'slug' => $slug,
            'content' => 'Test content for '.$slug,
            'status' => ContentArticle::STATUS_DRAFT,
            'is_pinned' => 0,
            'is_recommended' => 0,
            'sort_order' => 0,
            'view_count' => 0,
        ]);

        $this->createdIds[] = (int) $article->id;

        return $article;
    }
}
