<?php

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Verification\InitVerificationRequest;
use App\Http\Requests\Client\V2\Verification\QrcodeRequest;
use App\Http\Requests\Client\V2\Verification\StatusRequest;
use App\Services\Auth\VerificationService;
use App\Support\PublicUrl;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function __construct(private VerificationService $verificationService) {}

    /**
     * 初始化实名认证
     */
    public function init(InitVerificationRequest $request)
    {
        $user = $request->user();

        if ($user->is_verified) {
            return $this->error(40000, '您已完成实名认证');
        }

        $certType = $request->input('cert_type', 'IDENTITY_CARD');

        $result = $this->verificationService->startVerificationSession(
            $user,
            $request->input('realname'),
            $request->input('idcard'),
            $certType
        );

        return $this->success($result);
    }

    /**
     * 生成认证链接
     */
    public function qrcode(QrcodeRequest $request)
    {
        // validation handled by QrcodeRequest

        $user = $request->user();
        $certifyId = (string) $request->input('certify_id');

        $userCertifyId = trim((string) ($user->verification_certify_id ?? ''));
        if ($userCertifyId === '' || ! hash_equals($userCertifyId, $certifyId)) {
            return $this->error(40300, '认证会话与当前账户不匹配');
        }

        $result = $this->verificationService->generateQrCode(
            $certifyId
        );

        return $this->success($result);
    }

    public function close(QrcodeRequest $request)
    {
        $user = $request->user();
        $certifyId = (string) $request->input('certify_id');

        $userCertifyId = trim((string) ($user->verification_certify_id ?? ''));
        if ($userCertifyId === '' || ! hash_equals($userCertifyId, $certifyId)) {
            return $this->error(40300, '认证会话与当前账户不匹配');
        }

        $this->verificationService->closeQrCodeSession($certifyId);

        return $this->success([
            'certify_id' => $certifyId,
            'closed' => true,
        ], '认证会话已关闭');
    }

    public function scan(Request $request)
    {
        $certifyId = trim((string) $request->query('certify_id', ''));
        if ($certifyId === '') {
            return response($this->buildScanErrorHtml('缺少认证会话，请返回系统重新生成二维码。'), 422, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
        }

        try {
            $targetUrl = $this->verificationService->resolveQrCodeRedirectUrl($certifyId);

            return redirect()->away($targetUrl);
        } catch (\Throwable $exception) {
            report($exception);

            return response($this->buildScanErrorHtml('二维码已失效或认证链接生成失败，请返回系统重新刷新二维码。'), 410, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
        }
    }

    /**
     * 查询认证状态
     */
    public function status(StatusRequest $request)
    {
        // validation handled by StatusRequest

        $user = $request->user();
        $certifyId = (string) $request->input('certify_id');

        $userCertifyId = trim((string) ($user->verification_certify_id ?? ''));
        if ($userCertifyId === '' || ! hash_equals($userCertifyId, $certifyId)) {
            return $this->error(40300, '认证会话与当前账户不匹配');
        }

        $result = $this->verificationService->queryStatus($certifyId);
        $user = $this->verificationService->syncUserStatus($user, $result, $certifyId);

        $payload = array_merge($result, [
            'user_verification_status' => $user->verification_status,
            'is_verified' => (int) $user->is_verified,
            'can_restart' => ($result['status'] ?? null) === 2,
        ]);

        return $this->success($payload);
    }

    /**
     * 重新发起认证会话（认证失败后重试）
     */
    public function restart(Request $request)
    {
        $user = $request->user();

        if ($user->is_verified) {
            return $this->error(40000, '您已完成实名认证');
        }

        $result = $this->verificationService->restartVerificationSession($user);

        return $this->success($result);
    }

    /**
     * 获取费用配置
     */
    public function feeConfig()
    {
        return $this->success($this->verificationService->feeConfig());
    }

    /**
     * 认证完成回跳页
     */
    public function callback(Request $request)
    {
        $certifyId = (string) $request->input('certify_id', $request->input('order_no', ''));

        if ($certifyId === '') {
            return $this->success([
                'certify_id' => '',
                'status' => 4,
                'message' => '请返回前端页面继续查询认证结果',
            ], '认证回调已接收');
        }

        $user = $this->verificationService->findUserByCertifyId($certifyId);
        if (! $user) {
            \Log::warning('[实名认证回调] 未找到对应用户', ['certify_id' => $certifyId]);

            return $this->error(40400, '未找到对应的认证记录');
        }

        $result = $this->verificationService->queryStatus($certifyId);
        $this->verificationService->syncUserStatus($user, $result, $certifyId);

        \Log::info('[实名认证回调] 状态已更新', [
            'user_id' => $user->id,
            'certify_id' => $certifyId,
            'status' => $result['status'],
        ]);

        $payload = [
            'certify_id' => $certifyId,
            'status' => $result['status'],
            'message' => $result['msg'],
        ];

        if ($request->isMethod('get')) {
            return redirect()->away($this->buildFrontendVerificationResultUrl(
                $certifyId,
                (int) ($result['status'] ?? 4),
                (string) ($result['msg'] ?? '')
            ));
        }

        return $this->success($payload, '认证回调已接收');
    }

    private function buildFrontendVerificationResultUrl(string $certifyId, int $status, string $message): string
    {
        $query = http_build_query([
            'verification_callback' => 1,
            'certify_id' => $certifyId,
            'result_status' => $status,
            'result_message' => $message,
            't' => time(),
        ]);

        return PublicUrl::console('/client/verification?'.$query);
    }

    private function buildScanErrorHtml(string $message): string
    {
        $safeMessage = e($message);

        return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>实名认证二维码</title>
</head>
<body style="margin:0;padding:32px;background:#f5f7fb;color:#1f2937;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif;">
  <div style="max-width:520px;margin:0 auto;padding:24px;border:1px solid #e5eaf3;border-radius:16px;background:#ffffff;box-shadow:0 10px 30px rgba(15,23,42,0.06);">
    <h1 style="margin:0 0 12px;font-size:20px;">实名认证二维码不可用</h1>
    <p style="margin:0;font-size:14px;line-height:1.8;">{$safeMessage}</p>
  </div>
</body>
</html>
HTML;
    }
}
