<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Exceptions\BusinessException;

/**
 * 上游该版本没有这条接口（3.8.0 删掉了整套 /v1，或供应商自己删了某条路由）。
 *
 * 与「上游报错」严格区分：只有取到这个异常才允许换下一条通道；
 * 5xx、业务失败、连接错误都必须直接失败，否则会把「付没付成功不知道」当成「没付」再付一次。
 */
final class ZjmfEndpointMissingException extends BusinessException {}
