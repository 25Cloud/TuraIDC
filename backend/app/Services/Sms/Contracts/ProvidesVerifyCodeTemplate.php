<?php

declare(strict_types=1);

namespace App\Services\Sms\Contracts;

/**
 * 可选能力：SMS 驱动提供验证码文案模板（按用途），
 * 供验证码发送在驱动侧解析文案，避免系统层硬编码特定服务商模板语法。
 */
interface ProvidesVerifyCodeTemplate
{
    public function verifyCodeTemplate(string $purpose): string;
}
