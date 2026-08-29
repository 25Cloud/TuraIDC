<?php

namespace App\Http\Controllers;

use App\Http\Requests\Site\ShowSecureAssetRequest;
use App\Support\MediaAssetTypes;
use App\Support\SecureAsset;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class SecureAssetController extends Controller
{
    public function show(ShowSecureAssetRequest $request)
    {
        $data = $request->validated();

        try {
            $path = SecureAsset::normalizePath((string) $data['path']);
            $absolutePath = SecureAsset::absolutePath($path);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_unless(File::exists($absolutePath), 404);

        // 用白名单而不是 `image/` 前缀：前缀会放行 image/svg+xml，而 SVG 是可内嵌
        // <script> 与事件属性的 XML 文档，以下面的 inline 方式返回时浏览器会把它当文档
        // 渲染并在本站源执行脚本，nosniff 拦不住（MIME 本就是 image/svg+xml，不涉及嗅探）。
        abort_unless(MediaAssetTypes::isAllowedImageMimeType(File::mimeType($absolutePath) ?: ''), 404);

        return response()->file($absolutePath, [
            'Cache-Control' => 'private, max-age=300, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.basename($absolutePath).'"',
        ]);
    }
}
