<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\MofangCloud\Lib;

/**
 * 一个魔方云上游的连接凭据。
 *
 * 代际不同，account / secret 的含义也不同：
 * - 老版：account = 魔方云登录账号，secret = 登录密码；
 * - 新版：account = AppID，secret = AppSecret。
 *
 * 因此这里只保存「原样提交」的两个字符串，由具体客户端决定怎么用。
 *
 * 证书校验不在这里开关：插件一律按平台默认策略校验上游证书，
 * 不允许自带 CA 或关闭校验（仓库级契约，由插件架构测试反向锁定）。
 */
final class CloudCredentials
{
    public function __construct(
        public readonly int $supplierId,
        public readonly string $baseUrl,
        public readonly string $account,
        public readonly string $secret,
        public readonly CloudApiMode $mode,
        public readonly bool $agentAccount = false,
    ) {}

    public function withMode(CloudApiMode $mode): self
    {
        return new self(
            supplierId: $this->supplierId,
            baseUrl: $this->baseUrl,
            account: $this->account,
            secret: $this->secret,
            mode: $mode,
            agentAccount: $this->agentAccount,
        );
    }

    public function tokenCacheKey(): string
    {
        return 'mofang_cloud:access_token:'.$this->supplierId.':'.sha1(
            $this->baseUrl.'|'.$this->account.'|'.$this->mode->value
        );
    }
}
