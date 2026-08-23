# 代理折扣 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 新增独立于会员等级的代理组折扣体系，支持商品折扣分组、折扣矩阵、成本价保护、优惠券兼容，并让新购、续费、后台和用户端展示统一生效。

**Architecture:** 使用 `AgentDiscountService` 作为唯一代理折扣解析和金额校验入口；用 `product_discount_groups`、`agent_groups`、`agent_group_discounts` 三张表保存配置，分别扩展商品、用户和优惠券。新购报价和续费报价均由服务端重新解析当前用户与商品关系，报价令牌和账单快照保存代理折扣上下文，前端只展示服务端结果。

**Tech Stack:** Laravel 11、Eloquent、MySQL、PHPUnit/Pest、Vue 3、TypeScript、TDesign、Playwright、pnpm workspace。

---

## 文件边界

- 代理折扣领域：`backend/app/Models/AgentGroup.php`、`ProductDiscountGroup.php`、`AgentGroupDiscount.php`、`backend/app/Services/Finance/AgentDiscountService.php`。
- 数据库：`backend/database/migrations/` 中新增一组增量迁移；同步更新 schema 相关测试或 baseline 导出流程，不手工修改历史迁移。
- 后端接口：新增 Admin AgentGroup、ProductDiscountGroup、AgentGroupDiscount Controller、Request、Resource 和路由；扩展 Product、User、Coupon 的现有接口。
- 新购计价：`backend/app/Services/Order/Concerns/HandlesOrderCalculation.php`、`CheckoutService.php`、`SiteProductQuoteService.php`、`CheckoutSecurityService.php`、`CouponService.php`。
- 续费计价：`backend/app/Services/Provisioning/ServiceRenewService.php` 与自动续费复用链路。
- 管理端：`frontend-admin-v3/src/api/admin/agentDiscount.ts`、代理折扣管理页、商品编辑、用户详情/列表、优惠券编辑和相关类型。
- 用户端：官网购买页、控制台续费弹窗、个人中心、用户 API 类型和报价归一化逻辑。
- 测试：后端 Unit/Feature、管理端 E2E、用户控制台 E2E 或纯函数测试。

## Task 1: 建立数据库结构和领域模型

**Files:**

- Create: `backend/database/migrations/2026_08_20_*.php`
- Modify: `backend/app/Models/Product.php`
- Modify: `backend/app/Models/User.php`
- Modify: `backend/app/Models/Coupon.php`
- Create: `backend/app/Models/ProductDiscountGroup.php`
- Create: `backend/app/Models/AgentGroup.php`
- Create: `backend/app/Models/AgentGroupDiscount.php`
- Test: `backend/tests/Unit/Services/Finance/AgentDiscountSchemaTest.php`

- [ ] **Step 1: Write failing schema/model tests**

覆盖以下断言：三张新表存在；联合唯一键阻止同一代理组和商品折扣分组重复；`products.product_discount_group_id`、`users.agent_group_id`、`coupons.allow_agent` 默认值正确；模型关系和 decimal cast 正确。

- [ ] **Step 2: Run the schema tests and confirm failure**

Run: `cd backend; php artisan test tests/Unit/Services/Finance/AgentDiscountSchemaTest.php`

Expected: FAIL，因为表、字段和模型尚不存在。

- [ ] **Step 3: Add the migrations**

创建三张表并添加字段扩展：

```php
Schema::create('product_discount_groups', function (Blueprint $table) {
    $table->id();
    $table->string('name', 50);
    $table->string('code', 30)->unique();
    $table->decimal('min_discount_rate', 5, 2)->default(100);
    $table->decimal('cost_rate', 5, 2)->default(0);
    $table->tinyInteger('status')->default(1);
    $table->integer('sort_order')->default(0);
    $table->string('remark')->nullable();
    $table->timestamps();
});
Schema::create('agent_groups', function (Blueprint $table) {
    $table->id();
    $table->string('name', 50);
    $table->string('code', 30)->unique();
    $table->tinyInteger('status')->default(1);
    $table->integer('sort_order')->default(0);
    $table->string('remark')->nullable();
    $table->timestamps();
});
Schema::create('agent_group_discounts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('agent_group_id')->constrained('agent_groups')->cascadeOnDelete();
    $table->foreignId('product_discount_group_id')->constrained('product_discount_groups')->cascadeOnDelete();
    $table->decimal('discount_rate', 5, 2);
    $table->timestamps();
    $table->unique(['agent_group_id', 'product_discount_group_id'], 'agent_group_discount_unique');
});
Schema::table('products', fn (Blueprint $table) => $table->foreignId('product_discount_group_id')->nullable()->nullOnDelete());
Schema::table('users', fn (Blueprint $table) => $table->foreignId('agent_group_id')->nullable()->nullOnDelete());
Schema::table('coupons', fn (Blueprint $table) => $table->boolean('allow_agent')->default(true));
```

- [ ] **Step 4: Add model fillable, casts and relationships**

`ProductDiscountGroup` 定义 `products()`、`agentGroupDiscounts()`；`AgentGroup` 定义 `users()`、`discounts()`；`AgentGroupDiscount` 定义双向 belongsTo。Product/User/Coupon 增加关系、fillable 和 casts，费率统一使用 `decimal:2`。

- [ ] **Step 5: Run the schema tests and confirm pass**

Run: `cd backend; php artisan test tests/Unit/Services/Finance/AgentDiscountSchemaTest.php`

Expected: PASS。

- [ ] **Step 6: Commit the database foundation**

```bash
git add backend/database/migrations backend/app/Models backend/tests/Unit/Services/Finance/AgentDiscountSchemaTest.php
git commit -m "feat: 添加代理折扣数据结构"
```

## Task 2: 实现代理折扣领域服务和配置校验

**Files:**

- Create: `backend/app/Services/Finance/AgentDiscountService.php`
- Create: `backend/app/Http/Requests/Admin/V2/AgentDiscount/CreateAgentGroupRequest.php`
- Create: `backend/app/Http/Requests/Admin/V2/AgentDiscount/UpdateAgentGroupRequest.php`
- Create: `backend/app/Http/Requests/Admin/V2/AgentDiscount/CreateProductDiscountGroupRequest.php`
- Create: `backend/app/Http/Requests/Admin/V2/AgentDiscount/UpdateProductDiscountGroupRequest.php`
- Create: `backend/app/Http/Requests/Admin/V2/AgentDiscount/SaveAgentGroupDiscountsRequest.php`
- Test: `backend/tests/Unit/Services/Finance/AgentDiscountServiceTest.php`

- [ ] **Step 1: Write failing pure pricing tests**

测试 `resolve()` 在普通用户、无代理组、无匹配矩阵、禁用组和有效矩阵下分别返回 `100` 或配置折扣；测试最低折扣率、成本系数、折扣率范围和两位小数金额校验；测试折后金额低于成本基准抛出业务异常。

- [ ] **Step 2: Run the tests and confirm failure**

Run: `cd backend; php artisan test tests/Unit/Services/Finance/AgentDiscountServiceTest.php`

Expected: FAIL，因为领域服务尚不存在。

- [ ] **Step 3: Implement the resolver contract**

定义统一返回结构：

```php
[
    'agent_group_id' => ?int,
    'agent_group_name' => ?string,
    'product_discount_group_id' => ?int,
    'discount_rate' => 100.00,
    'discount_amount' => 0.00,
    'original_amount' => 0.00,
    'discounted_amount' => 0.00,
    'cost_rate' => 0.00,
    'cost_amount' => 0.00,
]
```

实现 `resolveForProduct(?User $user, Product $product)`、`apply(Product $product, ?User $user, float $amount)`、`assertAboveCost(array $pricing)`。所有数据库查询使用 eager loading 或一次性关系查询，费率和金额使用 `Money`。

- [ ] **Step 4: Implement configuration request validation**

校验名称/编码唯一、状态和排序；`min_discount_rate`、`cost_rate`、`discount_rate` 为 `0-100`；矩阵折扣率必须不低于所属商品折扣分组的 `min_discount_rate`。批量保存矩阵时使用事务和 `upsert`，不能留下重复关系。

- [ ] **Step 5: Run the tests and confirm pass**

Run: `cd backend; php artisan test tests/Unit/Services/Finance/AgentDiscountServiceTest.php`

Expected: PASS。

- [ ] **Step 6: Commit the domain service**

```bash
git add backend/app/Services/Finance/AgentDiscountService.php backend/app/Http/Requests/Admin/V2/AgentDiscount backend/tests/Unit/Services/Finance/AgentDiscountServiceTest.php
git commit -m "feat: 实现代理折扣规则服务"
```

## Task 3: 增加后台代理折扣 API 和权限

**Files:**

- Create: `backend/app/Http/Controllers/Admin/V2/AgentGroupController.php`
- Create: `backend/app/Http/Controllers/Admin/V2/ProductDiscountGroupController.php`
- Create: `backend/app/Http/Controllers/Admin/V2/AgentGroupDiscountController.php`
- Create: `backend/app/Http/Resources/Admin/V2/AdminAgentGroupListItemResource.php`
- Create: `backend/app/Http/Resources/Admin/V2/AdminProductDiscountGroupListItemResource.php`
- Create: `backend/app/Http/Resources/Admin/V2/AdminAgentGroupDiscountResource.php`
- Modify: `backend/routes/v2-admin.php`
- Modify: `backend/app/Support/AdminPermissions.php`
- Modify: `backend/app/Services/Admin/Rbac/PermissionCatalogService.php`
- Test: `backend/tests/Feature/V2AdminAgentDiscountApiTest.php`

- [ ] **Step 1: Write failing API tests**

覆盖：无权限返回 403；有查看权限可查询代理组、商品折扣组和矩阵；有管理权限可创建/更新/删除；矩阵低于最低折扣率返回 422；被用户绑定的代理组删除返回业务错误。

- [ ] **Step 2: Run the API tests and confirm failure**

Run: `cd backend; php artisan test tests/Feature/V2AdminAgentDiscountApiTest.php`

Expected: FAIL，因为路由和 Controller 尚不存在。

- [ ] **Step 3: Add permissions and routes**

新增 `agent_discount.list`、`agent_discount.manage`，在 `marketing_growth` 分组登记；路由提供：

```text
GET/POST/PUT/DELETE /admin/agent-groups
GET/POST/PUT/DELETE /admin/product-discount-groups
GET/PUT             /admin/agent-group-discounts
```

所有写接口使用 `agent_discount.manage`，列表和详情使用 `agent_discount.list`。

- [ ] **Step 4: Implement Controllers and Resources**

Controller 只负责 Request、Service 调用和 Resource 返回；列表使用分页与 `status/sort_order` 排序；矩阵接口一次返回行列配置和当前折扣值。错误统一使用现有 `BusinessException` 和 API 错误资源格式。

- [ ] **Step 5: Run the API tests and confirm pass**

Run: `cd backend; php artisan test tests/Feature/V2AdminAgentDiscountApiTest.php`

Expected: PASS。

- [ ] **Step 6: Commit the admin API**

```bash
git add backend/app/Http/Controllers/Admin/V2 backend/app/Http/Resources/Admin/V2 backend/routes/v2-admin.php backend/app/Support/AdminPermissions.php backend/app/Services/Admin/Rbac/PermissionCatalogService.php backend/tests/Feature/V2AdminAgentDiscountApiTest.php
git commit -m "feat: 添加代理折扣管理接口"
```

## Task 4: 接入新购计价、优惠券和报价令牌

**Files:**

- Modify: `backend/app/Services/Order/Concerns/HandlesOrderCalculation.php`
- Modify: `backend/app/Services/Site/SiteProductQuoteService.php`
- Modify: `backend/app/Services/Finance/CheckoutService.php`
- Modify: `backend/app/Services/Finance/CheckoutSecurityService.php`
- Modify: `backend/app/Services/Finance/CouponService.php`
- Modify: `backend/app/Http/Resources/Finance/*Invoice*Resource.php`
- Test: `backend/tests/Unit/Services/Finance/AgentDiscountCheckoutTest.php`
- Test: `backend/tests/Feature/V2SiteProductQuoteAgentDiscountTest.php`

- [ ] **Step 1: Write failing quote and checkout tests**

覆盖有效代理组 `80%` 使原价 `100` 变为代理价 `80`；普通用户保持 `100`；优惠券在 `80` 的基础上计算；代理不允许使用 `allow_agent=0` 的券；代理价低于成本时报价和创建账单都失败；报价令牌中的代理组或折扣变化后创建失败。

- [ ] **Step 2: Run tests and confirm failure**

Run: `cd backend; php artisan test tests/Unit/Services/Finance/AgentDiscountCheckoutTest.php tests/Feature/V2SiteProductQuoteAgentDiscountTest.php`

Expected: FAIL，因为报价和结算链路尚未接入代理折扣。

- [ ] **Step 3: Add agent data to quote calculation**

将当前 User 传入报价上下文，在 `quote()` 返回 `original_total_amount`、`agent_discount_rate`、`agent_discount_amount`、`agent_amount`、`cost_amount` 和代理组快照。保持现有 `total_amount` 含义为优惠券前、代理折后金额，避免破坏现有优惠券计算。

- [ ] **Step 4: Add server-side cost protection and coupon compatibility**

`CheckoutService::create()` 重新解析当前用户和商品，先调用代理成本校验，再调用 `reserveOwnedCouponForInvoice()`；`CouponService` 的券可用性判断读取 `allow_agent`，优惠券金额基于代理折后金额计算。新字段写入 Invoice、Order projection 和 config snapshot。

- [ ] **Step 5: Bind quote token to current agent context**

扩展 `CheckoutSecurityService::issueQuoteToken()` 与 `assertQuoteToken()` 的 payload，加入 `agent_group_id`、商品折扣组 ID、折扣率、成本系数和代理折后金额。创建订单时服务端重新计算并比对，任何关系变更返回“报价已变更”。

- [ ] **Step 6: Run the tests and confirm pass**

Run: `cd backend; php artisan test tests/Unit/Services/Finance/AgentDiscountCheckoutTest.php tests/Feature/V2SiteProductQuoteAgentDiscountTest.php`

Expected: PASS。

- [ ] **Step 7: Commit new-purchase pricing**

```bash
git add backend/app/Services/Order backend/app/Services/Site backend/app/Services/Finance backend/app/Http/Resources backend/tests/Unit/Services/Finance/AgentDiscountCheckoutTest.php backend/tests/Feature/V2SiteProductQuoteAgentDiscountTest.php
git commit -m "feat: 新购计价接入代理折扣"
```

## Task 5: 接入续费和自动续费

**Files:**

- Modify: `backend/app/Services/Provisioning/ServiceRenewService.php`
- Modify: `backend/app/Services/Automation/AutoRenewService.php`
- Test: `backend/tests/Unit/Services/Provisioning/AgentDiscountRenewTest.php`
- Test: `backend/tests/Feature/V2ClientRenewAgentDiscountTest.php`

- [ ] **Step 1: Write failing renewal tests**

测试每个续费周期均按代理折扣计算；用户代理组变化后预览使用新折扣；续费优惠券基于代理价；低于成本拒绝创建；自动续费创建金额等于预览金额；已创建账单的快照不随代理组变更而修改。

- [ ] **Step 2: Run tests and confirm failure**

Run: `cd backend; php artisan test tests/Unit/Services/Provisioning/AgentDiscountRenewTest.php tests/Feature/V2ClientRenewAgentDiscountTest.php`

Expected: FAIL，因为 `buildRenewConfig()` 目前只返回服务锁定价。

- [ ] **Step 3: Apply the resolver to every supported renewal cycle**

在 `buildRenewConfig(User $user, Service $service)` 中对每个有效周期调用 `AgentDiscountService`，保留 `original_amount`、`agent_discount_rate`、`agent_discount_amount`、`amount` 和成本基准；修改调用方传递认证用户，不能从客户端传入用户 ID。

- [ ] **Step 4: Apply the same values during invoice creation**

`createRenewInvoiceForUser()` 在事务锁内重新解析并校验成本，优惠券在代理价上计算，Invoice/Order/config snapshot 保存代理折扣快照。`AutoRenewService` 继续读取 `previewForUser()` 的同一周期 `amount`，不重复实现折扣算法。

- [ ] **Step 5: Run tests and confirm pass**

Run: `cd backend; php artisan test tests/Unit/Services/Provisioning/AgentDiscountRenewTest.php tests/Feature/V2ClientRenewAgentDiscountTest.php`

Expected: PASS。

- [ ] **Step 6: Commit renewal pricing**

```bash
git add backend/app/Services/Provisioning/ServiceRenewService.php backend/app/Services/Automation/AutoRenewService.php backend/tests/Unit/Services/Provisioning/AgentDiscountRenewTest.php backend/tests/Feature/V2ClientRenewAgentDiscountTest.php
git commit -m "feat: 续费计价接入代理折扣"
```

## Task 6: 扩展后台商品、用户和优惠券管理

**Files:**

- Modify: `backend/app/Http/Controllers/Admin/V2/ProductController.php`
- Modify: `backend/app/Services/ProductCatalog/AdminCatalogActionV2Service.php`
- Modify: `backend/app/Http/Resources/Admin/V2/AdminProductDetailResource.php`
- Modify: `backend/app/Http/Controllers/Admin/V2/UserController.php`
- Modify: `backend/app/Services/User/UserService.php`
- Modify: `backend/app/Http/Resources/Admin/V2/AdminUserListItemResource.php`
- Modify: `backend/app/Http/Resources/Admin/V2/AdminUserDetailResource.php`
- Modify: `backend/app/Http/Resources/Admin/V2/AdminCouponDetailResource.php`
- Modify: `backend/app/Http/Resources/Admin/V2/AdminCouponListResource.php`
- Modify: `frontend-admin-v3/src/api/product.ts`
- Modify: `frontend-admin-v3/src/api/user.ts`
- Modify: `frontend-admin-v3/src/api/admin/coupon.ts`
- Modify: `frontend-admin-v3/src/pages/products/edit.vue`
- Modify: `frontend-admin-v3/src/pages/users/detail/index.vue`
- Modify: `frontend-admin-v3/src/pages/users/index.vue`
- Modify: `frontend-admin-v3/src/pages/products/coupons/index.vue`
- Test: `frontend-admin-v3/tests/e2e/agent-discount-management.spec.ts`

- [ ] **Step 1: Extend existing API payload tests**

先在后端 API 测试增加商品绑定折扣组、用户绑定代理组、优惠券 `allow_agent` 的成功与无权限断言；前端 E2E 先写出打开管理页面、编辑矩阵、绑定商品和用户、保存优惠券开关的失败用例。

- [ ] **Step 2: Run the tests and confirm failure**

Run: `cd frontend-admin-v3; pnpm exec playwright test tests/e2e/agent-discount-management.spec.ts --project=desktop`

Expected: FAIL，因为菜单、API 和表单控件尚未存在。

- [ ] **Step 3: Add admin API clients and route**

新增 `src/api/admin/agentDiscount.ts`，提供代理组、商品折扣组和矩阵 CRUD；在 `src/api/admin/index.ts` 聚合；新增 `src/pages/agent-discounts/index.vue` 与 `src/router/modules/admin/marketing.ts` 路由，权限使用 `agent_discount.list`。

- [ ] **Step 4: Build the matrix management page**

使用现有列表页 + `t-dialog` 模式；行是商品折扣分组、列是代理组，每个单元格为百分比输入。提交前校验 `discount_rate >= min_discount_rate`，保存失败保留用户输入并显示后端错误；移动端改为商品折扣分组卡片和代理组明细。

- [ ] **Step 5: Add product, user and coupon controls**

商品编辑页增加折扣分组选择；用户列表/详情增加代理组展示与手动绑定；优惠券抽屉增加“允许代理使用”开关，并在 payload、回填和详情展示中保持一致。用户详情页展示会员等级与代理组两个独立字段。

- [ ] **Step 6: Run the E2E tests and confirm pass**

Run: `cd frontend-admin-v3; pnpm exec playwright test tests/e2e/agent-discount-management.spec.ts --project=desktop`

Expected: PASS。

- [ ] **Step 7: Commit admin management**

```bash
git add backend/app/Http/Controllers/Admin/V2 backend/app/Services/ProductCatalog backend/app/Services/User backend/app/Http/Resources/Admin/V2 frontend-admin-v3/src/api frontend-admin-v3/src/pages frontend-admin-v3/src/router frontend-admin-v3/tests/e2e/agent-discount-management.spec.ts
git commit -m "feat: 添加代理折扣后台管理"
```

## Task 7: 扩展用户端报价、续费和用户组展示

**Files:**

- Modify: `backend/app/Http/Resources/User/UserResource.php`
- Modify: `backend/app/Http/Resources/User/MemberLevelResource.php`
- Modify: `frontend-user-v3-www/src/api/site.js`
- Modify: `frontend-user-v3-www/src/views/website/products/useWebsiteProductCheckout.js`
- Modify: `frontend-user-v3-www/src/views/website/products/index.vue`
- Modify: `frontend-user-v4-console/src/api/client.ts`
- Modify: `frontend-user-v4-console/src/types/client.ts`
- Modify: `frontend-user-v4-console/src/pages/client/service-console/components/dialogs/RenewDialog.vue`
- Modify: `frontend-user-v4-console/src/domains/services/console/useConsoleRenew.ts`
- Modify: `frontend-user-v4-console/src/pages/client/profile/index.vue`
- Modify: `frontend-user-v4-console/src/domains/account/useProfile.ts`
- Test: `frontend-user-v3-www/tests/agentDiscountPricing.test.mjs`
- Test: `frontend-user-v4-console/tests/domains/agentDiscountRenew.spec.ts`

- [ ] **Step 1: Write failing client pricing tests**

官网测试报价归一化：存在代理折扣时显示原价、代理价和折扣标签；普通用户不渲染代理空状态；优惠券金额显示在代理价之后。控制台续费测试周期对象可以读取 `original_amount`、`agent_discount_rate` 和 `amount`。

- [ ] **Step 2: Run tests and confirm failure**

Run: `cd frontend-user-v3-www; node --test tests/agentDiscountPricing.test.mjs`; `cd ../frontend-user-v4-console; node --test tests/domains/agentDiscountRenew.test.mjs`

Expected: FAIL，因为类型和展示字段尚未接入。

- [ ] **Step 3: Extend API types and normalization**

在报价、续费周期、用户资料类型中增加 `agent_group`、`agent_discount_rate`、`agent_discount_amount`、`original_amount`、`agent_amount` 等可选字段；归一化层把缺失字段映射为普通用户兼容值，不在模板中重复兼容判断。

- [ ] **Step 4: Update purchase and renewal displays**

官网购买确认区域在代理用户存在折扣时展示原价划线、代理价和“V1代理 · 8折”，总价和优惠券减免按服务端结果展示；续费弹窗每个周期展示原价与代理价，最终应付金额使用代理价叠加优惠券后的金额。

- [ ] **Step 5: Update profile and service context**

个人中心展示代理组名称，并与会员等级分开；服务控制台续费上下文携带代理折扣字段，避免刷新后丢失展示。

- [ ] **Step 6: Run client tests and builds**

Run: `cd frontend-user-v3-www; node --test tests/agentDiscountPricing.test.mjs`; `cd ../frontend-user-v4-console; pnpm run build:type`; `pnpm run lint`。

Expected: PASS。

- [ ] **Step 7: Commit user-facing pricing**

```bash
git add backend/app/Http/Resources/User frontend-user-v3-www/src frontend-user-v3-www/tests/agentDiscountPricing.test.mjs frontend-user-v4-console/src frontend-user-v4-console/tests/domains/agentDiscountRenew.spec.ts
git commit -m "feat: 展示用户端代理折扣"
```

## Task 8: 完整回归和数据文档

**Files:**

- Modify: `backend/database/schema/mysql-schema.sql` only if the repository baseline policy requires regeneration
- Create: `backend/tests/Feature/AgentDiscountLifecycleRegressionTest.php`
- Modify: `docs/generated/api/backend-api-catalog.md` through the project API generation command

- [ ] **Step 1: Add lifecycle regression coverage**

覆盖配置创建 → 商品绑定 → 用户绑定 → 新购报价 → 优惠券叠加 → 账单创建 → 代理组变更 → 续费报价 → 成本拒绝 → 账单快照不变的完整链路。

- [ ] **Step 2: Run backend verification**

Run: `cd backend; php artisan test tests/Unit/Services/Finance/AgentDiscountServiceTest.php tests/Unit/Services/Finance/AgentDiscountCheckoutTest.php tests/Unit/Services/Provisioning/AgentDiscountRenewTest.php tests/Feature/V2AdminAgentDiscountApiTest.php tests/Feature/V2SiteProductQuoteAgentDiscountTest.php tests/Feature/V2ClientRenewAgentDiscountTest.php tests/Feature/AgentDiscountLifecycleRegressionTest.php; composer run format:check; composer run analyse`

Expected: all selected tests pass, Pint and PHPStan pass。

- [ ] **Step 3: Run frontend verification**

Run: `pnpm run typecheck:frontends; pnpm run lint:frontends; pnpm run build:frontends; cd frontend-admin-v3; pnpm exec playwright test tests/e2e/agent-discount-management.spec.ts --project=desktop`

Expected: all type checks, lint, builds and selected E2E pass。

- [ ] **Step 4: Check migration and repository state**

Run: `cd backend; php artisan migrate:status`; `cd ..; git diff --check; git status --short`。

确认增量迁移可重复执行、未跟踪运行时文件未被纳入提交、没有敏感日志或支付凭证进入快照和日志。

- [ ] **Step 5: Commit final regression coverage**

```bash
git add backend/tests/Feature/AgentDiscountLifecycleRegressionTest.php backend/database/schema/mysql-schema.sql docs/generated/api/backend-api-catalog.md
git commit -m "test: 增加代理折扣全链路回归"
```

## Self-review

- 设计中的三张表、三处字段扩展对应 Task 1。
- 代理组、商品折扣组和矩阵 CRUD 与权限对应 Task 2、Task 3、Task 6。
- 新购、优惠券顺序、成本拒绝、报价令牌对应 Task 4。
- 续费和自动续费对应 Task 5。
- 用户端购买、续费和个人中心展示对应 Task 7。
- 账单快照、代理组变更和完整异常链路对应 Task 4、Task 5、Task 8。
- 未使用占位符；每个代码任务包含明确文件、命令和预期结果。
