<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentGroup extends Model
{
    protected $fillable = [
        'name', 'code', 'status', 'default_discount_rate', 'sort_order', 'remark',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'sort_order' => 'integer',
            'default_discount_rate' => 'float',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * 不带泛型时 Larastan 只能推断成基类 Model，AgentDiscountService 读取 discounts
     * 会被判为未定义属性/方法，只能靠 PHPStan 基线压住——关系一旦改错，
     * 静态检查发现不了，要等结算链路运行时才暴露。
     *
     * @return HasMany<AgentGroupDiscount, $this>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(AgentGroupDiscount::class);
    }
}
