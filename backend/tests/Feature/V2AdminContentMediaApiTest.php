<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\ContentArticle;
use App\Models\ContentCategory;
use App\Models\MediaFile;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminContentMediaApiTest extends TestCase
{
    /** @var list<int> */
    private array $articleIds = [];

    /** @var list<int> */
    private array $categoryIds = [];

    /** @var list<int> */
    private array $mediaFileIds = [];

    /** @var list<string> */
    private array $diskPaths = [];

    protected function tearDown(): void
    {
        if ($this->articleIds !== []) {
            DB::table('content_articles')->whereIn('id', $this->articleIds)->delete();
        }

        if ($this->categoryIds !== []) {
            DB::table('content_categories')->whereIn('id', $this->categoryIds)->delete();
        }

        if ($this->mediaFileIds !== []) {
            DB::table('media_files')->whereIn('id', $this->mediaFileIds)->delete();
        }

        foreach ($this->diskPaths as $path) {
            if (@is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_admin_content_read_endpoints_use_v2_projection(): void
    {
        $fixture = $this->createContentFixture('read');

        $this->getJson('/api/v2/admin/content/summary')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $this->getJson('/api/v2/admin/content/summary')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::CONTENT_LIST]));

        $this->getJson('/api/v2/admin/content/articles?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/content/categories?type=notice')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['content_type', 'type']]]);

        $this->getJson('/api/v2/admin/content/articles?'.http_build_query([
            'content_type' => ContentArticle::TYPE_NOTICE,
            'content_category_id' => $fixture['category']->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['content_category_id']]]);

        $summaryResponse = $this->getJson('/api/v2/admin/content/summary')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.notices_total', fn (int $total): bool => $total >= 1);

        $this->assertSame($this->summaryKeys(), array_keys($summaryResponse->json('data')));
        $this->assertNoSensitiveKeys($summaryResponse->json());

        $category = $fixture['category'];
        $categoryPosition = ContentCategory::query()
            ->ofType(ContentArticle::TYPE_NOTICE)
            ->where(function ($query) use ($category): void {
                $query
                    ->where('sort_order', '<', (int) $category->sort_order)
                    ->orWhere(function ($sameOrder) use ($category): void {
                        $sameOrder
                            ->where('sort_order', (int) $category->sort_order)
                            ->where('id', '<=', (int) $category->id);
                    });
            })
            ->count();
        $categoryPage = max((int) ceil($categoryPosition / 10), 1);

        $categoryResponse = $this->getJson('/api/v2/admin/content/categories?'.http_build_query([
            'content_type' => ContentArticle::TYPE_NOTICE,
            'page' => $categoryPage,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonFragment(['slug' => $fixture['category']->slug])
            ->assertJsonMissingPath('data.list.0.type');

        $this->assertSame($this->pageKeys(), array_keys($categoryResponse->json('data')));
        $this->assertSame($this->categoryKeys(), array_keys($categoryResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($categoryResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $categoryResponse->getContent()));

        $listResponse = $this->getJson('/api/v2/admin/content/articles?'.http_build_query([
            'content_type' => ContentArticle::TYPE_NOTICE,
            'keyword' => $fixture['article']->title,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $fixture['article']->id)
            ->assertJsonMissingPath('data.list.0.content')
            ->assertJsonMissingPath('data.list.0.type')
            ->assertJsonMissingPath('data.list.0.content_category_id')
            ->assertJsonMissingPath('data.list.0.trace_id')
            ->assertJsonMissingPath('data.list.0.creator')
            ->assertJsonMissingPath('data.list.0.updater');

        $this->assertSame($this->pageKeys(), array_keys($listResponse->json('data')));
        $this->assertSame($this->articleListKeys(), array_keys($listResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($listResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $listResponse->getContent()));

        $detailResponse = $this->getJson('/api/v2/admin/content/articles/'.$fixture['article']->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $fixture['article']->id)
            ->assertJsonPath('data.content', $fixture['article']->content)
            ->assertJsonMissingPath('data.trace_id')
            ->assertJsonMissingPath('data.creator')
            ->assertJsonMissingPath('data.updater');

        $this->assertSame($this->articleDetailKeys(), array_keys($detailResponse->json('data')));
        $this->assertNoSensitiveKeys($detailResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $detailResponse->getContent()));
    }

    public function test_admin_content_write_endpoints_use_v2_validation_and_resources(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::CONTENT_LIST]));

        $this->postJson('/api/v2/admin/content/categories', [
            'content_type' => ContentArticle::TYPE_NOTICE,
            'name' => 'forbidden',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::CONTENT_MANAGE]));

        $this->postJson('/api/v2/admin/content/categories', [
            'type' => ContentArticle::TYPE_NOTICE,
            'name' => 'legacy alias',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['content_type', 'type']]]);

        $suffix = 'write-'.bin2hex(random_bytes(4));
        $categoryResponse = $this->postJson('/api/v2/admin/content/categories', [
            'content_type' => ContentArticle::TYPE_NOTICE,
            'name' => 'V2 Category '.$suffix,
            'slug' => 'v2-category-'.$suffix,
            'description' => 'V2 category description',
            'status' => 1,
            'sort_order' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.content_type', ContentArticle::TYPE_NOTICE)
            ->assertJsonMissingPath('data.type');

        $categoryId = (int) $categoryResponse->json('data.id');
        $this->categoryIds[] = $categoryId;
        $this->assertSame($this->categoryKeys(), array_keys($categoryResponse->json('data')));

        $this->postJson('/api/v2/admin/content/articles', [
            'content_type' => ContentArticle::TYPE_NOTICE,
            'content_category_id' => $categoryId,
            'title' => 'legacy article alias',
            'content' => 'body',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['category_id', 'content_category_id']]]);

        $articleResponse = $this->postJson('/api/v2/admin/content/articles', $this->articlePayload($categoryId, $suffix))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.content_type', ContentArticle::TYPE_NOTICE)
            ->assertJsonPath('data.content', 'V2 article body '.$suffix)
            ->assertJsonMissingPath('data.trace_id');

        $articleId = (int) $articleResponse->json('data.id');
        $this->articleIds[] = $articleId;
        $this->assertSame($this->articleDetailKeys(), array_keys($articleResponse->json('data')));
        $this->assertNoSensitiveKeys($articleResponse->json());

        $updatedResponse = $this->putJson('/api/v2/admin/content/articles/'.$articleId, array_merge(
            $this->articlePayload($categoryId, $suffix),
            ['title' => 'Updated '.$suffix, 'require_reread' => true]
        ))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.title', 'Updated '.$suffix);

        $this->assertSame($this->articleDetailKeys(), array_keys($updatedResponse->json('data')));

        $this->deleteJson('/api/v2/admin/content/articles/'.$articleId)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data', null);

        $this->deleteJson('/api/v2/admin/content/categories/'.$categoryId)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data', null);
    }

    public function test_admin_media_file_endpoints_use_v2_projection(): void
    {
        $suffix = 'media-'.bin2hex(random_bytes(4));
        $image = $this->createMediaFile($suffix, 'image/jpeg', 'content');
        $video = $this->createMediaFile($suffix, 'video/mp4', 'hero-videos');

        Sanctum::actingAs($this->createAdmin([AdminPermissions::CONTENT_LIST]));

        $this->getJson('/api/v2/admin/media-files?per_page=24')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->getJson('/api/v2/admin/media-files?'.http_build_query([
            'keyword' => $suffix,
            'type' => 'video',
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonFragment(['id' => $video->id])
            ->assertJsonMissing(['id' => $image->id])
            ->assertJsonMissingPath('data.list.0.uploaded_by')
            ->assertJsonMissingPath('data.list.0.uploader');

        $this->assertSame($this->pageKeys(), array_keys($response->json('data')));
        $this->assertSame($this->mediaKeys(), array_keys($response->json('data.list.0')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));

        Sanctum::actingAs($this->createAdmin([AdminPermissions::CONTENT_MANAGE]));

        $uploadResponse = $this->post('/api/v2/admin/media-files', [
            'file' => UploadedFile::fake()->image('v2-upload-'.$suffix.'.jpg', 1, 1),
            'group' => 'content',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.type', 'image')
            ->assertJsonMissingPath('data.uploaded_by');

        $uploadedId = (int) $uploadResponse->json('data.id');
        $this->mediaFileIds[] = $uploadedId;
        $uploadedPath = public_path(ltrim((string) $uploadResponse->json('data.path'), '/'));
        $this->diskPaths[] = $uploadedPath;
        $this->assertSame($this->mediaKeys(), array_keys($uploadResponse->json('data')));

        $this->deleteJson('/api/v2/admin/media-files/'.$uploadedId)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data', null);
    }

    /**
     * @return array{category: ContentCategory, article: ContentArticle}
     */
    private function createContentFixture(string $prefix): array
    {
        $suffix = $prefix.'-'.bin2hex(random_bytes(4));
        $category = ContentCategory::query()->create([
            'content_type' => ContentArticle::TYPE_NOTICE,
            'name' => 'V2 Content Category '.$suffix,
            'slug' => 'v2-content-category-'.$suffix,
            'description' => 'V2 content category',
            'status' => ContentCategory::STATUS_ENABLED,
            'sort_order' => 1,
        ]);
        $this->categoryIds[] = (int) $category->id;

        $article = ContentArticle::query()->create([
            'content_type' => ContentArticle::TYPE_NOTICE,
            'category_id' => (int) $category->id,
            'title' => 'V2 Content Article '.$suffix,
            'slug' => 'v2-content-article-'.$suffix,
            'summary' => 'V2 content summary '.$suffix,
            'content' => 'V2 content body '.str_repeat($suffix.' ', 80),
            'category_name' => (string) $category->name,
            'keywords' => 'keyword-'.$suffix,
            'cover_image' => null,
            'status' => ContentArticle::STATUS_PUBLISHED,
            'is_pinned' => 1,
            'is_recommended' => 0,
            'sort_order' => 1,
            'view_count' => 3,
            'publish_at' => now()->subMinute(),
            'last_published_at' => now()->subMinute(),
            'operator' => 'admin#v2',
            'remark' => 'internal '.$suffix,
            'trace_id' => 'trace-'.$suffix,
        ]);
        $this->articleIds[] = (int) $article->id;

        return ['category' => $category, 'article' => $article];
    }

    /**
     * @return array<string, mixed>
     */
    private function articlePayload(int $categoryId, string $suffix): array
    {
        return [
            'content_type' => ContentArticle::TYPE_NOTICE,
            'category_id' => $categoryId,
            'title' => 'V2 Article '.$suffix,
            'slug' => 'v2-article-'.$suffix,
            'summary' => 'V2 article summary '.$suffix,
            'content' => 'V2 article body '.$suffix,
            'keywords' => 'v2,content',
            'status' => ContentArticle::STATUS_PUBLISHED,
            'is_pinned' => 0,
            'is_recommended' => 1,
            'cover_image' => null,
            'sort_order' => 2,
            'publish_at' => now()->format('Y-m-d H:i:s'),
            'operator' => 'admin#v2',
            'remark' => 'remark '.$suffix,
        ];
    }

    private function createMediaFile(string $suffix, string $mimeType, string $group): MediaFile
    {
        $extension = str_starts_with($mimeType, 'video/') ? 'mp4' : 'jpg';
        $mediaFile = MediaFile::query()->create([
            'filename' => $suffix.'-'.$group.'.'.$extension,
            'path' => '/media/'.$suffix.'-'.$group.'.'.$extension,
            'url' => '/media/'.$suffix.'-'.$group.'.'.$extension,
            'mime_type' => $mimeType,
            'size' => 1024,
            'width' => str_starts_with($mimeType, 'image/') ? 1 : null,
            'height' => str_starts_with($mimeType, 'image/') ? 1 : null,
            'group' => $group,
            'uploaded_by' => 1,
        ]);
        $this->mediaFileIds[] = (int) $mediaFile->id;

        return $mediaFile;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-content-media-'.$suffix,
            'label' => 'V2 Content Media',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-content-media-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Content Media',
            'email' => 'v2-content-media-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    private function summaryKeys(): array
    {
        return ['notices_total', 'helps_total', 'published_total', 'draft_total', 'pinned_total'];
    }

    /**
     * @return list<string>
     */
    private function pageKeys(): array
    {
        return ['list', 'total', 'page', 'page_size'];
    }

    /**
     * @return list<string>
     */
    private function categoryKeys(): array
    {
        return [
            'id',
            'content_type',
            'name',
            'slug',
            'description',
            'status',
            'sort_order',
            'articles_count',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function articleListKeys(): array
    {
        return [
            'id',
            'content_type',
            'category_id',
            'category_name',
            'content_category',
            'title',
            'slug',
            'summary',
            'excerpt',
            'cover_image',
            'status',
            'status_label',
            'is_pinned',
            'is_recommended',
            'sort_order',
            'view_count',
            'publish_at',
            'last_published_at',
            'operator',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function articleDetailKeys(): array
    {
        return array_merge($this->articleListKeys(), ['content', 'keywords', 'remark']);
    }

    /**
     * @return list<string>
     */
    private function mediaKeys(): array
    {
        return [
            'id',
            'filename',
            'path',
            'url',
            'mime_type',
            'size',
            'width',
            'height',
            'group',
            'type',
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
