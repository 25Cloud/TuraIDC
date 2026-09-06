<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 入站 WAF（Web 应用防火墙）
|--------------------------------------------------------------------------
|
| 参照异次元发卡（acg-faka）的 Firewall 结构落地：用正则规则库在请求进入业务
| 逻辑之前拦截明显的攻击载荷（SQL 注入、命令执行、目录穿越、扫描器探测）。
|
| 定位说明——本层是**纵深防御的外层**，不是唯一防线，也不能替代：
|   - Eloquent 参数绑定（防 SQL 注入的真正手段）
|   - FormRequest 输入验证（业务合法性）
|   - RichHtmlSanitizer 富文本白名单净化（防存储型 XSS 的真正手段）
| 正则黑名单天然可被绕过，它的价值在于挡掉自动化扫描与批量探测，降低噪音与
| 被踩到未知漏洞的概率，而不是当作"有它就安全了"。
|
| 与 acg-faka 规则库的差异（有意为之）：
|   - 剔除 ThinkPHP payload（invokefunction / call_user_func_array / \think\）
|     与 Smarty SSTI 规则：本项目是 Laravel + 无模板注入面，留着只会误伤正常参数。
|   - 剔除 XSS 标签/事件属性规则：富文本字段（文章正文、工单内容）合法包含 HTML，
|     在入站层拦 `<div` 会直接打断业务；XSS 交给 RichHtmlSanitizer 白名单净化。
|   - 放宽部分过于宽泛的 SQL 函数名规则（如裸 `substr(` / `user(`）：这类词在
|     正常文本里出现概率高，误伤代价大于收益。
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
    | 每条规则：['name' => 规则名, 'pattern' => 正则（不含分隔符，运行时以 # 包裹并加 i 修饰）]
    | 分组对应检测目标：query（GET 参数）、path（URI 路径）、body（POST 等请求体）、
    | cookie（Cookie 值）、ua（User-Agent）。
    |
    */
    'rules' => [

        // ---- GET 查询串 ----
        'query' => [
            ['name' => '目录穿越', 'pattern' => '\.\./\.\./'],
            ['name' => '敏感文件探测', 'pattern' => '(?:etc/\W*passwd|/proc/self/environ)'],
            ['name' => 'PHP 流协议', 'pattern' => '(gopher|phar|zlib|dict|expect|input)://'],
            ['name' => '代码执行函数', 'pattern' => '\b(?:eval|assert|shell_exec|passthru|proc_open|popen|base64_decode)\s*\('],
            ['name' => '超全局变量探测', 'pattern' => '\$_(?:GET|POST|COOKIE|FILES|SESSION|ENV|SERVER|REQUEST)\['],
            ['name' => 'SQL 联合注入', 'pattern' => 'union[\s/*]+select'],
            ['name' => 'SQL 布尔注入', 'pattern' => '\s+(?:or|xor|and)\s+[\w\'"`]+\s*(?:=|<|>|like)'],
            ['name' => 'SQL 时间盲注', 'pattern' => '\b(?:sleep|benchmark|pg_sleep|waitfor\s+delay)\s*\('],
            ['name' => 'SQL 元数据探测', 'pattern' => 'from\W+information_schema\W'],
            ['name' => 'SQL 文件写入', 'pattern' => 'into\s+(?:dump|out)file'],
            ['name' => 'SQL 报错注入', 'pattern' => '\b(?:extractvalue|updatexml|geometrycollection|multipolygon|linestring)\s*\('],
            ['name' => 'SQL 读文件', 'pattern' => '\b(?:load_file|@@version|@@datadir)\b'],
            ['name' => '中国菜刀特征', 'pattern' => 'array_map\s*\(\s*["\']ass'],
        ],

        // ---- URI 路径 ----
        'path' => [
            ['name' => '敏感配置文件', 'pattern' => '\.(?:htaccess|user\.ini|bash_history|mysql_history|DS_Store)$'],
            ['name' => '源码/备份文件', 'pattern' => '\.(?:bak|old|sql|swp|swo|inc|mdb|war|class)$'],
            ['name' => '备份包探测', 'pattern' => '^/?(?:www|web|site|root|backup|data|db|wwwroot|vhost)\.(?:rar|zip|tar|tar\.gz|7z|sql)$'],
            ['name' => 'WebShell 文件名', 'pattern' => '/(?:hack|shell|phpspy|webshell|cmd|c99|r57)\.(?:php|jsp|asp|aspx)$'],
            ['name' => '上传目录脚本执行', 'pattern' => '^/?(?:uploads?|static|media|cache|attachments|avatar)/[^/]+\.(?:php|phtml|jsp|asp|aspx)$'],
            ['name' => '版本控制目录泄露', 'pattern' => '/\.(?:git|svn|hg)/'],
            ['name' => '环境文件泄露', 'pattern' => '/\.env(?:\.|$)'],
        ],

        // ---- 请求体 ----
        'body' => [
            ['name' => '目录穿越', 'pattern' => '\.\./\.\./\.\./'],
            ['name' => '敏感文件探测', 'pattern' => '(?:etc/\W*passwd|/proc/self/environ)'],
            ['name' => 'PHP 流协议', 'pattern' => '(gopher|phar|zlib|dict|expect)://'],
            ['name' => '代码执行函数', 'pattern' => '\b(?:eval|assert|shell_exec|passthru|proc_open|popen)\s*\('],
            ['name' => 'SQL 联合注入', 'pattern' => 'union[\s/*]+select'],
            ['name' => 'SQL 时间盲注', 'pattern' => '\b(?:sleep|benchmark|pg_sleep)\s*\(\s*\d'],
            ['name' => 'SQL 元数据探测', 'pattern' => 'from\W+information_schema\W'],
            ['name' => 'SQL 文件写入', 'pattern' => 'into\s+(?:dump|out)file'],
            ['name' => 'SQL 报错注入', 'pattern' => '\b(?:extractvalue|updatexml)\s*\('],
            ['name' => '中国菜刀特征', 'pattern' => 'array_map\s*\(\s*["\']ass'],
        ],

        // ---- Cookie ----
        'cookie' => [
            ['name' => '代码执行函数', 'pattern' => '\b(?:eval|assert|shell_exec|passthru|base64_decode)\s*\('],
            ['name' => '超全局变量探测', 'pattern' => '\$_(?:GET|POST|COOKIE|FILES|SESSION|ENV|SERVER)\['],
            ['name' => 'SQL 联合注入', 'pattern' => 'union[\s/*]+select'],
            ['name' => 'SQL 元数据探测', 'pattern' => 'from\W+information_schema\W'],
            ['name' => 'PHP 流协议', 'pattern' => '(gopher|phar|zlib|dict)://'],
        ],

        // ---- User-Agent ----
        'ua' => [
            // 只匹配自动化安全工具，不含正常浏览器/搜索引擎爬虫
            ['name' => '扫描器探测', 'pattern' => '\b(?:sqlmap|nmap|nikto|havij|acunetix|netsparker|w3af|dirbuster|hydra|antSword|pangolin|zgrab|masscan|WPScan)\b'],
        ],
    ],
];
