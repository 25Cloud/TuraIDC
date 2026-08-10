<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContentArticle extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_NOTICE = 'notice';

    public const TYPE_HELP = 'help';

    public const STATUS_DRAFT = 0;

    public const STATUS_PUBLISHED = 1;

    public const STATUS_OFFLINE = 2;

    public const TYPE_LABELS = [
        self::TYPE_NOTICE => '公告',
        self::TYPE_HELP => '帮助',
    ];

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_PUBLISHED => '已发布',
        self::STATUS_OFFLINE => '已下线',
    ];

    public const NODE_TYPE_ARTICLE = 'article';

    public const NODE_TYPE_CATEGORY = 'category';

    protected $fillable = [
        'content_type',
        'type',
        'category_id',
        'content_category_id',
        'title',
        'slug',
        'summary',
        'content',
        'category_name',
        'category',
        'keywords',
        'cover_image',
        'status',
        'is_pinned',
        'is_recommended',
        'sort_order',
        'view_count',
        'publish_at',
        'last_published_at',
        'created_by',
        'updated_by',
        'operator',
        'remark',
        'trace_id',
        'require_reread_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'category_id' => 'integer',
            'is_pinned' => 'integer',
            'is_recommended' => 'integer',
            'sort_order' => 'integer',
            'view_count' => 'integer',
            'publish_at' => 'datetime',
            'last_published_at' => 'datetime',
            'require_reread_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // M2 修复：软删除前将 slug 改为 {slug}_deleted_{id}，释放唯一约束占用
        // 使 deleted_at IS NOT NULL 的行不再占用原始 slug，允许同 slug 新文章创建
        static::deleting(function (self $article): void {
            if ($article->isForceDeleting()) {
                return; // 物理删除无需处理
            }

            $originalSlug = $article->slug;
            if ($originalSlug === null || $originalSlug === '') {
                return;
            }

            // 避免重复后缀（幂等）
            $suffix = '_deleted_'.$article->getKey();
            if (str_ends_with($originalSlug, $suffix)) {
                return;
            }

            $article->slug = $originalSlug.$suffix;
            $article->saveQuietly(); // 不触发事件循环
        });

        // 恢复（restore）时清理后缀，恢复原始 slug
        static::restoring(function (self $article): void {
            $suffix = '_deleted_'.$article->getKey();
            if (str_ends_with((string) $article->slug, $suffix)) {
                $article->slug = substr($article->slug, 0, -strlen($suffix));
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function contentCategory(): BelongsTo
    {
        return $this->belongsTo(ContentCategory::class, 'category_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('content_type', $type);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where(function (Builder $builder) {
                $builder
                    ->whereNull('publish_at')
                    ->orWhere('publish_at', '<=', now());
            });
    }

    public static function typeLabelOf(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    public static function statusLabelOf(int $status): string
    {
        return self::STATUS_LABELS[$status] ?? (string) $status;
    }

    public function getTypeAttribute(): ?string
    {
        return $this->attributes['content_type'] ?? null;
    }

    public function setTypeAttribute(?string $value): void
    {
        $this->attributes['content_type'] = $value;
    }

    public function getContentCategoryIdAttribute(): ?int
    {
        $value = $this->attributes['category_id'] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function setContentCategoryIdAttribute($value): void
    {
        $this->attributes['category_id'] = $value;
    }

    public function getCategoryAttribute(): ?string
    {
        return $this->attributes['category_name'] ?? null;
    }

    public function setCategoryAttribute(?string $value): void
    {
        $this->attributes['category_name'] = $value;
    }
}
