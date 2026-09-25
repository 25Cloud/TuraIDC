<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Models\Service;
use App\Models\User;
use App\Models\ZjmfUpstreamBinding;
use App\Services\ClientServiceConsole\ServiceConsoleAreaService;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Support\UploadedImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 上游推送/透传接口（被魔方财务对接）：
 *   - api/ticket_reply/sync：下游工单回复推送，用绑定表 downstream_token 验签
 *   - upload_image：multipart 上传（字段 file），返回 savename
 *   - provision/custom/{id}：自定义面板动作，转发给供应商（无供应商时幂等受理）
 */
class PushService
{
    public function __construct(
        private readonly ?ServiceConsoleAreaService $consoleArea = null,
    ) {}

    /**
     * 自定义面板动作：中间层转发给供应商；未接入可控供应商时幂等受理，
     * 避免下游因单点失败卡住（对齐魔方财务中间层的降级行为）。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function provisionCustom(User $user, int $serviceId, array $data): array
    {
        $service = Service::query()
            ->where('user_id', (int) $user->id)
            ->find($serviceId);

        if (! $service instanceof Service) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }

        unset($data['id']);
        $result = $this->consoleArea()->proxyModuleAction($service, $data);
        if (is_array($result)) {
            return $result;
        }

        return [
            'status' => 200,
            'msg' => '操作成功',
            'data' => ['id' => $serviceId],
        ];
    }

    /**
     * 图表数据（魔方财务 GET /provision/chart/{id}，中间层转发供应商）。
     *
     * 魔方前端按 data.status==200 且 data.data.list 渲染；服务未接入可控
     * 供应商或上游失败时返回空列表，下游渲染空图表而不是报错卡死。
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function chart(User $user, int $serviceId, array $query): array
    {
        $service = Service::query()
            ->where('user_id', (int) $user->id)
            ->find($serviceId);

        if (! $service instanceof Service) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }

        $empty = ['status' => 200, 'msg' => '请求成功', 'data' => ['list' => []]];

        try {
            if (! app(ServiceTransformService::class)->canExecuteConsoleActions($service)) {
                return $empty;
            }

            $detail = app(ServiceDetailService::class);
            [$runtime, $supplier, $hostId, $jwt] = $detail->resolveUpstreamContext($service);
            $rootUrl = rtrim($detail->resolveSupplierRootUrl($supplier), '/');
            $response = $runtime->get($supplier, $rootUrl.'/provision/chart/'.$hostId, $jwt, $query);

            if ((int) ($response['status'] ?? 0) !== 200) {
                return $empty;
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];

            return [
                'status' => 200,
                'msg' => '请求成功',
                'data' => ['list' => is_array($data['list'] ?? null) ? array_values($data['list']) : []],
            ];
        } catch (\Throwable $exception) {
            Log::info('[zjmf-upstream] 图表数据透传失败，返回空列表', [
                'service_id' => $serviceId,
                'message' => $exception->getMessage(),
            ]);

            return $empty;
        }
    }

    private function consoleArea(): ServiceConsoleAreaService
    {
        return $this->consoleArea ?? app(ServiceConsoleAreaService::class);
    }

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
        if (! $file->isValid()) {
            return ['status' => 400, 'msg' => '文件上传失败'];
        }

        // 按 MIME 嗅探取扩展名并走白名单（与工单附件同基线），客户端扩展名不可信。
        try {
            $extension = UploadedImage::extension($file);
        } catch (\Throwable) {
            return ['status' => 400, 'msg' => '仅支持 JPG、PNG、WEBP 图片'];
        }

        if ((int) $file->getSize() > 5 * 1024 * 1024) {
            return ['status' => 400, 'msg' => '图片大小不能超过 5MB'];
        }

        $directoryPath = 'zjmf/'.now()->format('Ym');
        $directory = storage_path('app/private/uploads/'.$directoryPath);
        File::ensureDirectoryExists($directory);

        $filename = Str::lower(Str::random(16)).'.'.$extension;
        $file->move($directory, $filename);

        $savename = 'uploads/'.$directoryPath.'/'.$filename;

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
