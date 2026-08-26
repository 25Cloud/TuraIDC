<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Models\ZjmfUpstreamBinding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 上游推送/透传接口（被魔方财务对接）：
 *   - api/ticket_reply/sync：下游工单回复推送，用绑定表 downstream_token 验签
 *   - upload_image：multipart 上传（字段 file），返回 savename
 *   - provision/custom/{id}：自定义模块操作透传（TuraIDC 无对应能力，幂等受理）
 */
class PushService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function ticketReplySync(array $data): array
    {
        $id = (int) ($data['id'] ?? 0);
        $randStr = (string) ($data['rand_str'] ?? '');
        $signature = (string) ($data['signature'] ?? '');

        if ($id <= 0 || $randStr === '' || $signature === '') {
            return ['status' => 400, 'msg' => '参数错误：缺少 id、rand_str 或 signature'];
        }

        $tokens = ZjmfUpstreamBinding::query()
            ->where('downstream_token', '!=', '')
            ->distinct()
            ->pluck('downstream_token')
            ->map(fn ($token) => (string) $token)
            ->all();

        $verified = false;
        foreach ($tokens as $token) {
            if ($this->verifySignature(['id' => $id], $token, $randStr, $signature)) {
                $verified = true;
                break;
            }
        }

        if (! $verified) {
            Log::warning('[zjmf-upstream] 工单回复推送验签失败', [
                'id' => $id,
                'rand_str' => $randStr,
            ]);

            return ['status' => 400, 'msg' => '签名校验失败'];
        }

        Log::info('[zjmf-upstream] 工单回复推送', [
            'id' => $id,
            'has_attachment' => ! empty($data['attachment']),
            'content_length' => mb_strlen((string) ($data['content'] ?? '')),
        ]);

        return ['status' => 200, 'msg' => '操作成功'];
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadImage(UploadedFile $file): array
    {
        $directoryPath = 'uploads/zjmf/'.now()->format('Ym');
        $directory = public_path(str_replace('/', DIRECTORY_SEPARATOR, $directoryPath));
        File::ensureDirectoryExists($directory);

        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $filename = Str::lower(Str::random(16)).'.'.$extension;
        $file->move($directory, $filename);

        $savename = $directoryPath.'/'.$filename;

        return ['status' => 200, 'msg' => '操作成功', 'savename' => $savename];
    }

    /**
     * 对齐魔方财务 createSign：strtoupper(md5(json_encode(ksort(params+token+rand_str, SORT_STRING))))。
     *
     * @param  array<string, mixed>  $params
     */
    private function verifySignature(array $params, string $token, string $randStr, string $signature): bool
    {
        $params['token'] = $token;
        $params['rand_str'] = $randStr;
        ksort($params, SORT_STRING);
        $expected = strtoupper(md5((string) json_encode($params)));

        return hash_equals($expected, $signature);
    }
}
