<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContentArticle;
use App\Models\ContentCategory;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2ContentNotificationApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_site_content_lists_details_and_overview_use_v2_projection(): void
    {
        $fixture = $this->createContentFixture('site');

        $this->getJson('/api/v2/site/notices?per_page=10')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/site/notices?pageSize=10')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $listResponse = $this->getJson('/api/v2/site/notices?'.http_build_query([
            'keyword' => $fixture['notice']->title,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $fixture['notice']->id)
            ->assertJsonPath('data.list.0.excerpt', $fixture['notice']->summary)
            ->assertJsonFragment(['slug' => $fixture['notice_category']->slug])
            ->assertJsonMissingPath('data.list.0.content')
            ->assertJsonMissingPath('data.list.0.trace_id')
            ->assertJsonMissingPath('data.list.0.creator')
            ->assertJsonMissingPath('data.list.0.updated_by');

        $this->assertSame($this->contentPageWhitelist(), array_keys($listResponse->json('data')));
        $this->assertSame($this->contentSummaryWhitelist(), array_keys($listResponse->json('data.list.0')));
        $this->assertSame($this->contentCategoryWhitelist(), array_keys($listResponse->json('data.categories.0')));
        $this->assertNoSensitiveKeys($listResponse->json());
        $this->assertLessThan(50 * 1024, strlen((string) $listResponse->getContent()));

        $this->getJson('/api/v2/site/notices/'.$fixture['notice']->id.'?page=1')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);

        $detailResponse = $this->getJson('/api/v2/site/notices/'.$fixture['notice']->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.article.id', $fixture['notice']->id)
            ->assertJsonPath('data.article.content', $fixture['notice']->content)
            ->assertJsonMissingPath('data.article.trace_id')
            ->assertJsonMissingPath('data.article.creator');

        $this->assertSame($this->contentDetailWhitelist(), array_keys($detailResponse->json('data.article')));
        $this->assertNoSensitiveKeys($detailResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $detailResponse->getContent()));

        $this->getJson('/api/v2/site/content/overview?per_page=10')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $overviewResponse = $this->getJson('/api/v2/site/content/overview')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonFragment(['id' => $fixture['notice']->id])
            ->assertJsonFragment(['id' => $fixture['help']->id])
            ->assertJsonMissingPath('data.notices.0.content')
            ->assertJsonMissingPath('data.help_articles.0.content');

        $this->assertSame($this->contentOverviewWhitelist(), array_keys($overviewResponse->json('data')));
        $this->assertNoSensitiveKeys($overviewResponse->json());
        $this->assertLessThan(50 * 1024, strlen((string) $overviewResponse->getContent()));
    }

    public function test_client_content_requires_auth_and_marks_notice_read(): void
    {
        $fixture = $this->createContentFixture('client');
        $user = $this->createClientUser('content-owner');

        $this->getJson('/api/v2/client/notices')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/help-articles?per_page=10')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/client/help-articles?pageSize=10')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $listResponse = $this->getJson('/api/v2/client/help-articles?'.http_build_query([
            'keyword' => $fixture['help']->title,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $fixture['help']->id)
            ->assertJsonMissingPath('data.list.0.content');

        $this->assertSame($this->contentPageWhitelist(), array_keys($listResponse->json('data')));
        $this->assertSame($this->contentSummaryWhitelist(), array_keys($listResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($listResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $listResponse->getContent()));

        $this->getJson('/api/v2/client/content/overview?page=1')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);

        $this->getJson('/api/v2/client/notices/'.$fixture['notice']->id.'?page_size=10')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page_size']]]);

        $this->getJson('/api/v2/client/notices/'.$fixture['notice']->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.article.id', $fixture['notice']->id)
            ->assertJsonPath('data.article.content', $fixture['notice']->content);

        $this->assertDatabaseHas('notice_reads', [
            'user_id' => $user->id,
            'article_id' => $fixture['notice']->id,
        ]);
    }

    public function test_client_notifications_are_owner_scoped_paginated_and_whitelisted(): void
    {
        $fixture = $this->createContentFixture('notifications');
        $owner = $this->createClientUser('notification-owner');
        $other = $this->createClientUser('notification-other');

        $ownerMessage = UserNotification::query()->create([
            'user_id' => (int) $owner->id,
            'type' => 'service',
            'title' => 'Owner notification '.$fixture['suffix'],
            'content' => 'Owner message summary',
            'link' => '/client/services/1',
            'data' => [
                'password' => 'must-not-leak-by-key',
                'api_key' => 'must-not-leak-by-key',
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        UserNotification::query()->create([
            'user_id' => (int) $other->id,
            'type' => 'service',
            'title' => 'Other notification '.$fixture['suffix'],
            'content' => 'Other message summary',
            'link' => '/client/services/2',
            'created_at' => now()->addSecond(),
            'updated_at' => now()->addSecond(),
        ]);

        $this->getJson('/api/v2/client/notifications')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v2/client/notifications?per_page=10')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/client/notifications?pageSize=10')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $response = $this->getJson('/api/v2/client/notifications?'.http_build_query([
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonFragment(['id' => 'msg-'.$ownerMessage->id])
            ->assertJsonFragment(['id' => 'notice-'.$fixture['notice']->id])
            ->assertJsonMissing(['title' => 'Other notification '.$fixture['suffix']])
            ->assertJsonMissingPath('data.list.0.data')
            ->assertJsonMissingPath('data.list.0.content');

        $this->assertGreaterThanOrEqual(2, (int) $response->json('data.total'));
        $this->assertSame($this->notificationPageWhitelist(), array_keys($response->json('data')));
        $this->assertSame($this->notificationItemWhitelist(), array_keys($response->json('data.list.0')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));

        $unreadOnlyResponse = $this->getJson('/api/v2/client/notifications?'.http_build_query([
            'unread_only' => 1,
            'page' => 1,
            'page_size' => 1,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.page_size', 1);

        $this->assertLessThanOrEqual(1, count($unreadOnlyResponse->json('data.list')));
        $this->assertNoSensitiveKeys($unreadOnlyResponse->json());

        $this->getJson('/api/v2/client/notifications/feed?per_page=10')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/client/notifications/unread-count?page=1')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);
    }

    /**
     * @return array{
     *     suffix: string,
     *     notice_category: ContentCategory,
     *     help_category: ContentCategory,
     *     notice: ContentArticle,
     *     help: ContentArticle
     * }
     */
    private function createContentFixture(string $prefix): array
    {
        $suffix = $prefix.'-'.bin2hex(random_bytes(4));
        $noticeCategory = $this->createCategory(ContentArticle::TYPE_NOTICE, $suffix);
        $helpCategory = $this->createCategory(ContentArticle::TYPE_HELP, $suffix);

        $notice = $this->createArticle(ContentArticle::TYPE_NOTICE, $noticeCategory, $suffix);
        $help = $this->createArticle(ContentArticle::TYPE_HELP, $helpCategory, $suffix);

        Cache::flush();

        return [
            'suffix' => $suffix,
            'notice_category' => $noticeCategory,
            'help_category' => $helpCategory,
            'notice' => $notice,
            'help' => $help,
        ];
    }

    private function createCategory(string $type, string $suffix): ContentCategory
    {
        return ContentCategory::query()->create([
            'content_type' => $type,
            'name' => strtoupper($type).' Category '.$suffix,
            'slug' => $type.'-'.$suffix,
            'description' => 'Category summary '.$suffix,
            'status' => ContentCategory::STATUS_ENABLED,
            'sort_order' => 1,
        ]);
    }

    private function createArticle(string $type, ContentCategory $category, string $suffix): ContentArticle
    {
        return ContentArticle::query()->create([
            'content_type' => $type,
            'category_id' => (int) $category->id,
            'title' => strtoupper($type).' Article '.$suffix,
            'slug' => $type.'-article-'.$suffix,
            'summary' => strtoupper($type).' summary '.$suffix,
            'content' => strtoupper($type).' body '.str_repeat('paragraph ', 80),
            'category_name' => (string) $category->name,
            'keywords' => 'keyword-'.$suffix,
            'cover_image' => null,
            'status' => ContentArticle::STATUS_PUBLISHED,
            'is_pinned' => 1,
            'is_recommended' => 1,
            'sort_order' => 1,
            'view_count' => 0,
            'publish_at' => now()->subMinute(),
            'last_published_at' => now()->subMinute(),
            'created_by' => 999,
            'updated_by' => 999,
            'operator' => 'admin#999',
            'remark' => 'internal remark '.$suffix,
            'trace_id' => 'trace-'.$suffix,
        ]);
    }

    private function createClientUser(string $suffix): User
    {
        $random = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => 'v2-content-'.$suffix.'-'.$random.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'V2 Content '.$random,
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
            'balance' => '0.00',
        ]);
    }

    /**
     * @return list<string>
     */
    private function contentPageWhitelist(): array
    {
        return [
            'list',
            'total',
            'page',
            'page_size',
            'categories',
        ];
    }

    /**
     * @return list<string>
     */
    private function contentOverviewWhitelist(): array
    {
        return [
            'notices',
            'help_articles',
            'notice_categories',
            'help_categories',
        ];
    }

    /**
     * @return list<string>
     */
    private function contentSummaryWhitelist(): array
    {
        return [
            'id',
            'content_type',
            'type',
            'type_label',
            'category_id',
            'content_category_id',
            'title',
            'slug',
            'summary',
            'excerpt',
            'category_name',
            'category',
            'category_slug',
            'cover_image',
            'status',
            'status_label',
            'is_pinned',
            'is_recommended',
            'view_count',
            'publish_at',
            'last_published_at',
            'created_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function contentDetailWhitelist(): array
    {
        return array_merge($this->contentSummaryWhitelist(), [
            'content',
            'keywords',
            'updated_at',
        ]);
    }

    /**
     * @return list<string>
     */
    private function contentCategoryWhitelist(): array
    {
        return [
            'id',
            'content_type',
            'type',
            'name',
            'slug',
            'description',
            'articles_count',
        ];
    }

    /**
     * @return list<string>
     */
    private function notificationPageWhitelist(): array
    {
        return [
            'list',
            'total',
            'page',
            'page_size',
        ];
    }

    /**
     * @return list<string>
     */
    private function notificationItemWhitelist(): array
    {
        return [
            'id',
            'raw_id',
            'source',
            'type',
            'type_label',
            'title',
            'summary',
            'link',
            'read',
            'created_at',
        ];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response', 'trace_id'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
