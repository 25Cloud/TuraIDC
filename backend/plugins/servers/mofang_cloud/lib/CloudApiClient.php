<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\MofangCloud\Lib;

/**
 * 魔方云接口客户端。
 *
 * 两代协议的共同契约只有「发一个动作、拿回一个数组」；
 * 路径拼接、鉴权、报文编码、响应信封一律由实现按代际决定，绝不互相兜底。
 */
interface CloudApiClient
{
    public function mode(): CloudApiMode;

    public function credentials(): CloudCredentials;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @return array<string, mixed> 解码后的响应体（各代原生形状）
     *
     * @throws CloudApiException
     */
    public function request(string $method, string $path, array $payload = [], array $query = []): array;
}
