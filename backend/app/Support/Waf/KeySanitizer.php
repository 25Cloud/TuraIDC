<?php

declare(strict_types=1);

namespace App\Support\Waf;

/**
 * 请求「键名」检测器 —— 识别数组键里的注入字符。
 *
 * 由来：入站过滤的一个经典 P0——只清洗数组的**值**、不检查**键**，攻击者
 * 遂把 PHP 代码/HTML 藏进键名，经配置写入器逃逸单引号造成配置文件 RCE，
 * 或经后台表格 innerHTML 渲染造成存储型 XSS。
 *
 * 本类只**检测**、绝不**改写**。早期版本会把脏键删字符后放行，但删字符是
 * 不可逆折叠：`amount<` 与 `amount` 会变成同一个键，`['amount' => '100',
 * 'amount<' => '0']` 清洗后成了 `['amount' => '0']`——攻击载荷反而覆盖了正常
 * 业务值。因此检测到脏键由中间件直接拒绝整个请求，与"密钥缺省即拒绝"同一
 * 原则：无法证明干净的输入不放行。
 *
 * 深度超过 MAX_DEPTH 的嵌套同样视为脏：递归停止意味着深层键从未被检查，
 * 静默放行等于把深层变成免检区。
 */
final class KeySanitizer
{
    /**
     * 判定为脏的字符：
     * - `\x00-\x1F\x7F` 控制字符（含换行，可用于伪造配置行 / 日志注入）
     * - `<` `>` `"` HTML 逃逸
     * - `'` `\` PHP/SQL 字符串逃逸
     *
     * 合法键名（参数名、配置键、SKU 规格名等人类可读标识）不含这些字符，
     * 因此本检测对正常请求是**恒等通过**——这正是它能安全地全局生效的前提。
     */
    private const FORBIDDEN = '/[\x00-\x1F\x7F<>"\'\\\\]/u';

    /** 递归深度上限，防御畸形深层嵌套。与 PayloadScanner 同口径。 */
    private const MAX_DEPTH = 12;

    /**
     * 是否存在脏键（含嵌套）。
     *
     * @param  array<array-key, mixed>  $input
     */
    public function hasDirtyKey(array $input, int $depth = 0): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return true;
        }

        foreach ($input as $key => $value) {
            if (is_string($key) && $this->isDirty($key)) {
                return true;
            }

            if (is_array($value) && $this->hasDirtyKey($value, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 单键是否含被禁止的字符。非字符串键（整数索引）恒为干净。
     */
    public function isDirty(mixed $key): bool
    {
        if (! is_string($key)) {
            return false;
        }

        return (string) preg_replace(self::FORBIDDEN, '', $key) !== $key;
    }
}
