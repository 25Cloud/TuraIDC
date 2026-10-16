# 修改记录 2026-09-04：管理端实名编辑 + 代理分组「无代理分组」 + 实例价格重置

> 基线：本补丁是 `增量补丁-20260902-管理端编辑用户资料保存修复.patch` 之后的下一个增量包。
> 生产/测试环境若已应用 20260902 补丁，按顺序打 `增量补丁-20260904-实名编辑无代理组与实例价格重置.patch` 即可。
> 该补丁同时包含 1 个**新增后端文件**（`UpdateVerificationRequest.php`），应用时需文件系统可写。

---

## 一、代码变更

### 1. 管理端可修改用户实名状态/信息

- `backend/app/Http/Controllers/Admin/V2/VerificationController.php`
  新增 `update()`：写操作日志（module=`verification`，action=`verification.update`），返回脱敏结果。
- `backend/app/Http/Requests/Admin/V2/Verification/UpdateVerificationRequest.php`（**新增文件**）
  校验实名状态 ∈ 0/2/3/5，证件号 15-24 位字母数字；至少提交一项变更；名称/说明过 `TextSanitizer::clean`。
- `backend/app/Services/Auth/VerificationService.php`
  新增 `updateVerificationByAdmin()`：
  - `real_name`/`id_card` 留空=沿用现有值，不支持主动清空；
  - 置为「已认证」要求最终姓名+证件号非空；
  - 状态未变化时不覆盖原说明；状态迁移才套默认文案（2=审核通过（管理员人工认证）/3=认证失败（管理员操作）/5=管理员驳回/0=空）；
  - 资料变更或状态从「已认证」降级时作废 `certify_id`（注意 `persistVerificationState()` 入参 key 为 `certify_id`），并写 `VerificationHistory`。
- `backend/routes/v2-admin.php`
  在 `VERIFICATION_UNBIND` 权限组下新增 `PUT /v2/admin/verifications/{user}`。
- `frontend-admin-v3/src/api/admin/verification.ts` 新增 `update` 动作。
- `frontend-admin-v3/src/pages/users/components/VerificationPanel.vue`
  实名列表（桌面+移动）增加「编辑」入口与「修改实名认证信息」对话框（状态/姓名/证件号/状态说明）；
  无 `verification.unbind` 权限（或 `*`）时不显示编辑按钮；姓名/证件号回显脱敏，含 `*`/`＊` 时不提交。

### 2. 用户代理分组支持「无代理分组」

- `frontend-admin-v3/src/pages/users/detail/index.vue`
  编辑资料对话框代理组下拉显式提供「无代理分组（值 0）」，去掉 `clearable`；
  保存时 0 转 `null` 提交（后端 `agent_group_id` 可空，删除分组即回到无代理）。
  仅此文件 3 处为增量，不含 20260902 补丁已交付内容。

### 部署步骤（源码部分）

```bash
# 1. 应用补丁（仓库根或含 backend/、frontend-admin-v3/ 的目录结构）
git apply --check 增量补丁-20260904-实名编辑无代理组与实例价格重置.patch   # 先检查
git apply         增量补丁-20260904-实名编辑无代理组与实例价格重置.patch

# 2. 后端清缓存
cd backend && php artisan optimize:clear

# 3. 前端需重新构建并发布 dist
cd frontend-admin-v3 && npm run build
```

---

## 二、数据库变更（重要：只改运行库 tura_25y，与代码补丁配套执行）

### 背景

智简魔方的代理折扣是直接改「实例」（用户购买后的服务记录）价格实现的；
迁移后该价格落在 `services.amount`，而 TuraIDC 续费链路会先按 `services.amount` 折算基准价、
再叠加运行时全局代理折扣 → 造成二次折扣。本次将实例价格重置为「商品本身价格」。

### 变更内容

对 `services` 中能匹配到商品 `products.pricing` 同周期价（>0）且与现价不一致的记录，
把 `services.amount` 重置为商品该周期价格。

- 总数 2783 条；实际重置 **1650 条**；剩余不一致 = 0。
- 未处理的 195 条：商品 `pricing` 未配置该 `billing_cycle`（free/onetime/ontrial 等）或周期价为 0/负 → 无从重置，保持原值。

已执行 SQL（等价）：

```sql
UPDATE services s
JOIN products p ON p.id = s.product_id
SET s.amount = CAST(JSON_UNQUOTE(JSON_EXTRACT(p.pricing, CONCAT('$.', s.billing_cycle))) AS DECIMAL(12,2)),
    s.updated_at = NOW()
WHERE JSON_EXTRACT(p.pricing, CONCAT('$.', s.billing_cycle)) IS NOT NULL
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(p.pricing, CONCAT('$.', s.billing_cycle))) AS DECIMAL(12,2)) > 0
  AND ABS(s.amount - CAST(JSON_UNQUOTE(JSON_EXTRACT(p.pricing, CONCAT('$.', s.billing_cycle))) AS DECIMAL(12,2))) > 0.005;
```

### 备份 / 回滚

- 备份表：`services_bak_20260903`（整表快照，2783 行，与 services 同结构）。
- 需要回滚时：

```sql
UPDATE services s
JOIN services_bak_20260903 b ON b.id = s.id
SET s.amount = b.amount, s.updated_at = s.updated_at
WHERE ABS(COALESCE(s.amount,0) - COALESCE(b.amount,0)) > 0.005;
```

（仅回滚金额列，不触碰其它字段；确认无误后删除备份表。）

---

## 三、验证记录

- 后端：相关文件 `php -l` 全部通过。
- 前端 admin：`vue-tsc --noEmit && vite build` 构建成功。
- 补丁自检：`git apply --check` / `git apply --check --reverse` 通过；
  并以「HEAD → 20260902 补丁 → 本补丁」顺序在临时目录正向应用 detail/index.vue，结果与工作区逐字节一致。
