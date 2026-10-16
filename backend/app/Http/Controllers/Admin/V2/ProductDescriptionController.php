<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductCatalog\ProductDescriptionService;
use Illuminate\Http\JsonResponse;

/**
 * 商品描述读取接口。
 *
 * 只读，不承担保存：描述是商品的普通字段，随商品表单一起提交
 * （StoreProductRequest::description → ProductAdminService），
 * 单独开一个写接口等于给同一份数据第二个真源。
 *
 * 存在的理由是「自动生成预览」：描述留空时前台展示的是规格维度自动生成的
 * <ul><li>键: 值</li></ul>，商家在后台必须能预览这段内容才知道留空长什么样。
 * 商品列表接口不逐个算这份预览（要跑一遍识别），因此在这里按需取。
 */
class ProductDescriptionController extends Controller
{
    public function __construct(
        private readonly ProductDescriptionService $descriptionService,
    ) {}

    public function show(Product $product): JsonResponse
    {
        $resolved = $this->descriptionService->resolve($product);
        $autoItems = $this->descriptionService->autoItems($product);

        return $this->success([
            'product_id' => (int) $product->id,
            // 库里存的原文（已消毒），供编辑器回填
            'description' => (string) ($product->description ?? ''),
            // 当前生效的描述与它的来源：manual=商家填写 / auto=自动生成 / empty=两者皆空
            'html' => $resolved['html'],
            'source' => $resolved['source'],
            // 留空时的自动生成结果
            'auto_html' => $this->descriptionService->renderList($autoItems),
            'auto_items' => $autoItems,
        ]);
    }
}