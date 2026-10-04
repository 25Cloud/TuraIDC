<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 面板入口信息归一化
 *
 * 各家 CDN / 虚拟主机商家的「面板信息」摆放位置差异极大，同一个信息可能出现在：
 *   - host/header 的 module_client_area 自定义区域（HTML 文本标签或跳转按钮）
 *   - host_data.config_option（键值对）
 *   - host_data 直接字段（panel_username / ftp_username 等明确字段）
 *   - host_data 泛化字段（username / password）
 *   - 页面 <a href> 直链
 *   - JS 里拼接的跳转地址（天理云即为该形态：base64(access_token&username&uid)）
 *
 * 原始 HTML 直接透传给终端用户不可靠（样式各异、依赖 jQuery、按钮点了没反应），
 * 因此这里把上述形态统一收敛成稳定结构，供控制台直接渲染：
 *   panel_url / panel_username / panel_password / panel_type / panel_source。
 *
 * 全部为「尽力而为」：任一字段抽不到就留空，绝不抛错——
 * 面板信息属于增值展示，抽不到时退化为空状态即可，不能因此让整个控制台不可用。
 */
final class PanelAccessExtractor
{
    /**
     * 带限定词的面板标签 → 归一化字段名。优先级高于泛化标签。
     *
     * 「面板」「控制台」这类限定词很关键：CDN 类产品的 host_data.username
     * 往往是控制台 uid（天理云实例 9436 即 username=16118），
     * 只有商家明确标注「面板用户名」时才可认定是面板账号。
     */
    private const STRICT_LABEL_FIELDS = [
        '面板用户名' => 'username',
        '面板账号' => 'username',
        '面板帐号' => 'username',
        '面板登录名' => 'username',
        '控制面板用户名' => 'username',
        '控制面板账号' => 'username',
        '管理面板用户名' => 'username',
        'ftp用户名' => 'username',
        'ftp用户' => 'username',
        'ftp账号' => 'username',
        'cpanel用户名' => 'username',
        '主机面板用户名' => 'username',
        '面板密码' => 'password',
        '控制面板密码' => 'password',
        '管理面板密码' => 'password',
        '初始密码' => 'password',
        'ftp密码' => 'password',
        'cpanel密码' => 'password',
        '面板地址' => 'url',
        '面板链接' => 'url',
        '面板url' => 'url',
        '面板入口' => 'url',
        '控制面板' => 'url',
        '管理面板' => 'url',
        '控制台地址' => 'url',
        '面板' => 'url',
    ];

    /** 泛化标签 → 归一化字段名。优先级最低，仅在没有更明确来源时采用。 */
    private const LOOSE_LABEL_FIELDS = [
        '用户名' => 'username',
        '账号' => 'username',
        '帐号' => 'username',
        '登录名' => 'username',
        '用户' => 'username',
        '密码' => 'password',
        '登录密码' => 'password',
        '地址' => 'url',
        '链接' => 'url',
        'url' => 'url',
    ];

    /** host_data 上语义明确的面板字段（按优先级）。 */
    private const HOST_FIELD_USERNAME = [
        'panel_username', 'console_username', 'cdn_username',
        'ftp_username', 'cpanel_username', 'control_panel_username',
    ];

    private const HOST_FIELD_PASSWORD = [
        'panel_password', 'console_password', 'cdn_password',
        'ftp_password', 'cpanel_password', 'control_panel_password',
    ];

    private const HOST_FIELD_URL = [
        'panel_url', 'panel_link', 'console_url', 'manage_url', 'control_panel_url', 'cp_url',
    ];

    /**
     * host_data 上的泛化字段，仅作最后兜底。
     *
     * 放在最后是因为它们经常不是面板凭据：CDN 的 username 是控制台 uid，
     * 云主机的 password 可能是 SSH 口令而非面板口令。
     */
    private const HOST_FIELD_FALLBACK_USERNAME = ['username', 'user', 'account'];
    private const HOST_FIELD_FALLBACK_PASSWORD = ['password', 'pass', 'pwd'];
    private const HOST_FIELD_FALLBACK_URL = ['url', 'link'];

    /**
     * 面板类型判定关键词（按顺序匹配，先命中先算）。
     * 未命中时返回空字符串，由前端按「通用面板」呈现。
     */
    private const PANEL_TYPE_KEYWORDS = [
        'idcdun' => 'cdn',
        'cdnfly' => 'cdn',
        'cloudflare' => 'cdn',
        'cdn' => 'cdn',
        '宝塔' => 'panel',
        'bt' => 'panel',
        'cpanel' => 'cpanel',
        'directadmin' => 'directadmin',
        'da面板' => 'directadmin',
        'ftp' => 'ftp',
    ];

    /** 表达式求值最大递归深度，防御恶意或异常脚本导致的无限引用。 */
    private const MAX_EVAL_DEPTH = 6;

    /**
     * 从任意来源的原始数据中提取面板入口信息。
     *
     * 取值优先级（先到先得，后来源不覆盖）：
     *   1. host_data 上语义明确的面板字段   —— panel_username / ftp_password 等
     *   2. 配置项与 HTML 里的限定标签       —— 「面板用户名」「控制面板地址」
     *   3. 配置项与 HTML 里的泛化标签       —— 「用户名」「密码」
     *   4. host_data 的泛化字段            —— username / password（可能不是面板凭据）
     *
     * @param  array<int, mixed>  $configOptions  host/header 的 config_options
     * @param  array<string, mixed>  $hostData  host_data 原始载荷
     * @param  array<int, mixed>  $hostConfigOptions  provision_data.host_config_option
     * @return array<string, string>
     */
    public static function extract(
        array $configOptions = [],
        array $hostData = [],
        array $hostConfigOptions = []
    ): array {
        $result = self::emptyResult();

        // ---- 1. host_data 上语义明确的面板字段 ----
        foreach (self::HOST_FIELD_URL as $field) {
            $value = self::normalizeText($hostData[$field] ?? '');
            if (self::looksLikeUrl($value)) {
                $result['panel_url'] = $value;
                $result['panel_source'] = 'host_field';
                break;
            }
        }

        foreach (self::HOST_FIELD_USERNAME as $field) {
            $value = self::normalizeText($hostData[$field] ?? '');
            if ($value !== '') {
                $result['panel_username'] = $value;
                $result['panel_source'] = $result['panel_source'] ?: 'host_field';
                break;
            }
        }

        foreach (self::HOST_FIELD_PASSWORD as $field) {
            $value = self::normalizeText($hostData[$field] ?? '');
            if ($value !== '') {
                $result['panel_password'] = $value;
                $result['panel_source'] = $result['panel_source'] ?: 'host_field';
                // 与用户名一致：HOST_FIELD_PASSWORD 是按优先级排列的，
                // 取第一个非空即最高优先级，不该被后面的字段覆盖。
                break;
            }
        }

        // ---- 2. 配置项里的中文标签：先限定标签，后泛化标签 ----
        $optionSources = [];

        foreach ([
            'config_option' => array_merge(array_values($configOptions), array_values($hostConfigOptions)),
            'host_config_option' => array_values((array) ($hostData['config_option'] ?? [])),
        ] as $source => $options) {
            $labels = self::collectOptionLabels($options);
            if ($labels !== []) {
                $optionSources[] = [$source, $labels];
            }
        }

        foreach ([self::STRICT_LABEL_FIELDS, self::LOOSE_LABEL_FIELDS] as $table) {
            foreach ($optionSources as [$source, $labels]) {
                foreach ($labels as $label => $rawValue) {
                    $field = $table[$label] ?? null;
                    if ($field === null) {
                        continue;
                    }

                    $target = self::targetOf($field);
                    if ($target === '' || $result[$target] !== '') {
                        continue;
                    }

                    $value = self::normalizeText($rawValue);
                    if ($value === '' || ($target === 'panel_url' && ! self::looksLikeUrl($value))) {
                        continue;
                    }

                    $result[$target] = $value;
                    $result['panel_source'] = $result['panel_source'] ?: $source;
                }
            }
        }

        // ---- 3. host_data 内嵌的 HTML 片段：交给 fromHtml 完整解析。
        // 这里不能只做标签映射——厂商常把面板地址藏在 JS 拼接里（天理云即该形态），
        // 标签映射取不到，只有 fromHtml 才会解析 <a href> 与 btoa 拼接地址。
        foreach (self::extractHtmlFragments($hostData) as $fragment) {
            foreach (self::fromHtml($fragment) as $key => $value) {
                if ($key === 'panel_type' || $value === '' || $result[$key] !== '') {
                    continue;
                }

                $result[$key] = $value;
                $result['panel_source'] = $result['panel_source'] ?: 'html';
            }
        }

        // ---- 4. host_data 泛化字段兜底 ----
        foreach (self::HOST_FIELD_FALLBACK_USERNAME as $field) {
            $value = self::normalizeText($hostData[$field] ?? '');
            if ($value !== '' && $result['panel_username'] === '') {
                $result['panel_username'] = $value;
                $result['panel_source'] = $result['panel_source'] ?: 'host_field_fallback';
                break;
            }
        }

        foreach (self::HOST_FIELD_FALLBACK_PASSWORD as $field) {
            $value = self::normalizeText($hostData[$field] ?? '');
            if ($value !== '' && $result['panel_password'] === '') {
                $result['panel_password'] = $value;
                $result['panel_source'] = $result['panel_source'] ?: 'host_field_fallback';
                break;
            }
        }

        foreach (self::HOST_FIELD_FALLBACK_URL as $field) {
            $value = self::normalizeText($hostData[$field] ?? '');
            if (self::looksLikeUrl($value) && $result['panel_url'] === '') {
                $result['panel_url'] = $value;
                $result['panel_source'] = $result['panel_source'] ?: 'host_field_fallback';
                break;
            }
        }

        $result['panel_type'] = self::resolvePanelType($result);

        return $result;
    }

    /**
     * 用额外的 HTML 片段补齐面板信息。
     *
     * 面板型产品的面板账号密码常常只写在自定义区域里，host_data 完全没有——
     * 天理云 CDN 就是如此：host_data.username 是控制台 uid（16118），
     * 真正的面板账号（ser862441826426）在 module_client_area 的 HTML 中。
     *
     * 因此这里允许覆盖「泛化字段兜底」这类弱来源（panel_source 以 _fallback 结尾），
     * 但不会覆盖商家明确给出的字段。
     *
     * @param  array<string, string>  $panel  extract() 的结果
     * @param  array<int, string>  $htmlFragments
     * @return array<string, string>
     */
    public static function mergeHtmlSources(array $panel, array $htmlFragments): array
    {
        if ($panel === []) {
            $panel = self::emptyResult();
        }

        // $weak 只描述「进入 HTML 合并前」的来源强度，不随赋值改变：
        // 一旦在循环里被置 true，后续每个字段都会覆盖已有值，
        // 商家明确配置的面板地址/账号会被 HTML 里的兜底值顶掉。
        $weak = ! isset($panel['panel_source'])
            || $panel['panel_source'] === ''
            || str_ends_with((string) $panel['panel_source'], '_fallback');

        $htmlSource = $weak ? 'area_html' : (string) ($panel['panel_source'] ?: 'area_html');

        foreach ($htmlFragments as $html) {
            if (! is_string($html) || trim($html) === '') {
                continue;
            }

            foreach (self::fromHtml($html) as $key => $value) {
                if ($key === 'panel_type' || $value === '') {
                    continue;
                }

                // 已有强来源的值不被覆盖；弱来源（泛化字段兜底）允许被 HTML 里的准确值替换
                if (($panel[$key] ?? '') !== '' && ! $weak) {
                    continue;
                }

                $panel[$key] = $value;
                $panel['panel_source'] = $htmlSource;
            }
        }

        $panel['panel_type'] = self::resolvePanelType($panel);

        return $panel;
    }

    /**
     * 从 HTML 片段中提取面板信息。
     *
     * 覆盖三种常见形态：
     *   1. 文本标签型：<p>用户名: xxx</p><p>密码: yyy</p>
     *   2. 跳转直链型：<a href="https://panel.example.com/login">
     *   3. 跳转按钮型：JS 里 base64 拼地址（天理云即该形态）
     *
     * @return array<string, string>
     */
    public static function fromHtml(string $html): array
    {
        $result = self::emptyResult();

        if (trim($html) === '') {
            return $result;
        }

        $labels = self::matchHtmlLabels($html);

        // 1. 文本标签型：限定标签优先于泛化标签
        foreach ([self::STRICT_LABEL_FIELDS, self::LOOSE_LABEL_FIELDS] as $table) {
            foreach ($labels as $label => $value) {
                $field = $table[$label] ?? null;
                if ($field === null) {
                    continue;
                }

                $target = self::targetOf($field);
                if ($target === '' || $result[$target] !== '') {
                    continue;
                }

                if ($target === 'panel_url' && ! self::looksLikeUrl($value)) {
                    continue;
                }

                $result[$target] = $value;
            }
        }

        // ---- 2. <a href> 直链面板地址
        if ($result['panel_url'] === '' && preg_match('/<a\b[^>]*\bhref=[\'"]([^\'"]+)[\'"]/i', $html, $m)) {
            $candidate = self::normalizeText(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (self::looksLikeUrl($candidate)) {
                $result['panel_url'] = $candidate;
            }
        }

        // 3. JS 拼接的跳转地址（天理云即该形态）：
        //    面板域名在 dailiUrl 之类的模板变量里，查询参数经 btoa 编码后拼在末尾。
        if ($result['panel_url'] === '') {
            $result['panel_url'] = self::resolveScriptPanelUrl($html, $result);
        }

        $result['panel_type'] = self::resolvePanelType($result);

        return $result;
    }

    /**
     * 解码面板 URL 里携带的查询参数。
     *
     * 形如 access_token=xxx&username=ser862&uid=16118，天理云会先把它 base64
     * 编码（并把 / 与 = 转成 _ 与 , 以免破坏 JS 字面量）再拼到自家面板域名上。
     * 入参既可以是明文串，也可以是 base64 串，这里两种都能吃。
     *
     * @return array{access_token: string, username: string, uid: string}
     */
    public static function decodeUrlParamPayload(string $payload): array
    {
        $result = ['access_token' => '', 'username' => '', 'uid' => ''];

        $plain = self::decodePayload($payload);

        if ($plain === '') {
            return $result;
        }

        parse_str($plain, $parsed);

        foreach (array_keys($result) as $key) {
            $result[$key] = self::normalizeText($parsed[$key] ?? '');
        }

        return $result;
    }

    // ---------------------------------------------------------------------
    // 内部实现
    // ---------------------------------------------------------------------

    /** @return array<string, string> */
    private static function emptyResult(): array
    {
        return [
            'panel_url' => '',
            'panel_username' => '',
            'panel_password' => '',
            'panel_type' => '',
            'panel_source' => '',
        ];
    }

    private static function targetOf(string $field): string
    {
        return match ($field) {
            'username' => 'panel_username',
            'password' => 'panel_password',
            'url' => 'panel_url',
            default => '',
        };
    }

    /**
     * 归一化配置项数组为「中文标签 => 值」。
     *
     * 各家商家对同一份配置项用的键名五花八门（name / sub_name / option_name /
     * name_k / value / option_name_first / key），这里逐个尝试直到取到非空值。
     *
     * @param  array<int|string, mixed>  $options
     * @return array<string, string>
     */
    private static function collectOptionLabels(array $options): array
    {
        $labels = [];

        foreach ($options as $key => $option) {
            // 已经是「标签 => 值」形态
            if (is_string($option)) {
                $name = self::normalizeText($key);
                if ($name !== '' && $option !== '') {
                    $labels[$name] = $option;
                }
                continue;
            }

            if (! is_array($option)) {
                continue;
            }

            $name = self::normalizeText(
                $option['name'] ?? ($option['name_k'] ?? ($option['option_name'] ?? ''))
            );

            $value = self::normalizeText(
                $option['sub_name'] ?? ($option['value'] ?? ($option['option_name_first'] ?? ''))
            );

            if ($name === '') {
                $name = self::normalizeText($option['key'] ?? '');
            }

            if ($name === '' || $value === '' || isset($labels[$name])) {
                continue;
            }

            $labels[$name] = $value;
        }

        return $labels;
    }

    /**
     * @param  array<string, mixed>  $hostData
     * @return array<int, string>
     */
    private static function extractHtmlFragments(array $hostData): array
    {
        $fragments = [];

        foreach (['description', 'remark', 'notes', 'panel_info', 'clientinfo', 'content', 'html'] as $field) {
            $value = $hostData[$field] ?? '';

            if (is_string($value) && trim($value) !== '') {
                $fragments[] = $value;
            }
        }

        return $fragments;
    }

    /**
     * 抽取 HTML 里的「标签: 值」文本对。
     *
     * 兼容全角冒号、<br/> 分隔、以及值里带 HTML 实体的情况。
     * 限定词更长的标签排在前面，避免「面板」先于「面板地址」命中。
     *
     * @return array<string, string>
     */
    private static function matchHtmlLabels(string $html): array
    {
        $labels = [];

        $alternatives = array_merge(
            array_keys(self::STRICT_LABEL_FIELDS),
            array_keys(self::LOOSE_LABEL_FIELDS)
        );

        // 长标签优先：「面板地址」必须排在「面板」之前
        usort($alternatives, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $pattern = '/(' . implode('|', array_map(
            static fn (string $label): string => preg_quote($label, '/'),
            $alternatives
        )) . ')\s*[:：]\s*([^<\r\n]+)/u';

        if (! preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            return $labels;
        }

        foreach ($matches as $match) {
            $label = self::normalizeText(strip_tags($match[1]));
            $value = self::normalizeText(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($label === '' || $value === '' || isset($labels[$label])) {
                continue;
            }

            $labels[$label] = $value;
        }

        return $labels;
    }

    /**
     * 解析脚本里拼接出的面板地址。
     *
     * @param  array<string, string>  $current  已解析到的字段，用于回填 username
     * @return string
     */
    private static function resolveScriptPanelUrl(string $html, array $current): string
    {
        $scripts = self::extractScriptBlocks($html);

        foreach ($scripts as $script) {
            // 形态 A：btoa('字面量')。用捕获组严格配平引号，避免 .*? 跨过引号吞掉后续内容。
            if (preg_match('/btoa\s*\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*\)/s', $script, $m)) {
                $payload = self::decodePayload($m[2]);
                if ($payload === '') {
                    continue;
                }

                $url = self::applyEncodedPayload($script, $payload, $current);
                if ($url !== '') {
                    return $url;
                }
            }

            // 形态 B：变量参与拼接后 btoa(变量)
            $variables = self::collectScriptVariables($script);

            if (preg_match('/btoa\s*\(\s*([A-Za-z_$][\w$]*)\s*\)/', $script, $m)) {
                // collectScriptVariables() 返回的已是求值成品，不能再进evaluateJsExpression：
                // 成品是裸串（?a=1&b=2），既非字面量也非变量名，二次求值会当成无法解析而返回空。
                $payload = self::decodePayload($variables[$m[1]] ?? '');

                if ($payload === '') {
                    continue;
                }

                $url = self::applyEncodedPayload($script, $payload, $current);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return '';
    }

    /**
     * 从脚本里取出面板地址模板（末尾的 token 值由调用方补）。
     *
     * 覆盖两种写法：
     *   var dailiUrl = "https://x/cdnlogin?token=" + encodedUrl;   变量赋值
     *   window.open("https://x/cdnlogin?token=" + u);                 内联拼接
     * 另有一种更朴素的整串直给：
     *   window.open("https://x/cdnlogin?token=abc");
     */
    private static function matchUrlTemplate(string $script): string
    {
        $quoted = '([\'"])((?:\\\\.|(?!\1).)*)\1';

        // 优先取带 query 的地址（面板入口几乎必然带参数）
        if (preg_match('/' . $quoted . '\s*\+\s*[A-Za-z_$][\w$.\[\]]*\s*\)/', $script, $m)
            || preg_match('/=\s*' . $quoted . '\s*\+\s*[A-Za-z_$][\w$.\[\]]*\s*[;\r\n]/', $script, $m)
        ) {
            $candidate = self::normalizeText($m[2]);
            if (self::looksLikeUrl($candidate)) {
                return $candidate;
            }
        }

        // 退而求其次：脚本里任意一个 http(s) 字面量
        if (preg_match('/' . $quoted . '/', $script, $m)) {
            $candidate = self::normalizeText($m[2]);
            if (self::looksLikeUrl($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * 用明文参数串拼出最终面板地址，并把参数里的账号回填到结果集。
     *
     * 模板形如 https://cdn.example.com/cdnlogin?token=（上游 JS 里通常已带好 query 前缀），
     * token 值 = base64(明文参数串)，并按上游习惯做 / -> _ 、= -> , 转义。
     *
     * @param  array<string, string>  $current
     */
    private static function applyEncodedPayload(string $script, string $plainPayload, array &$current): string
    {
        $template = self::matchUrlTemplate($script);

        if ($template === '') {
            return '';
        }

        $encoded = strtr(base64_encode($plainPayload), ['/' => '_', '=' => ',']);

        // 模板通常已带好 query 前缀与参数名（如 https://x/cdnlogin?token=），
        // 此时直接追加；缺失时才补分隔符与 token=，避免出现 ?token=& 或双问号。
        $url = $template;

        if (! str_contains($url, '?')) {
            $url .= '?';
        } elseif (! preg_match('/[?&][^?&=]*=$/', $url)) {
            // 末段不是「参数名=」这种待填值形态才补&；已经是 ?token= 则直接接值
            $url .= '&';
        }

        if (! str_contains($url, 'token=')) {
            $url .= 'token=';
        }

        $url .= $encoded;

        if ($current['panel_username'] === '') {
            $params = self::decodeUrlParamPayload($plainPayload);
            $current['panel_username'] = $params['username'];
        }

        return $url;
    }

    /**
     * 入参可能是明文查询串，也可能是 base64（且 / = 被转成 _ ,），两种都还原为明文。
     */
    private static function decodePayload(string $payload): string
    {
        $payload = trim($payload);

        if ($payload === '') {
            return '';
        }

        // 明文形态：以 ? 或 & 开头，或直接是 key=value
        if (str_starts_with($payload, '?') || str_starts_with($payload, '&') || preg_match('/^[A-Za-z_][\w.-]*=/', $payload)) {
            return ltrim($payload, '?&');
        }

        // base64 形态：先还原商家做的字符转义再解
        $restored = strtr($payload, ['_' => '/', ',' => '=']);
        $decoded = base64_decode($restored, true);

        if ($decoded === false) {
            return '';
        }

        // 少数商家会编码两次
        if (! str_contains($decoded, '=')) {
            $second = base64_decode($decoded, true);
            if ($second !== false && str_contains($second, '=')) {
                $decoded = $second;
            }
        }

        return $decoded;
    }

    /**
     * 取出 HTML 里的 <script> 块。
     *
     * @return array<int, string>
     */
    private static function extractScriptBlocks(string $html): array
    {
        if (! preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return trim($html) === '' ? [] : [$html];
        }

        return array_values(array_filter(array_map('trim', $matches[1])));
    }

    /**
     * 抓取 <script> 里的简单变量赋值。
     *
     * 处理的形态：
     *   var token = "abc";
     *   token = token.replace(/\//g, "_");            <- 无 var 关键字的重新赋值
     *   var url = "?access_token=" + token + "&uid=1";
     *
     * 刻意不处理函数调用（btoa / window.open 等）——这里只做取值，
     * 真正需要执行的那一步在 applyEncodedPayload() 里用 PHP 复刻。
     *
     * @return array<string, string>
     */
    private static function collectScriptVariables(string $script): array
    {
        $variables = [];

        foreach (self::splitStatements($script) as $statement) {
            // 商家常把赋值写在 $(fn) { ... } 这类回调里，语句开头是包装代码而非变量名。
            // 因此不要求整条语句以 var 开头，而是取最后一个「名字 = 表达式」的匹配。
            if (! preg_match('/(?:^|[\s;{}(,])(?:var|let|const)?\s*([A-Za-z_$][\w$]*)\s*=\s*([^=].*)$/s', $statement, $m)) {
                continue;
            }

            $name = $m[1];
            $expression = trim($m[2]);

            // 变量名后紧跟括号的是函数调用（f(...)），不是赋值
            if (str_starts_with($expression, '(')) {
                continue;
            }

            $value = self::evaluateJsExpression($expression, $variables);

            if ($value === '') {
                continue;
            }

            $variables[$name] = $value;
        }

        return $variables;
    }

    /**
     * 按分号与花括号切分脚本语句，跳过字符串字面量内部的分隔符。
     *
     * 商家常把赋值包在 `$(fn) {` ... `}` 回调里，若只按分号切，
     * 首条语句会变成 `$(document).ready(function() { var x = ...`，变量名就匹配不上了。
     *
     * @return array<int, string>
     */
    private static function splitStatements(string $script): array
    {
        $statements = [];
        $current = '';
        $quote = '';
        $length = strlen($script);

        for ($i = 0; $i < $length; $i++) {
            $char = $script[$i];

            if ($quote !== '') {
                $current .= $char;

                if ($char === '\\') {
                    // 转义字符，连同下一个字符一起吞掉
                    if ($i + 1 < $length) {
                        $current .= $script[$i + 1];
                        $i++;
                    }
                    continue;
                }

                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($char === ';' || $char === '{' || $char === '}') {
                $statements[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = $current;
        }

        return array_values(array_filter(array_map('trim', $statements)));
    }

    /**
     * 求值「字面量 + 变量 + 字面量」形式的拼接表达式。
     *
     * 只支持字符串字面量、已采集变量与 .replace() 链，不执行任何其他函数调用，
     * 避免把上游脚本当代码跑。
     *
     * @param  array<string, string>  $variables
     */
    private static function evaluateJsExpression(
        string $expression,
        array $variables,
        int $depth = 0
    ): string {
        $expression = trim($expression);

        if ($expression === '' || $depth > self::MAX_EVAL_DEPTH) {
            return '';
        }

        // 链式 replace：'xxx'.replace(/\//g,"_").replace(/=/g,",")
        // 商家用它把 base64 里的 / 与 = 转成 URL 安全字符，必须复刻否则解不出 token
        if (str_contains($expression, '.replace')) {
            return self::applyReplaceChain($expression, $variables, $depth);
        }

        // 完整字符串字面量。必须逐字符配平引号：
        // 直接用 ^(['"])(.*)\1$ 会把 "?a=" + token + "&b=1" 这种拼接表达式
        // 误判成字面量（首尾恰好都是引号），从而原样返回表达式残留。
        $literal = self::matchStringLiteral($expression);
        if ($literal !== null) {
            return $literal;
        }

        // 纯变量引用
        if (preg_match('/^[A-Za-z_$][\w$]*$/', $expression)) {
            return $variables[$expression] ?? '';
        }

        // 已是求值成品的裸串（如 ?access_token=xxx&uid=1），原样返回。
        // 它既不是字面量也不是可拼接表达式，若继续往下走会被误判为无法解析。
        if (! str_contains($expression, "'") && ! str_contains($expression, '"')) {
            return $expression;
        }

        // 拼接式：按 + 切分，逐段取字面量或变量值
        if (! str_contains($expression, '+')) {
            return '';
        }

        $result = '';
        foreach (self::splitConcatenation($expression) as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            $literal = self::matchStringLiteral($segment);
            if ($literal !== null) {
                $result .= $literal;
                continue;
            }

            if (preg_match('/^[A-Za-z_$][\w$]*$/', $segment)) {
                $result .= $variables[$segment] ?? '';
                continue;
            }

            // 段内是函数调用等无法求值的结构，整条表达式作废
            return '';
        }

        return $result;
    }

    /**
     * 严格解析：整个字符串是否恰好是一个完整的字符串字面量。
     *
     * @return string|null 匹配返回字面量内容（已去除引号），否则返回 null
     */
    private static function matchStringLiteral(string $expression): ?string
    {
        $length = strlen($expression);

        if ($length < 2) {
            return null;
        }

        $quote = $expression[0];

        if ($quote !== '"' && $quote !== "'") {
            return null;
        }

        for ($i = 1; $i < $length; $i++) {
            $char = $expression[$i];

            if ($char === '\\') {
                $i++;
                continue;
            }

            if ($char === $quote) {
                // 收尾引号必须落在最后一个字符，否则说明后面还有拼接内容
                return $i === $length - 1 ? substr($expression, 1, $i - 1) : null;
            }
        }

        return null;
    }

    /**
     * 复刻 replace 链，支持链头是字面量或已采集变量。
     *
     * 只处理 .replace(/正则/gi, "字面量") 这一种形式，其余原样返回，
     * 由上层判定为解析失败并降级。
     *
     * @param  array<string, string>  $variables
     */
    private static function applyReplaceChain(string $expression, array $variables, int $depth): string
    {
        $offset = 0;
        $value = null;

        $pattern = '/\.replace\s*\(\s*\/((?:\\\\.|[^\/\\\\])*)\/([gimsuy]*)\s*,\s*([\'"])((?:\\\\.|(?!\3).)*)\3\s*\)/s';

        while (preg_match($pattern, $expression, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $matched = $m[0][0];

            if ($value === null) {
                $head = trim(substr($expression, 0, $m[0][1]));

                $literal = self::matchStringLiteral($head);
                $value = $literal ?? (preg_match('/^[A-Za-z_$][\w$]*$/', $head)
                    ? ($variables[$head] ?? '')
                    : self::evaluateJsExpression($head, $variables, $depth + 1));

                if ($value === '') {
                    return '';
                }
            }

            $value = str_replace($m[1][0], $m[4][0], $value);
            $offset = $m[0][1] + strlen($matched);
        }

        return $value ?? '';
    }

    /**
     * 按 + 切分拼接表达式，跳过字符串字面量内部的 + 号。
     *
     * @return array<int, string>
     */
    private static function splitConcatenation(string $expression): array
    {
        $segments = [];
        $current = '';
        $quote = '';
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            if ($quote !== '') {
                $current .= $char;

                if ($char === '\\') {
                    if ($i + 1 < $length) {
                        $current .= $expression[$i + 1];
                        $i++;
                    }
                    continue;
                }

                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($char === '+') {
                $segments[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $segments[] = $current;

        return $segments;
    }

    /**
     * 判定面板类型，供前端选择展示形态。
     *
     * @param  array<string, string>  $panel
     */
    private static function resolvePanelType(array $panel): string
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            (string) ($panel['panel_url'] ?? ''),
            (string) ($panel['panel_username'] ?? ''),
        ])));

        if ($haystack === '') {
            return '';
        }

        foreach (self::PANEL_TYPE_KEYWORDS as $keyword => $type) {
            if (str_contains($haystack, mb_strtolower($keyword))) {
                return $type;
            }
        }

        return '';
    }

    private static function looksLikeUrl(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return (bool) preg_match('#^(https?://|//)#i', $value);
    }

    private static function normalizeText(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        return trim((string) $value);
    }
}
