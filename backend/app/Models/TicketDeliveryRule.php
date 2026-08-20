<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TicketDeliveryRule extends Model
{
    protected $fillable = [
        'name', 'department', 'supplier_id', 'provider_key', 'product_scope_mode', 'upstream_department_id', 'enabled',
        'sync_admin_replies', 'auto_reply_enabled', 'auto_reply_content', 'mask_keywords',
    ];

    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'enabled' => 'boolean',
            'sync_admin_replies' => 'boolean',
            'auto_reply_enabled' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function appliesToProduct(?int $productId): bool
    {
        if ($this->product_scope_mode === 'all') {
            return true;
        }

        return $productId !== null && $this->products()->whereKey($productId)->exists();
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'ticket_delivery_rule_products', 'rule_id', 'product_id');
    }

    public function maskKeywordList(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $this->mask_keywords) ?: [])));
    }
}
