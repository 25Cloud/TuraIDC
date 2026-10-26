---
source: develop/api/index.md
sourceHash: 0e6d47900a303c1e4deb01b72da02b9007382f2f65be5aa49f60679e59babb84
generated: zh-hk
---

<!-- 本檔案由 docs-i18n.mjs trad zh-hk 從 docs/develop/api/index.md 產生，請勿手動編輯。 -->
# API 文檔

TuraIDC 的接口契約分三層維護，職責不重疊：先看格式規範，再用清單導航找業務域，最後在自動生成的清單裡查具體路由。

## 1. 三層入口

| 文檔                                    | 回答的問題                                       | 維護方式               |
| --------------------------------------- | ------------------------------------------------ | ---------------------- |
| [接口格式規範](format.md)               | 響應結構、鑑權、分頁、校驗與版本邊界是什麼       | 手工維護               |
| [API 清單導航](catalog-navigation.md)   | 某個業務域有哪些能力、核心業務流程映射到哪些接口 | 手工維護               |
| [後端 API 清單](/develop/api/backend-api-catalog) | 具體路由、方法與控制器動作的完整枚舉             | 腳本生成，禁止手工編輯 |

清單導航與格式規範都以重新生成後的清單為準；三者衝突時按此順序回退到代碼與測試。

## 2. 刷新自動清單

路由或控制器動作變化後必須重刷，產物不入手工維護範圍：

```bash
php backend/scripts/export_api_inventory.php
```

刷新後執行文檔校驗，確認目錄與鏈接仍然完整：

```bash
pnpm run docs:check
```

## 3. 接口分層約定

- 管理端接口統一落在 `/api/v2/admin/*`，用戶端在 `/api/v2/client/*`，站點公開數據在 `/api/v2/site/*`。
- 開放 API（給外部系統調用的密鑰認證接口）與魔方上游協議是獨立入口，見[魔方上游協議](../integrations/zjmf-upstream-api.md)與[後端規範](../backend.md)。
- 控制器保持薄層：校驗進 FormRequest，業務進 Service，第三方調用進 Driver 或插件。
- 越權與鑑權問題必須在認證授權層解決，不能靠 WAF 兜底。

## 關聯文檔

- [後端規範](../backend.md)：控制器分層、服務邊界與治理要求。
- [本地對接説明](../integrations/local-integration.md)：本地聯調上游接口的入口與邊界。
