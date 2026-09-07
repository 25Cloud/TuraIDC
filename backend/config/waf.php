<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 入站 WAF（Web 应用防火墙）
|--------------------------------------------------------------------------
|
| 入站载荷扫描层：用正则规则库在请求进入业务
| 逻辑之前拦截明显的攻击载荷（SQL 注入、命令执行、目录穿越、扫描器探测）。
|
| 定位说明——本层是**纵深防御的外层**，不是唯一防线，也不能替代：
|   - Eloquent 参数绑定（防 SQL 注入的真正手段）
|   - FormRequest 输入验证（业务合法性）
|   - RichHtmlSanitizer 富文本白名单净化（防存储型 XSS 的真正手段）
| 正则黑名单天然可被绕过，它的价值在于挡掉自动化扫描与批量探测，降低噪音与
| 被踩到未知漏洞的概率，而不是当作"有它就安全了"。
|
| 与常见通用规则库的差异（有意为之）：
|   - 剔除 ThinkPHP payload（invokefunction / call_user_func_array / \think\）
|     与 Smarty SSTI 规则：本项目是 Laravel + 无模板注入面，留着只会误伤正常参数。
|   - 剔除 XSS 标签/事件属性规则：富文本字段（文章正文、工单内容）合法包含 HTML，
|     在入站层拦 `<div` 会直接打断业务；XSS 交给 RichHtmlSanitizer 白名单净化。
|   - 放宽部分过于宽泛的 SQL 函数名规则（如裸 `substr(` / `user(`）：这类词在
|     正常文本里出现概率高，误伤代价大于收益。
|
| 按我们的威胁模型对通用 WAF 能力做的取舍（有意不做的部分与理由）：
|   - 做了：按攻击类别停用的运营开关（disabled_categories）、规则元数据
|     （id/category/severity，为将来 N-day/CVE 订阅规则预留 schema）。
|   - 不做：变换链多层解码（url_decode×2/base64 试探/多层嵌套变换）——我们
|     的威胁模型是"挡自动化扫描与批量探测"，逐值多轮解码会破坏合法 % 序列
|     （WafPayloadScannerTest::test_does_not_double_decode_percent_sequences 是
|     有意取舍）；Java/SSTI/XSS 入站规则（我们是 Laravel/Blade，无对应注入
|     面，XSS 交给 RichHtmlSanitizer）；CC 组合 DSL 与 JA4/TLS 指纹（边缘
|     CDN 的对抗维度，计费系统不需要）；multipart 内容扫描（我们上传走
|     UploadedFile 白名单校验）。检测实现不依赖任何外部库——规则即配置，
|     引擎即数百行 PHP，全部走代码评审与回归测试。
|
*/

return [

    // 总开关。默认开启；出现误伤可临时置 false 快速止血。
    'enabled' => env('WAF_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | 观察模式（dry-run）
    |--------------------------------------------------------------------------
    |
    | 为 true 时只记录命中日志、不拦截请求。上线初期建议先开观察模式跑一段时间，
    | 确认没有误伤真实业务后再切换为拦截。
    |
    */
    'observe_only' => env('WAF_OBSERVE_ONLY', false),

    // 命中后是否写 warning 日志（含规则名、路径、IP，不记录完整载荷以免日志膨胀/泄敏）
    'log_hits' => env('WAF_LOG_HITS', true),

    /*
    |--------------------------------------------------------------------------
    | 规则库元数据
    |--------------------------------------------------------------------------
    |
    | version：规则库自身的语义化版本。规则条目的增删改必须递增，供运维核对
    | "线上跑的是哪一版规则"。N-day/CVE 类订阅规则若未来引入，同样在此登记
    | 订阅版本（如 "nday-2026.09"）。
    |
    */
    'meta' => [
        'version' => '1.1.0',
        'updated_at' => '2026-09-06',
    ],

    /*
    |--------------------------------------------------------------------------
    | 按类别停用（运营级灰度开关）
    |--------------------------------------------------------------------------
    |
    | category ∈ sqli | rce | lfi | webshell | scanner | infoleak | cmdi。
    | 某类规则误伤时的运营级灰度开关：只停用该类，不必全局关 WAF，也不必
    | 逐条加豁免。值的解析与小写归一由 PayloadScanner 负责。
    |
    */
    'disabled_categories' => env('WAF_DISABLED_CATEGORIES', ''),

    /*
    |--------------------------------------------------------------------------
    | 引擎限额（fail-closed）
    |--------------------------------------------------------------------------
    |
    | scan_block_size：单块扫描长度。超长载荷按此尺寸分块覆盖全部字节（块间
    | 重叠 scan_block_overlap 字节防跨边界漏检），既不让正则在超长字符串上回溯
    | 放大，也不给"把载荷藏在截断点之后"留绕过空间。
    |
    | max_depth：嵌套数组摊平深度上限。业务入参的合法嵌套远达不到这个层数，
    | 超限请求按固定规则拦截，而不是静默跳过深层内容。
    |
    */
    'limits' => [
        'scan_block_size' => 20000,
        'scan_block_overlap' => 256,
        'max_depth' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | 固定拦截规则（无需正则的 fail-closed 兜底）
    |--------------------------------------------------------------------------
    |
    | 触发条件成立即拒绝请求：无法被规则库完整、正确检查的输入一律不放行，
    | 与"密钥缺省即拒绝"同一原则。键名是规则名，值是日志与响应里呈现的名称。
    |
    */
    'fatal_rules' => [
        'depth_exceeded' => '嵌套层级超限',
        'invalid_encoding' => '无效字符编码',
    ],

    /*
    |--------------------------------------------------------------------------
    | 豁免路径
    |--------------------------------------------------------------------------
    |
    | 这些路径承载的正是"看起来像攻击载荷"的合法内容，必须豁免，否则必然误伤：
    |   - 文章/公告正文、工单内容：富文本 + 用户可能贴报错日志、SQL 片段
    |   - 上游回调：报文由对端决定，形态不可控，且已有签名校验保护
    |   - 日志查询接口：查询条件本身可能包含攻击特征字符串
    | 支持 Laravel 的 `Request::is()` 通配语法。
    |
    */
    'except' => [
        // 文章/公告正文：富文本 Markdown，帮助文档里出现 `<script>` 代码示例、
        // SQL 片段属正常内容。
        'api/v2/admin/content/articles*',
        // 工单正文：用户常贴报错日志、SQL 片段、HTML 示例；两侧同理由。
        'api/v2/admin/tickets*',
        'api/v2/client/tickets*',
        // 日志查询条件本身可能包含攻击特征字符串（管理端按内容检索攻击痕迹）。
        'api/v2/admin/logs*',
        // 支付回调：报文由外部网关（支付宝/易支付等）生成、形态不可控，且报文
        // 原文参与验签。一旦被 WAF 误拦即直接收不到钱，是资金级误伤面。
        'api/v2/client/payment/alipay/notify',
        'api/v2/client/payment/notify/*',
        // 实名认证回调：同为外部服务商回调，已有 verify.callback 签名 + nonce 保护。
        'api/v2/client/verification/callback',
        // 工单上游同步回调：内容为用户在客商系统里写的任意文本，且原文参与
        // legacy MD5 签名；VerifyTicketUpstreamCallbackSignature 已把门。
        'api/ticket_reply/sync',
        // zjmf 上游协议路径：报文由上游系统生成且整体参与 HMAC 签名。
        'api/v2/zjmf/*',
        // open API 无回调路由，保留此前预留；健康检查供探针使用，报文固定。
        'api/v2/open/callback*',
        'api/health',
        'api/ready',
    ],

    /*
    |--------------------------------------------------------------------------
    | 规则库
    |--------------------------------------------------------------------------
    |
    | 每条规则的字段：
    |   id       稳定标识（{category}-{描述}），用于日志检索与将来的按规则豁免；
    |            一经发布不得改名，只能废弃（enable=false）或以新 id 替代。
    |   name     中文名（日志与响应里呈现）。
    |   pattern  正则（不含分隔符，运行时以 # 包裹并加 i 修饰）。
    |   category sqli | rce | lfi | webshell | scanner | infoleak，
    |            对应 disabled_categories 的停用粒度。
    |   severity critical | high | medium | low（情报性质，供日志分级与盘点）。
    |   enable   可选，默认 true。置 false 表示该规则停用——停用属于运营变更，
    |            必须在 git 提交说明里写明误伤证据，禁止无记录地关闭规则。
    |   cve / references  可选，N-day/CVE 规则的元数据预留（cve 如 "CVE-2024-4577"，
    |            references 为参考链接数组）。当前规则库为通用特征，暂未使用；
    |            引入订阅型 CVE 规则时按此字段登记，schema 无需再变更。
    | 分组对应检测目标：query（GET 参数）、path（URI 路径）、body（POST 等请求体）、
    | cookie（Cookie 值）、ua（User-Agent）。
    |
    */
    'rules' => [

        // ---- GET 查询串 ----
        'query' => [
            ['id' => 'lfi-path-traversal', 'name' => '目录穿越', 'pattern' => '\.\./\.\./', 'category' => 'lfi', 'severity' => 'high'],
            ['id' => 'lfi-probe-passwd', 'name' => '敏感文件探测', 'pattern' => '(?:etc/\W*passwd|/proc/self/environ)', 'category' => 'lfi', 'severity' => 'critical'],
            ['id' => 'rce-stream-protocol', 'name' => 'PHP 流协议', 'pattern' => '(gopher|phar|zlib|dict|expect|input)://', 'category' => 'rce', 'severity' => 'critical'],
            ['id' => 'rce-code-func', 'name' => '代码执行函数', 'pattern' => '\b(?:eval|assert|shell_exec|passthru|proc_open|popen|base64_decode)\s*\(', 'category' => 'rce', 'severity' => 'critical'],
            ['id' => 'rce-superglobal', 'name' => '超全局变量探测', 'pattern' => '\$_(?:GET|POST|COOKIE|FILES|SESSION|ENV|SERVER|REQUEST)\[', 'category' => 'rce', 'severity' => 'critical'],
            ['id' => 'sqli-union-select', 'name' => 'SQL 联合注入', 'pattern' => 'union[\s/*]+select', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'sqli-boolean', 'name' => 'SQL 布尔注入', 'pattern' => '\s+(?:or|xor|and)\s+[\w\'"`]+\s*(?:=|<|>|like)', 'category' => 'sqli', 'severity' => 'high'],
            ['id' => 'sqli-time-based', 'name' => 'SQL 时间盲注', 'pattern' => '\b(?:sleep|benchmark|pg_sleep|waitfor\s+delay)\s*\(', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'sqli-information-schema', 'name' => 'SQL 元数据探测', 'pattern' => 'from\W+information_schema\W', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'sqli-into-outfile', 'name' => 'SQL 文件写入', 'pattern' => 'into\s+(?:dump|out)file', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'sqli-error-based', 'name' => 'SQL 报错注入', 'pattern' => '\b(?:extractvalue|updatexml|geometrycollection|multipolygon|linestring)\s*\(', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'sqli-file-read', 'name' => 'SQL 读文件', 'pattern' => '\b(?:load_file|@@version|@@datadir)\b', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'webshell-chopper', 'name' => '中国菜刀特征', 'pattern' => 'array_map\s*\(\s*["\']ass', 'category' => 'webshell', 'severity' => 'critical'],
            // 以下四条是规则库审计后的特征补强（业界通用 WAF 均覆盖，本库此前
            // 缺位）：auto_prepend/append_file 是 php.ini 注入特征；MySQL 版本
            // 注释（/*!5xxxx）是绕过 union 关键词匹配的经典混淆形态；管道执行
            // 与 PHP 序列化对象（O:N:"）是低误伤、高特异的攻击指纹。
            ['id' => 'rce-auto-prepend', 'name' => 'PHP 配置文件注入', 'pattern' => 'auto_(?:prepend|append)_file', 'category' => 'rce', 'severity' => 'high'],
            ['id' => 'sqli-mysql-version-comment', 'name' => 'MySQL 版本注释', 'pattern' => '/\*!5\d{4}', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'cmdi-pipe-exec', 'name' => '命令管道执行', 'pattern' => '\|\s*(?:sh|bash|nc|curl|wget)\b', 'category' => 'cmdi', 'severity' => 'critical'],
            ['id' => 'rce-php-deser', 'name' => 'PHP 反序列化特征', 'pattern' => 'O:\d+:"', 'category' => 'rce', 'severity' => 'high'],
        ],

        // ---- URI 路径 ----
        'path' => [
            ['id' => 'infoleak-config-files', 'name' => '敏感配置文件', 'pattern' => '\.(?:htaccess|user\.ini|bash_history|mysql_history|DS_Store)$', 'category' => 'infoleak', 'severity' => 'medium'],
            ['id' => 'infoleak-backup-files', 'name' => '源码/备份文件', 'pattern' => '\.(?:bak|old|sql|swp|swo|inc|mdb|war|class)$', 'category' => 'infoleak', 'severity' => 'medium'],
            ['id' => 'scanner-backup-archives', 'name' => '备份包探测', 'pattern' => '^/?(?:www|web|site|root|backup|data|db|wwwroot|vhost)\.(?:rar|zip|tar|tar\.gz|7z|sql)$', 'category' => 'scanner', 'severity' => 'low'],
            ['id' => 'webshell-filename', 'name' => 'WebShell 文件名', 'pattern' => '/(?:hack|shell|phpspy|webshell|cmd|c99|r57)\.(?:php|jsp|asp|aspx)$', 'category' => 'webshell', 'severity' => 'critical'],
            ['id' => 'webshell-upload-dir', 'name' => '上传目录脚本执行', 'pattern' => '^/?(?:uploads?|static|media|cache|attachments|avatar)/[^/]+\.(?:php|phtml|jsp|asp|aspx)$', 'category' => 'webshell', 'severity' => 'critical'],
            ['id' => 'infoleak-vcs-dir', 'name' => '版本控制目录泄露', 'pattern' => '/\.(?:git|svn|hg)/', 'category' => 'infoleak', 'severity' => 'medium'],
            ['id' => 'infoleak-env-file', 'name' => '环境文件泄露', 'pattern' => '/\.env(?:\.|$)', 'category' => 'infoleak', 'severity' => 'critical'],
        ],

        // ---- 请求体 ----
        'body' => [
            ['id' => 'lfi-path-traversal-body', 'name' => '目录穿越', 'pattern' => '\.\./\.\./\.\./', 'category' => 'lfi', 'severity' => 'high'],
            ['id' => 'lfi-probe-passwd-body', 'name' => '敏感文件探测', 'pattern' => '(?:etc/\W*passwd|/proc/self/environ)', 'category' => 'lfi', 'severity' => 'critical'],
            ['id' => 'rce-stream-protocol-body', 'name' => 'PHP 流协议', 'pattern' => '(gopher|phar|zlib|dict|expect)://', 'category' => 'rce', 'severity' => 'critical'],
            ['id' => 'rce-code-func-body', 'name' => '代码执行函数', 'pattern' => '\b(?:eval|assert|shell_exec|passthru|proc_open|popen)\s*\(', 'category' => 'rce', 'severity' => 'critical'],
            ['id' => 'sqli-union-select-body', 'name' => 'SQL 联合注入', 'pattern' => 'union[\s/*]+select', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'sqli-time-based-body', 'name' => 'SQL 时间盲注', 'pattern' => '\b(?:sleep|benchmark|pg_sleep)\s*\(\s*\d', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'sqli-information-schema-body', 'name' => 'SQL 元数据探测', 'pattern' => 'from\W+information_schema\W', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'sqli-into-outfile-body', 'name' => 'SQL 文件写入', 'pattern' => 'into\s+(?:dump|out)file', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'sqli-error-based-body', 'name' => 'SQL 报错注入', 'pattern' => '\b(?:extractvalue|updatexml)\s*\(', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'webshell-chopper-body', 'name' => '中国菜刀特征', 'pattern' => 'array_map\s*\(\s*["\']ass', 'category' => 'webshell', 'severity' => 'critical'],
            // 与 query 组同源的四类特征补强。
            ['id' => 'rce-auto-prepend-body', 'name' => 'PHP 配置文件注入', 'pattern' => 'auto_(?:prepend|append)_file', 'category' => 'rce', 'severity' => 'high'],
            ['id' => 'sqli-mysql-version-comment-body', 'name' => 'MySQL 版本注释', 'pattern' => '/\*!5\d{4}', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'cmdi-pipe-exec-body', 'name' => '命令管道执行', 'pattern' => '\|\s*(?:sh|bash|nc|curl|wget)\b', 'category' => 'cmdi', 'severity' => 'critical'],
            ['id' => 'rce-php-deser-body', 'name' => 'PHP 反序列化特征', 'pattern' => 'O:\d+:"', 'category' => 'rce', 'severity' => 'high'],
        ],

        // ---- Cookie ----
        'cookie' => [
            ['id' => 'rce-code-func-cookie', 'name' => '代码执行函数', 'pattern' => '\b(?:eval|assert|shell_exec|passthru|base64_decode)\s*\(', 'category' => 'rce', 'severity' => 'critical'],
            ['id' => 'rce-superglobal-cookie', 'name' => '超全局变量探测', 'pattern' => '\$_(?:GET|POST|COOKIE|FILES|SESSION|ENV|SERVER)\[', 'category' => 'rce', 'severity' => 'critical'],
            ['id' => 'sqli-union-select-cookie', 'name' => 'SQL 联合注入', 'pattern' => 'union[\s/*]+select', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'sqli-information-schema-cookie', 'name' => 'SQL 元数据探测', 'pattern' => 'from\W+information_schema\W', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'rce-stream-protocol-cookie', 'name' => 'PHP 流协议', 'pattern' => '(gopher|phar|zlib|dict)://', 'category' => 'rce', 'severity' => 'critical'],
        ],

        // ---- User-Agent ----
        'ua' => [
            // 只匹配自动化安全工具，不含正常浏览器/搜索引擎爬虫
            ['id' => 'scanner-tools', 'name' => '扫描器探测', 'pattern' => '\b(?:sqlmap|nmap|nikto|havij|acunetix|netsparker|w3af|dirbuster|hydra|antSword|pangolin|zgrab|masscan|WPScan)\b', 'category' => 'scanner', 'severity' => 'low'],
        ],

        // ---- 请求头（cookie 与 UA 已单列，此处扫描其余全部头）----
        // 攻击载荷可藏在 X-Forwarded-For、Referer 或任意自定义头里进入日志与
        // 下游解析。头值形态多为短 ASCII，只保留高特异规则，避免
        // Accept-Language 等正常头误伤。
        'header' => [
            ['id' => 'sqli-union-select-header', 'name' => 'SQL 联合注入', 'pattern' => 'union[\s/*]+select', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'sqli-time-based-header', 'name' => 'SQL 时间盲注', 'pattern' => '\b(?:sleep|benchmark|pg_sleep|waitfor\s+delay)\s*\(', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'sqli-information-schema-header', 'name' => 'SQL 元数据探测', 'pattern' => 'from\W+information_schema\W', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'sqli-mysql-version-comment-header', 'name' => 'MySQL 版本注释', 'pattern' => '/\*!5\d{4}', 'category' => 'sqli', 'severity' => 'critical'],
            ['id' => 'rce-code-func-header', 'name' => '代码执行函数', 'pattern' => '\b(?:eval|assert|shell_exec|passthru|proc_open|popen)\s*\(', 'category' => 'rce', 'severity' => 'critical'],
            ['id' => 'rce-stream-protocol-header', 'name' => 'PHP 流协议', 'pattern' => '(gopher|phar|zlib|dict|expect|input)://', 'category' => 'rce', 'severity' => 'critical'],
            ['id' => 'rce-php-deser-header', 'name' => 'PHP 反序列化特征', 'pattern' => 'O:\d+:"', 'category' => 'rce', 'severity' => 'high'],
            ['id' => 'lfi-path-traversal-header', 'name' => '目录穿越', 'pattern' => '\.\./\.\./', 'category' => 'lfi', 'severity' => 'high'],
            ['id' => 'cmdi-pipe-exec-header', 'name' => '命令管道执行', 'pattern' => '\|\s*(?:sh|bash|nc|curl|wget)\b', 'category' => 'cmdi', 'severity' => 'critical'],
        ],
    ],
];
