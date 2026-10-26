---
source: develop/local-development.md
sourceHash: 5007427a2f90628d183db4330d00d80cc6bae8a00138e5776971808ac531f5b9
generated: zh-hk
---

<!-- 本檔案由 docs-i18n.mjs trad zh-hk 從 docs/develop/local-development.md 產生，請勿手動編輯。 -->
# 啟動指南

本文説明本地啟動後端與三個前端、數據庫初始化與常用驗證命令；生產部署見各部署指南。

## 1. 環境要求

- PHP 8.3+
- Composer
- MySQL 8
- Redis
- Node.js 20.19+（`frontend-admin-v3` 與 `frontend-user-v4-console` 的 package 約束）

本地聯調統一使用 `127.0.0.1`，不要混用 `localhost`。

## 2. 後端

```bash
cd backend
composer install
php artisan key:generate
php artisan migrate
php artisan app:serve
```

### 快取與 Redis 配置

本地開發默認 `CACHE_STORE=file`（`.env` 中配置），單進程運行時足夠。

**生產環境必須設置 `CACHE_STORE=redis`**：

- 分佈式鎖（`Cache::lock`）在 file 驅動下不支持跨進程互斥
- 支付回調、訂單創建、返利計算等關鍵業務依賴 Redis 鎖保證冪等
- Redis 不可用時這些操作可能併發衝突導致數據錯誤

檢查 Redis 是否運行：

```bash
redis-cli ping  # 应返回 PONG
```

`php artisan app:serve` 是本項目本地後端統一入口，會拉起 Laravel HTTP、VNC Relay 和業務隊列 Worker。不要用 `php artisan serve` 替代。

需要本地同時帶調度時，建議讓 Relay 獨立運行：

```bash
cd backend
php artisan app:serve --with-schedule --without-vnc
php artisan vnc:relay
```

帶調度時，`app:serve --with-schedule --without-vnc` 不再另起業務隊列 Worker；每分鐘 `schedule:run` 驅動心跳派發（15 分鐘槽位去重）與 `queue:drain` 後台消費，隊列按 `provision`、業務組、`automation` 三個隊列組分別建 Worker，drain 鎖保證同一隊列不併發。Relay 單獨進程退出不會影響 HTTP 和調度入口。

生產環境不使用 `app:serve` 常駐，PHP-FPM 指向 `backend/public`，寶塔每分鐘執行：

```bash
cd backend
php artisan schedule:run
```

## 3. 前端

推薦在倉庫根目錄使用 pnpm workspace 腳本：

```bash
pnpm run dev:admin-v3
pnpm run dev:user-v3-www
pnpm run dev:user-v4-console
pnpm run dev:docs
```

也可以進入子項目：

```bash
cd frontend-admin-v3
pnpm run dev

cd frontend-user-v3-www
pnpm run dev

cd frontend-user-v4-console
pnpm run dev
```

當前真實前端目錄：

- `frontend-admin-v3`：管理端，TDesign Vue Next，默認端口以 Vite 配置為準。
- `frontend-user-v3-www`：官網與用戶入口，Element Plus。
- `frontend-user-v4-console`：新版用戶控制台，TDesign Vue Next。
- `shared`：跨端共享包。
- `docs-web`：獨立 VitePress 官方文檔站，內容直接讀取 `docs/`。

## 4. 構建與驗證

```bash
# 全部当前前端
pnpm run build:frontends
pnpm run typecheck:frontends
pnpm run test:frontends
pnpm run verify:frontends

# 单项目
pnpm run build:admin-v3
pnpm run build:user-v3-www
pnpm run build:user-v4-console
pnpm run build:docs
pnpm run docs:check
```

後端驗證：

```bash
cd backend
php artisan test
```

文檔或配置類改動至少自檢內容是否與當前目錄、腳本和代碼一致。

## 5. 數據庫初始化與遷移

初始化新庫和舊庫遷移屬於高影響操作，不由代理自動執行：

```bash
python backend/scripts/install_db.py
python backend/scripts/migrate_legacy_dump.py --dump "你的dump文件.sql" --dry-run
python backend/scripts/migrate_legacy_dump.py --dump "你的dump文件.sql"
```

執行前必須確認目標庫、備份和 `.env`。

## 關聯文檔

- [測試指南](testing.md)：測試環境與驗證方法。
- [部署指南](../guide/deploy/generic.md)：生產部署全流程。
- [架構現狀説明](architecture.md)：本地與生產運行模型。
