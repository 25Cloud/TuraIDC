<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 统一的缓存键命名辅助类。
 *
 * 所有缓存键应该通过此类生成，确保格式统一、可维护。
 * 注意：键名前缀与 config/cache.php 中的 CACHE_PREFIX（idc_cache_）是两个层面，
 * Laravel Cache 门面会自动在键前追加 CACHE_PREFIX，所以这里的键名无需再手动加前缀。
 */
final class CacheKey
{
    /**
     * 用户相关缓存。
     */
    public static function user(int $userId, string $suffix): string
    {
        return 'user:'.$userId.':'.$suffix;
    }

    /**
     * 余额统计缓存。
     */
    public static function userBalanceSummary(int $userId): string
    {
        return self::user($userId, 'balance_summary');
    }

    /**
     * 会员等级相关缓存。
     */
    public static function memberLevels(bool $enabledOnly): string
    {
        return 'member_levels:list:'.($enabledOnly ? 'enabled' : 'all');
    }

    /**
     * 首页 Hero 内容缓存。
     */
    public static function homeHero(): string
    {
        return 'site:home:hero';
    }

    /**
     * 内容发布版本缓存。
     */
    public static function contentPublishedVersion(): string
    {
        return 'content:published:version';
    }

    /**
     * 站点目录缓存。
     */
    public static function siteCatalog(): string
    {
        return 'catalog:site:v1';
    }

    /**
     * 管理端目录概览缓存。
     */
    public static function adminCatalogSummary(): string
    {
        return 'catalog:admin_summary:v1';
    }

    /**
     * 站点目录拆分版本缓存。
     */
    public static function siteCatalogSplitVersion(): string
    {
        return 'catalog:site:split:version';
    }

    /**
     * 服务配置缓存。
     */
    public static function serviceConfig(int $serviceId): string
    {
        return 'service:'.$serviceId.':config';
    }

    /**
     * 服务远程状态缓存。
     */
    public static function serviceRemoteStatus(int $serviceId): string
    {
        return 'service:'.$serviceId.':remote_status';
    }

    /**
     * 服务监控模块缓存。
     */
    public static function serviceMonitorModules(int $serviceId): string
    {
        return 'service:'.$serviceId.':monitor_modules';
    }

    /**
     * VNC Token 缓存。
     */
    public static function vncToken(string $token): string
    {
        return 'vnc_token:'.$token;
    }

    /**
     * 验证码缓存。
     */
    public static function verificationCode(string $key): string
    {
        return 'verification_code:'.$key;
    }

    /**
     * 极验脚本缓存。
     */
    public static function geeTestScript(): string
    {
        return 'geetest:script';
    }

    /**
     * Cap 人机验证前端脚本缓存。
     */
    public static function capScript(): string
    {
        return 'captcha:cap:script';
    }

    /**
     * VAPTCHA 已通过 token 防复用缓存。
     */
    public static function vaptchaVerifiedToken(string $fingerprint): string
    {
        return 'vaptcha:verified_token:'.$fingerprint;
    }

    /**
     * 上传资产引用缓存。
     */
    public static function uploadedAssetReference(string $token): string
    {
        return 'uploaded_asset:'.$token;
    }

    /**
     * 分布式锁键。
     */
    public static function lock(string $name): string
    {
        return 'lock:'.$name;
    }
}
