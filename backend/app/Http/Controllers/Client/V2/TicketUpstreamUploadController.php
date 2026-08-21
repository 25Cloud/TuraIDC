<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Support\UploadedImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class TicketUpstreamUploadController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $file = $request->file('file');
        if ($file === null || ! $file->isValid()) {
            return response()->json(['status' => 400, 'msg' => '文件上传失败'], 200);
        }

        try {
            $mimeType = UploadedImage::mimeType($file);
            $extension = UploadedImage::extension($file);
        } catch (\Throwable) {
            return response()->json(['status' => 400, 'msg' => '仅支持 JPG、PNG、WEBP 图片'], 200);
        }

        $size = (int) $file->getSize();
        if ($size > 5 * 1024 * 1024) {
            return response()->json(['status' => 400, 'msg' => '图片大小不能超过 5MB'], 200);
        }

        $directoryPath = 'private/tickets/upstream';
        $directory = storage_path('app/'.str_replace('/', DIRECTORY_SEPARATOR, $directoryPath));
        File::ensureDirectoryExists($directory);
        $filename = 'upstream-'.now()->format('YmdHis').'-'.Str::lower(Str::random(20)).'.'.$extension;
        try {
            $file->move($directory, $filename);
        } catch (\Throwable) {
            return response()->json(['status' => 400, 'msg' => '文件保存失败'], 200);
        }

        Log::info('上游工单附件上传成功', [
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size' => $size,
        ]);

        return response()->json([
            'code' => 0,
            'status' => 200,
            'msg' => '上传成功',
            'savename' => $filename,
            'mime_type' => $mimeType,
            'name' => $filename,
            'data' => [
                'savename' => $filename,
                'mime_type' => $mimeType,
                'name' => $filename,
            ],
        ]);
    }
}
