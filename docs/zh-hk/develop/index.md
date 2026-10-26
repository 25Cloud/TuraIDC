---
source: develop/index.md
sourceHash: a1204138a32a1d88f56587d997bbeca49b97e4a2c04f0c1fdce183591234fbfe
generated: zh-hk
---

<!-- 本檔案由 docs-i18n.mjs trad zh-hk 從 docs/develop/index.md 產生，請勿手動編輯。 -->
# 開發者文檔

面向二次開發、上游對接與倉庫貢獻者：運行中的系統結構、三端工程規範、數據庫與接口契約、插件擴展點，以及本地開發與測試。只負責部署和運營這套系統，請進[使用者文檔](../guide/index.md)。

## 1. 按目的進入

| 目的                           | 入口                                                                                                 |
| ------------------------------ | ---------------------------------------------------------------------------------------------------- |
| 先搞清楚系統怎麼跑             | [系統架構](architecture.md)                                                                          |
| 改後端 / 前端 / 視覺           | [後端規範](backend.md)、[前端規範](frontend.md)、[視覺與交互規範](design.md)                         |
| 查表結構、SQL 兼容約束         | [數據庫快照](/develop/database)、[MySQL 版本兼容基線](mysql-compatibility.md)                              |
| 對接或改造接口                 | [API 文檔](api/index.md)、[後端 API 清單](/develop/api/backend-api-catalog)                                |
| 寫插件（供應商 / 支付 / 短信） | [插件開發](plugins/index.md)、[插件包規範](plugins/package-spec.md)                                  |
| 與上游系統互相打通             | [魔方上游協議](integrations/zjmf-upstream-api.md)、[本地對接説明](integrations/local-integration.md) |
| 本地跑起來並跑測試             | [本地啟動](local-development.md)、[測試指南](testing.md)                                             |
| 提交改動之前                   | [安全開發規範](security.md)、[工程踩坑記錄](pitfalls.md)、[參與貢獻](contributing.md)                |

## 2. 事實優先級與生成物

衝突時依次以運行代碼與測試、實庫 `information_schema`、自動生成文檔、本目錄規範為準。兩份自動生成物**禁止手工編輯**，只能重刷腳本：

```bash
php backend/scripts/export_database_structure.php   # 刷新数据库快照 develop/database.md
php backend/scripts/export_api_inventory.php        # 刷新后端 API 清单
```

## 3. 改動前必讀

- [後端規範](backend.md) 與 [前端規範](frontend.md)：控制器保持薄層，第三方調用進 Service/Driver/插件，三端 UI 框架不混用。
- [安全開發規範](security.md)：WAF 只是外層防禦，鑑權與越權必須在認證授權層修；簽名或令牌密鑰讀到空值必須拒絕服務。
- [MySQL 版本兼容基線](mysql-compatibility.md)：SQL 必須同時兼容 MySQL 5.7.44 與 8.x，禁用窗口函數、CTE 等 8.0 專屬語法。
- [工程踩坑記錄](pitfalls.md)：改代碼、改文案、跑測試、提交與增量部署環節的實測坑。

## 關聯文檔

- [使用者文檔](../guide/index.md)：部署、配置與日常運營手冊。
