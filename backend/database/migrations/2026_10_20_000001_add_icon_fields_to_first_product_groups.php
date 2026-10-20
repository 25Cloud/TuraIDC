<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 一级分组自定义图标：文字图标 + 图片图标。
 *
 * 设计口径（与既有 `icon` 列配合，不另起一套模式开关）：
 * - `icon`（已有列，varchar(50)）：内置图标名，系统分组存 TDesign 图标名（Platform/Gamepad/...）；
 * - `icon_text`（新增）：文字图标，如「轻量」「游戏」；
 * - `icon_image`（新增）：图片图标，存站内相对路径（如 /media/xxx.png），出参时经
 *   App\Support\UploadUrl 解析成可访问绝对地址。
 *
 * 展示优先级由 App\Support\ProductGroupIcon 统一解析：
 * icon_image → icon_text → icon（内置图标名）→ 一级分组名自动缩写（半角宽度最多 4 单位）。
 * 因此不需要 icon_type：优先级在每次解析时按字段是否为空推断。
 *
 * 迁移只新增列、不改旧列，也不写任何存量数据；存量系统分组的 icon 保持不动，
 * 自发分组 icon 为空时会自动走名称缩写，无需回填。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('first_product_groups')) {
            return;
        }

        Schema::table('first_product_groups', function (Blueprint $table): void {
            if (! Schema::hasColumn('first_product_groups', 'icon_text')) {
                $table->string('icon_text', 16)
                    ->nullable()
                    ->after('icon')
                    ->comment('文字图标：如「轻量」「游戏」，留空时按优先级回退到 icon 或分组名缩写');
            }

            if (! Schema::hasColumn('first_product_groups', 'icon_image')) {
                $table->string('icon_image', 255)
                    ->nullable()
                    ->after('icon_text')
                    ->comment('图片图标地址：存站内相对路径 /media/xxx.png，出参时解析为绝对地址');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('first_product_groups')) {
            return;
        }

        Schema::table('first_product_groups', function (Blueprint $table): void {
            foreach (['icon_image', 'icon_text'] as $column) {
                if (Schema::hasColumn('first_product_groups', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
