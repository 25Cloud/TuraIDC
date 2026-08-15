<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContentArticle;
use App\Models\User;
use App\Services\User\UserService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SoftDeleteUniqueKeyReleaseTest extends TestCase
{
    public function test_soft_deleted_user_email_and_phone_unique_keys_are_released(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $email = "reuse-{$suffix}@example.com";
        $phone = '139'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

        $userA = app(UserService::class)->create([
            'email' => $email,
            'password' => 'secret123',
            'phone' => $phone,
            'status' => 1,
        ]);

        $userId = (int) $userA->id;
        $userA->delete();

        // 软删后唯一列被改写为释放占位值（应用层语义：软删后可复用）。
        $this->assertSame("del-email-{$userId}", (string) DB::table('users')->where('id', $userId)->value('email'));
        $this->assertStringStartsWith('del-phone-', (string) DB::table('users')->where('id', $userId)->value('phone'));

        // 同 email 可重新注册（DB 唯一键不再被软删行占用）。
        $userB = app(UserService::class)->create([
            'email' => $email,
            'password' => 'secret123',
            'phone' => '137'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $this->assertNotSame($userId, (int) $userB->id);
    }

    public function test_soft_deleted_article_slug_unique_key_is_released(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $slug = 'reuse-slug-'.$suffix;

        $articleA = ContentArticle::query()->create([
            'content_type' => 'help',
            'title' => '待复用文章',
            'slug' => $slug,
            'content' => '<p>content</p>',
            'status' => ContentArticle::STATUS_PUBLISHED,
        ]);

        $articleId = (int) $articleA->id;
        $articleA->delete();

        // 软删后 slug 被改写为 {slug}_{hash}_deleted_{id}，唯一键释放（既有 M2 机制）。
        $releasedSlug = (string) DB::table('content_articles')->where('id', $articleId)->value('slug');
        $this->assertStringEndsWith("_deleted_{$articleId}", $releasedSlug);
        $this->assertNotSame($slug, $releasedSlug);

        // 同 slug 可重新创建文章。
        $articleB = ContentArticle::query()->create([
            'content_type' => 'notice',
            'title' => '复用文章',
            'slug' => $slug,
            'content' => '<p>new</p>',
            'status' => ContentArticle::STATUS_PUBLISHED,
        ]);

        $this->assertNotSame($articleId, (int) $articleB->id);
    }

    public function test_physical_delete_does_not_rewrite_unique_keys(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => "force-delete-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '135'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $email = (string) $user->email;
        $user->forceDelete();

        $this->assertSame(0, (int) User::query()->withTrashed()->where('id', (int) $user->id)->count());
        $this->assertSame($email, "force-delete-{$suffix}@example.com");
    }
}
