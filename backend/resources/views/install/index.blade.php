<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>图拉云 TuraIDC 安装向导</title>
    <style>
        :root { --brand: #0052d9; --ok: #2ba471; --bad: #d54941; --line: #e7e7e7; --text: #333; --muted: #8a8a8a; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "PingFang SC", "Microsoft YaHei", system-ui, sans-serif; background: #f3f4f6; color: var(--text); min-height: 100vh; display: flex; justify-content: center; padding: 40px 16px; }
        .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.06); width: 100%; max-width: 780px; padding: 32px 40px; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        .sub { color: var(--muted); font-size: 13px; margin-bottom: 24px; }
        .steps { display: flex; gap: 8px; margin-bottom: 28px; }
        .steps span { flex: 1; text-align: center; font-size: 13px; color: var(--muted); padding: 8px 0; border-bottom: 2px solid var(--line); }
        .steps span.active { color: var(--brand); border-color: var(--brand); font-weight: 600; }
        .steps span.done { color: var(--ok); border-color: var(--ok); }
        .panel { display: none; }
        .panel.show { display: block; }
        .req-list { border: 1px solid var(--line); border-radius: 6px; overflow: hidden; margin-bottom: 16px; }
        .req-list li { display: flex; align-items: center; gap: 10px; padding: 10px 14px; font-size: 13px; border-bottom: 1px solid var(--line); list-style: none; }
        .req-list li:last-child { border-bottom: none; }
        .tag { font-size: 12px; padding: 2px 10px; border-radius: 10px; }
        .tag.ok { color: var(--ok); background: #e8f5ef; }
        .tag.bad { color: var(--bad); background: #fbeceb; }
        .msg { color: var(--muted); margin-left: auto; font-size: 12px; }
        h2 { font-size: 15px; margin: 18px 0 10px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        label { display: block; font-size: 13px; margin-bottom: 4px; color: #555; }
        input { width: 100%; padding: 9px 12px; border: 1px solid var(--line); border-radius: 6px; font-size: 13px; outline: none; }
        input:focus { border-color: var(--brand); }
        .full { grid-column: 1 / -1; }
        .hint { font-size: 12px; color: var(--muted); margin-top: 4px; }
        .btns { display: flex; gap: 12px; margin-top: 24px; }
        button { padding: 10px 28px; border-radius: 6px; font-size: 14px; cursor: pointer; border: none; }
        .primary { background: var(--brand); color: #fff; }
        .primary:disabled { background: #a8c0ea; cursor: not-allowed; }
        .ghost { background: #fff; color: var(--brand); border: 1px solid var(--brand); }
        .log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, monospace; font-size: 12px; border-radius: 6px; padding: 14px; max-height: 320px; overflow-y: auto; white-space: pre-wrap; margin-bottom: 16px; }
        .log .ok { color: #6bc46d; }
        .log .err { color: #f0705f; }
        .alert { padding: 12px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; display: none; }
        .alert.err { background: #fbeceb; color: var(--bad); display: block; }
        .alert.ok { background: #e8f5ef; color: var(--ok); display: block; }
        .done-box { border: 1px solid #cfe6db; background: #f2faf6; border-radius: 6px; padding: 16px; font-size: 13px; line-height: 1.9; margin-bottom: 16px; }
        .done-box strong { color: var(--ok); }
        .cred { font-family: Consolas, monospace; background: #fff; border: 1px solid var(--line); border-radius: 4px; padding: 1px 8px; }
        ol.next { padding-left: 20px; font-size: 13px; color: #555; line-height: 2; }
        .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid #fff6; border-top-color: #fff; border-radius: 50%; animation: spin .7s linear infinite; vertical-align: -2px; margin-right: 6px; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="card">
    <h1>图拉云 TuraIDC 安装向导</h1>
    <div class="sub">安装程序将生成 .env、初始化数据库并创建管理员账号；已安装系统无法重复进入本向导。</div>

    <div class="steps">
        <span id="step-1" class="active">1. 环境检测</span>
        <span id="step-2">2. 配置信息</span>
        <span id="step-3">3. 执行安装</span>
        <span id="step-4">4. 安装完成</span>
    </div>

    <div id="alert" class="alert"></div>

    <div id="panel-1" class="panel show">
        <div class="req-list"><ul id="req-list"></ul></div>
        <div class="btns">
            <button class="primary" id="btn-req-refresh">重新检测</button>
            <button class="primary" id="btn-req-next" disabled>下一步</button>
        </div>
    </div>

    <div id="panel-2" class="panel">
        <h2>数据库（MySQL 8）</h2>
        <div class="grid">
            <div><label>主机</label><input id="db_host" value="127.0.0.1"></div>
            <div><label>端口</label><input id="db_port" value="3306"></div>
            <div><label>数据库名</label><input id="db_database" placeholder="idc"></div>
            <div><label>用户名</label><input id="db_username" placeholder="idc"></div>
            <div class="full"><label>密码</label><input id="db_password" type="password" placeholder="可为空"><div class="hint">目标库不存在时将自动创建；非空库会跳过结构导入并执行增量迁移。</div></div>
        </div>
        <h2>Redis</h2>
        <div class="grid">
            <div><label>主机</label><input id="redis_host" value="127.0.0.1"></div>
            <div><label>端口</label><input id="redis_port" value="6379"></div>
            <div class="full"><label>密码</label><input id="redis_password" type="password" placeholder="可为空"></div>
        </div>
        <h2>站点地址（四个 origin 必须互不相同、同协议）</h2>
        <div class="grid">
            <div><label>后端 API 地址</label><input id="app_url" placeholder="https://api.example.com"></div>
            <div><label>官网门户地址</label><input id="frontend_url" placeholder="https://www.example.com"></div>
            <div><label>用户控制台地址</label><input id="client_console_url" placeholder="https://console.example.com"></div>
            <div><label>管理端地址</label><input id="admin_url" placeholder="https://admin.example.com"></div>
        </div>
        <h2>管理员账号</h2>
        <div class="grid">
            <div><label>用户名</label><input id="admin_username" placeholder="字母开头，3-32 位"></div>
            <div><label>邮箱</label><input id="admin_email" placeholder="admin@example.com"></div>
            <div class="full"><label>密码</label><input id="admin_password" type="password" placeholder="至少 12 位"><div class="hint">安装完成后请立即登录管理端并妥善保管。</div></div>
        </div>
        <div class="btns">
            <button class="ghost" id="btn-back-1">上一步</button>
            <button class="ghost" id="btn-test">测试连接</button>
            <button class="primary" id="btn-install">开始安装</button>
        </div>
    </div>

    <div id="panel-3" class="panel">
        <div class="log" id="install-log">正在准备安装…</div>
        <div class="hint">安装包含数据库结构与默认数据写入，视服务器性能可能需要数十秒到数分钟，请勿关闭页面。</div>
    </div>

    <div id="panel-4" class="panel">
        <div class="done-box" id="done-box"></div>
        <h2>后续步骤</h2>
        <ol class="next">
            <li>登录管理端，确认管理员账号可用，并尽快修改默认通知、支付等配置。</li>
            <li>按部署文档构建并发布三端前端（frontend-admin-v3 / frontend-user-v3-www / frontend-user-v4-console）。</li>
            <li>配置队列 Worker 与调度任务（schedule:run、queue:work），详见部署文档。</li>
            <li>确认 storage/app/install.lock 存在且不可被 Web 访问，防止向导被再次触发。</li>
        </ol>
    </div>
</div>

<script>
(function () {
    'use strict';

    var $ = function (id) { return document.getElementById(id); };
    var alertBox = $('alert');
    var logs = [];

    function showAlert(type, message) {
        alertBox.className = 'alert ' + type;
        alertBox.textContent = message;
    }
    function clearAlert() { alertBox.className = 'alert'; alertBox.textContent = ''; }

    function goStep(n) {
        for (var i = 1; i <= 4; i++) {
            $('panel-' + i).className = 'panel' + (i === n ? ' show' : '');
            var step = $('step-' + i);
            step.className = i < n ? 'done' : (i === n ? 'active' : '');
        }
        if (n !== 2) clearAlert();
    }

    function appendLog(message, type) {
        logs.push({ message: message, type: type || '' });
        var box = $('install-log');
        var line = document.createElement('div');
        if (type) line.className = type;
        line.textContent = '[install] ' + message;
        box.appendChild(line);
        box.scrollTop = box.scrollHeight;
    }

    function api(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {})
        }).then(function (response) {
            return response.json().then(function (data) { return { status: response.status, data: data }; });
        });
    }

    function payload() {
        return {
            db_host: $('db_host').value.trim(),
            db_port: parseInt($('db_port').value, 10) || 3306,
            db_database: $('db_database').value.trim(),
            db_username: $('db_username').value.trim(),
            db_password: $('db_password').value,
            redis_host: $('redis_host').value.trim(),
            redis_port: parseInt($('redis_port').value, 10) || 6379,
            redis_password: $('redis_password').value,
            app_name: '图拉云',
            app_url: $('app_url').value.trim(),
            frontend_url: $('frontend_url').value.trim(),
            client_console_url: $('client_console_url').value.trim(),
            admin_url: $('admin_url').value.trim(),
            admin_username: $('admin_username').value.trim(),
            admin_email: $('admin_email').value.trim(),
            admin_password: $('admin_password').value
        };
    }

    function loadRequirements() {
        $('btn-req-next').disabled = true;
        $('req-list').innerHTML = '';
        api('/install/requirements').then(function (result) {
            if (result.status === 404) { window.location.reload(); return; }
            var data = result.data && result.data.data;
            if (!data) { showAlert('err', '环境检测接口异常'); return; }
            var allPassed = data.passed;
            data.items.forEach(function (item) {
                var li = document.createElement('li');
                var tag = document.createElement('span');
                tag.className = 'tag ' + (item.passed ? 'ok' : 'bad');
                tag.textContent = item.passed ? '通过' : '未通过';
                var name = document.createElement('span');
                name.textContent = item.name;
                li.appendChild(tag); li.appendChild(name);
                if (item.message) {
                    var msg = document.createElement('span');
                    msg.className = 'msg'; msg.textContent = item.message;
                    li.appendChild(msg);
                }
                $('req-list').appendChild(li);
            });
            $('btn-req-next').disabled = !allPassed;
            if (!allPassed) showAlert('err', '存在未通过的必选项，请先修复服务器环境后重新检测。');
        }).catch(function () { showAlert('err', '网络异常，无法完成环境检测'); });
    }

    $('btn-req-refresh').addEventListener('click', function () { clearAlert(); loadRequirements(); });
    $('btn-req-next').addEventListener('click', function () { goStep(2); });
    $('btn-back-1').addEventListener('click', function () { goStep(1); });

    $('btn-test').addEventListener('click', function () {
        clearAlert();
        this.disabled = true;
        this.textContent = '检测中…';
        var self = this;
        api('/install/test', payload()).then(function (result) {
            var data = result.data && result.data.data;
            if (!data) { showAlert('err', '连接测试接口异常'); return; }
            var parts = [];
            parts.push('数据库：' + data.database.message);
            parts.push('Redis：' + data.redis.message);
            showAlert(data.database.ok && data.redis.ok ? 'ok' : 'err', parts.join('；'));
        }).catch(function () { showAlert('err', '网络异常，无法完成连接测试'); }).finally(function () {
            self.disabled = false; self.textContent = '测试连接';
        });
    });

    $('btn-install').addEventListener('click', function () {
        clearAlert();
        logs = [];
        $('install-log').innerHTML = '';
        goStep(3);
        appendLog('提交安装请求…');
        var button = this;
        button.disabled = true;
        api('/install/run', payload()).then(function (result) {
            var data = result.data;
            if (logs.length === 0 && data && data.data && data.data.logs) {
                data.data.logs.forEach(function (message) { appendLog(message); });
            }
            if (result.status !== 200 || !data || data.code !== 0) {
                appendLog((data && data.message) || '安装失败', 'err');
                appendLog('请修正配置后重试；已生成的 .env 将被下次尝试覆盖。', 'err');
                goStep(2);
                showAlert('err', (data && data.message) || '安装失败');
                button.disabled = false;
                return;
            }
            appendLog('安装完成', 'ok');
            var box = $('done-box');
            box.textContent = '';
            var title = document.createElement('strong');
            title.textContent = '安装成功！';
            box.appendChild(title);
            [['管理员用户名：', data.data.admin_username], ['管理员邮箱：', data.data.admin_email]]
                .forEach(function (pair) {
                    box.appendChild(document.createElement('br'));
                    box.appendChild(document.createTextNode(pair[0]));
                    var value = document.createElement('span');
                    value.className = 'cred';
                    value.textContent = pair[1];
                    box.appendChild(value);
                });
            box.appendChild(document.createElement('br'));
            box.appendChild(document.createTextNode('管理端地址：'));
            var link = document.createElement('a');
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = data.data.admin_url;
            if (/^https?:\/\//i.test(data.data.admin_url)) {
                link.href = data.data.admin_url;
            }
            box.appendChild(link);
            goStep(4);
        }).catch(function () {
            appendLog('网络中断，安装结果未知；请勿直接重试，先检查数据库与管理员是否已创建。', 'err');
            goStep(2);
            button.disabled = false;
        });
    });

    loadRequirements();
})();
</script>
</body>
</html>
