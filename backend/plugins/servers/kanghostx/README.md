# 康乐虚拟主机插件

该插件把 `kanghostx` 原模块接入 EwyFinance 的 `upstream` 插件运行时，provider key 为 `kanghostx`。

## 接口来源

原模块位于仓库根目录 `kanghostx/`，核心调用方式：

- 接口入口：`{panel}/api/index.php`
- 固定参数：`c=whm`、`json=1`
- 签名：`s = md5(a + accesshash + r)`
- 随机数：`r` 为 6 位数字

已映射动作：

| 原动作 | 康乐 API `a` | 本插件方法 |
| --- | --- | --- |
| 连接测试 | `info` | `login()` / `getUserProfile()` |
| 开通虚拟主机 | `add_vh` | `provisionOrder()` |
| 升降级配置 | `add_vh&edit=1` | `changePackage()` 客户端封装 |
| 查询虚拟主机 | `getVh` | `getHostDetail()` / `syncServiceStatuses()` |
| 暂停/恢复 | `update_vh` | `powerAction()` / `renewHost()` |
| 删除 | `del_vh` | 客户端封装 |
| 重置密码 | `change_password` | `resetPassword()` |

## 配置

在后台供应商中选择插件提供商 `kanghostx` 后填写：

- `api_url`：康乐面板根地址，例如 `http://1.2.3.4:3312`。插件会请求 `/api/index.php`。
- `api_key`：康乐 `accesshash`，加密保存。

空间、数据库、月流量、域名数、FTP、伪静态、日志、SSI、端口、模块等套餐参数不放在供应商配置里。请在商品目录页先绑定康乐接口提供商，再在“产品配置”中拉取或维护康乐虚拟主机模板字段。

## 账号规则

当前服务控制台只按整数 `upstream_host_id` 传递实例标识。为保证后续查询和控制可反推康乐账号，插件固定使用：

```text
康乐虚拟主机账号 = cw{service_id}
upstream_host_id = service_id
```

不要手动把同一服务的康乐账号改成其他格式，否则控制台查询、暂停、重置密码会找不到虚拟主机。

## 能力边界

插件提供：

- 自动开通
- 续费后恢复
- 状态同步
- 详情查询
- 暂停/恢复
- 重置密码
- 本地康乐虚拟主机商品模板和产品配置模板

插件不提供：

- VNC
- 重装系统
- NAT/安全组
- 上游真实账单支付

订单、账单、服务状态、审计日志仍由 EwyFinance 平台负责。
