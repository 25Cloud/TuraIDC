<template>
  <div class="user-detail-page">
    <t-loading :loading="detailLoading" size="small">
      <div class="detail-action-bar">
        <t-button variant="text" theme="default" @click="goBack">
          <template #icon><chevron-left-icon /></template>
          返回用户列表
        </t-button>
        <div class="user-detail-actions">
          <t-button
            v-if="canLoginAs"
            theme="primary"
            :disabled="!user.id"
            :loading="loginAsLoading"
            @click="handleLoginAs"
            >代登录</t-button
          >
          <t-button theme="default" :disabled="!user.id" @click="openEditDialog">编辑资料</t-button>
        </div>
      </div>
    </t-loading>

    <div class="user-detail-stats">
      <t-card v-for="item in statCards" :key="item.key" :bordered="false">
        <div class="user-stat-card">
          <div class="user-stat-card__label">
            <span>{{ item.label }}</span>
            <edit-icon
              v-if="item.key === 'cash_balance'"
              class="balance-action-icon"
              :class="{ 'is-disabled': !user.id }"
              @click="user.id && openRechargeDialog()"
            />
          </div>
          <strong :class="`is-${item.tone}`">{{ item.value }}</strong>
        </div>
      </t-card>
    </div>

    <t-card :bordered="false">
      <t-tabs :value="activeTab" @change="handleTabChange">
        <t-tab-panel value="basic" label="基本信息" />
        <t-tab-panel value="referral" label="推荐信息" />
        <t-tab-panel value="services" label="产品/服务" />
        <t-tab-panel value="invoices" label="账单" />
        <t-tab-panel value="balance" label="资金流水" />
        <t-tab-panel value="tickets" label="工单" />
        <t-tab-panel value="logs" label="操作日志" />
        <t-tab-panel value="notices" label="通知记录" />
      </t-tabs>

      <section v-if="activeTab === 'basic'" class="user-detail-section">
        <div class="basic-grid">
          <t-card header="基础信息" :bordered="true">
            <div class="info-grid">
              <div v-for="item in infoItems" :key="item.label" class="info-field">
                <span class="info-label">{{ item.label }}</span>
                <t-popup :content="String(item.value)" trigger="click" placement="bottom-left" show-arrow>
                  <span class="info-value" :class="item.tone ? `text-${item.tone}` : ''">{{ item.value }}</span>
                </t-popup>
              </div>
            </div>
          </t-card>
          <t-card :bordered="true">
            <template #header>
              <div class="note-header">
                <span>管理员备注</span>
                <t-button v-if="!noteEditing" variant="text" theme="primary" size="small" @click="startEditNote"
                  >编辑</t-button
                >
              </div>
            </template>
            <div v-if="!noteEditing" class="user-note" :class="{ 'is-empty': !user.admin_note }">
              {{ user.admin_note || '暂无备注' }}
            </div>
            <div v-else class="note-edit">
              <t-textarea
                v-model="noteForm"
                placeholder="输入管理员备注"
                :maxlength="500"
                :autosize="{ minRows: 3, maxRows: 6 }"
              />
              <div class="note-edit-footer">
                <span class="note-char-count" :class="{ 'is-over': noteForm.length >= 500 }"
                  >{{ noteForm.length }} / 500</span
                >
                <div class="note-edit-actions">
                  <t-button size="small" theme="default" @click="noteEditing = false">取消</t-button>
                  <t-button
                    size="small"
                    theme="primary"
                    :loading="noteSaving"
                    :disabled="noteForm.length > 500"
                    @click="saveNote"
                    >保存</t-button
                  >
                </div>
              </div>
            </div>
          </t-card>
        </div>
      </section>

      <section v-else-if="activeTab === 'referral'" class="user-detail-section">
        <div class="referral-strip">
          <div v-for="item in referralItems" :key="item.label" class="referral-item">
            <span>{{ item.label }}</span>
            <strong :class="item.tone ? `text-${item.tone}` : ''">{{ item.value }}</strong>
          </div>
        </div>
        <t-table row-key="id" :data="recentReferrals" :columns="recentReferralColumns" table-layout="fixed">
          <template #referralUser="{ row }">
            <strong>{{ row.nickname || row.display_name || row.email || '-' }}</strong>
            <p class="table-subtext">{{ row.email || '-' }}</p>
          </template>
          <template #referredAt="{ row }">{{ formatDateTime(row.referred_at || row.created_at) }}</template>
        </t-table>
      </section>

      <section v-else-if="activeTab === 'services'" class="user-detail-section">
        <div class="detail-toolbar">
          <t-input
            v-model="services.filters.keyword"
            clearable
            placeholder="搜索服务名、域名、账单号、配置名"
            @enter="searchServices"
            @clear="searchServices"
          >
            <template #suffix-icon><search-icon /></template>
          </t-input>
          <t-select v-model="services.filters.status" clearable placeholder="状态" @change="searchServices">
            <t-option
              v-for="option in serviceStatusOptions"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </t-select>
          <t-button theme="primary" @click="openAddServiceDialog">添加实例</t-button>
          <t-button theme="default" :loading="services.refreshingStatus" @click="handleRefreshServicesStatus"
            >批量刷新状态</t-button
          >
        </div>
        <div class="table-scroll">
          <t-table
            row-key="id"
            :data="services.list"
            :columns="serviceColumns"
            :loading="services.loading"
            :pagination="paginationOf(services)"
            table-layout="fixed"
            @page-change="handleServicesPageChange"
          >
            <template #serviceName="{ row }">
              <button type="button" class="user-link" :disabled="!Number(row.id || 0)" @click="goServiceDetail(row)">
                <strong>{{ serviceName(row) }}</strong>
              </button>
              <p class="table-subtext">{{ row.domain || row.product?.group_name || '-' }}</p>
            </template>
            <template #serviceAmount="{ row }">{{ formatMoney(row.amount) }}</template>
            <template #serviceStatus="{ row }">
              <t-tag :theme="serviceStatusTheme(row.status)" variant="light">{{
                serviceStatusLabel(row.status)
              }}</t-tag>
            </template>
            <template #serviceCreated="{ row }">{{ formatDateTime(row.created_at) }}</template>
            <template #serviceExpires="{ row }">{{ formatDateTime(row.expires_at) }}</template>
            <template #serviceOperation="{ row }">
              <div class="row-actions">
                <t-button variant="text" theme="primary" size="small" @click="goServiceDetail(row)">管理实例</t-button>
                <t-button
                  variant="text"
                  theme="default"
                  size="small"
                  :loading="services.refreshing"
                  @click="handleRefreshService(row)"
                  >刷新</t-button
                >
                <t-button variant="text" theme="danger" size="small" @click="handleDeleteServiceRow(row)"
                  >删除</t-button
                >
              </div>
            </template>
          </t-table>
        </div>
      </section>

      <section v-else-if="activeTab === 'invoices'" class="user-detail-section">
        <div class="detail-toolbar compact">
          <t-select v-model="invoices.filters.status" clearable placeholder="状态" @change="searchInvoices">
            <t-option
              v-for="option in invoiceStatusOptions"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </t-select>
          <t-select v-model="invoices.filters.type" clearable placeholder="类型" @change="searchInvoices">
            <t-option
              v-for="option in invoiceTypeOptions"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </t-select>
        </div>
        <div class="table-scroll">
          <t-table
            row-key="id"
            :data="invoices.list"
            :columns="invoiceColumns"
            :loading="invoices.loading"
            :pagination="paginationOf(invoices)"
            table-layout="fixed"
            @page-change="handleInvoicesPageChange"
          >
            <template #invoiceAmount="{ row }">{{ formatMoney(row.amount) }}</template>
            <template #invoiceStatus="{ row }">
              <t-tag :theme="invoiceStatusTheme(row.status)" variant="light">{{
                invoiceStatusLabel(row.status)
              }}</t-tag>
            </template>
            <template #invoiceType="{ row }">{{ row.type_label || invoiceTypeLabel(row.type) }}</template>
            <template #invoiceCreated="{ row }">{{ formatDateTime(row.created_at) }}</template>
            <template #invoicePaid="{ row }">{{ formatDateTime(row.paid_at) }}</template>
            <template #invoiceOperation="{ row }">
              <div class="row-actions">
                <t-button variant="text" theme="primary" size="small" @click="openInvoiceDrawer(row)">详情</t-button>
                <t-button
                  v-if="isCancelableInvoice(row)"
                  variant="text"
                  theme="danger"
                  size="small"
                  @click="handleCancelInvoice(row)"
                  >取消</t-button
                >
              </div>
            </template>
          </t-table>
        </div>
      </section>

      <section v-else-if="activeTab === 'balance'" class="user-detail-section">
        <div class="table-scroll">
          <t-table
            row-key="ledger_id"
            :data="balance.list"
            :columns="balanceColumns"
            :loading="balance.loading"
            :pagination="paginationOf(balance)"
            table-layout="fixed"
            @page-change="handleBalancePageChange"
          >
            <template #balanceTime="{ row }">{{ formatDateTime(row.occurred_at || row.created_at) }}</template>
            <template #balanceType="{ row }">
              <t-tag :theme="balanceTheme(row.event_type)" variant="light">{{
                balanceTypeLabel(row.event_type)
              }}</t-tag>
            </template>
            <template #balanceChange="{ row }">
              <span :class="amountClass(row.change_amount)">{{ signedMoney(row.change_amount) }}</span>
            </template>
            <template #balanceAfter="{ row }">{{ formatMoney(row.balance_after) }}</template>
          </t-table>
        </div>
      </section>

      <section v-else-if="activeTab === 'tickets'" class="user-detail-section">
        <div class="table-scroll">
          <t-table
            row-key="id"
            :data="tickets.list"
            :columns="ticketColumns"
            :loading="tickets.loading"
            :pagination="paginationOf(tickets)"
            table-layout="fixed"
            @page-change="handleTicketsPageChange"
          >
            <template #ticketPriority="{ row }">
              <t-tag :theme="priorityTheme(row.priority)" variant="light">{{ priorityLabel(row.priority) }}</t-tag>
            </template>
            <template #ticketStatus="{ row }">
              <t-tag :theme="ticketStatusTheme(row.status)" variant="light">{{ ticketStatusLabel(row.status) }}</t-tag>
            </template>
            <template #ticketCreated="{ row }">{{ formatDateTime(row.created_at) }}</template>
          </t-table>
        </div>
      </section>

      <section v-else-if="activeTab === 'logs'" class="user-detail-section">
        <div class="detail-toolbar compact">
          <t-date-picker v-model="logs.filters.start_date" clearable placeholder="开始日期" @change="searchLogs" />
          <t-date-picker v-model="logs.filters.end_date" clearable placeholder="结束日期" @change="searchLogs" />
          <t-input
            v-model="logs.filters.keyword"
            clearable
            placeholder="描述"
            @enter="searchLogs"
            @clear="searchLogs"
          />
          <t-input
            v-model="logs.filters.ip_address"
            clearable
            placeholder="IP 地址"
            @enter="searchLogs"
            @clear="searchLogs"
          />
          <t-select v-model="logs.filters.source" clearable placeholder="来源" @change="searchLogs">
            <t-option value="web" label="Web" />
            <t-option value="api" label="API" />
          </t-select>
        </div>
        <div class="table-scroll">
          <t-table
            row-key="id"
            :data="logs.list"
            :columns="logColumns"
            :loading="logs.loading"
            :pagination="paginationOf(logs)"
            table-layout="fixed"
            @page-change="handleLogsPageChange"
          >
            <template #logTime="{ row }">{{ formatDateTime(row.created_at) }}</template>
          </t-table>
        </div>
      </section>

      <section v-else class="user-detail-section">
        <div class="detail-toolbar compact">
          <t-radio-group v-model="notices.channel" variant="default-filled" @change="reloadNotices">
            <t-radio-button value="email">邮件</t-radio-button>
            <t-radio-button value="sms">短信</t-radio-button>
          </t-radio-group>
        </div>
        <div class="table-scroll">
          <t-table
            row-key="id"
            :data="notices.list"
            :columns="noticeColumns"
            :loading="notices.loading"
            :pagination="paginationOf(notices)"
            table-layout="fixed"
            @page-change="handleNoticesPageChange"
          >
            <template #noticeTarget="{ row }">{{ notices.channel === 'email' ? row.to_email : row.phone }}</template>
            <template #noticeTitle="{ row }">{{
              notices.channel === 'email' ? row.subject : row.template_code
            }}</template>
            <template #noticeStatus="{ row }">
              <t-tag :theme="noticeTheme(row.status)" variant="light">{{ noticeLabel(row.status) }}</t-tag>
            </template>
            <template #noticeTime="{ row }">{{ formatDateTime(row.sent_at || row.created_at) }}</template>
          </t-table>
        </div>
      </section>
    </t-card>

    <t-dialog
      v-model:visible="editVisible"
      header="编辑资料"
      width="560px"
      :confirm-btn="{ content: '保存修改', loading: saveLoading }"
      @cancel="editVisible = false"
      @confirm="handleSave"
    >
      <t-form ref="editFormRef" :data="editForm" :rules="editRules" label-align="top">
        <t-form-item label="邮箱">
          <t-input :value="user.email || '-'" disabled />
        </t-form-item>
        <t-form-item label="昵称" name="nickname">
          <t-input v-model="editForm.nickname" />
        </t-form-item>
        <t-form-item label="手机号" name="phone">
          <t-input v-model="editForm.phone" />
        </t-form-item>
        <t-form-item label="新密码" name="password">
          <t-input v-model="editForm.password" type="password" placeholder="留空则不修改" />
        </t-form-item>
        <t-form-item label="状态" name="status">
          <t-switch v-model="editForm.status" :custom-value="[1, 0]" />
        </t-form-item>
        <t-form-item v-if="canManageUsers" label="代理组" name="agent_group_id">
          <t-select
            v-model="editForm.agent_group_id"
            clearable
            filterable
            :loading="agentGroupOptionsLoading"
            placeholder="请选择代理组，留空表示不设置"
          >
            <t-option
              v-for="item in agentGroupOptions"
              :key="item.id"
              :label="item.name || item.code"
              :value="item.id"
            />
          </t-select>
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="rechargeVisible"
      header="资金管理"
      width="480px"
      :confirm-btn="{ content: rechargeForm.type === 'decrease' ? '确认扣减' : '确认增加', loading: rechargeLoading }"
      @cancel="rechargeVisible = false"
      @confirm="handleRecharge"
    >
      <t-form ref="rechargeFormRef" :data="rechargeForm" :rules="rechargeRules" label-align="top">
        <t-form-item label="用户">
          <t-input :value="rechargeForm.email" disabled />
        </t-form-item>
        <t-form-item label="操作类型" name="type">
          <t-radio-group v-model="rechargeForm.type">
            <t-radio value="increase">增加余额</t-radio>
            <t-radio value="decrease">扣减余额</t-radio>
          </t-radio-group>
        </t-form-item>
        <t-form-item label="金额" name="amount">
          <t-input-number
            v-model="rechargeForm.amount"
            :min="0.01"
            :max="999999"
            :decimal-places="2"
            style="width: 100%"
          />
        </t-form-item>
        <t-form-item label="备注" name="remark">
          <t-input v-model="rechargeForm.remark" placeholder="请填写操作原因" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="addServiceVisible"
      header="添加实例"
      width="760px"
      :confirm-btn="{ content: '确认创建', loading: addServiceSubmitting }"
      @cancel="addServiceVisible = false"
      @confirm="handleSubmitAddService"
    >
      <t-alert
        theme="info"
        message="确认后会创建本地服务；可按开关选择自动创建订单、账单和从余额扣款。余额扣款需同时创建账单，余额不足时会回滚本次创建。不会自动发起上游控制，需要时请在服务控制台绑定上游实例。"
      />
      <t-form
        ref="addServiceFormRef"
        :data="addServiceForm"
        :rules="addServiceRules"
        label-align="top"
        class="dialog-form"
      >
        <div class="dialog-grid">
          <t-form-item label="选择商品" name="product_id" class="form-item-full">
            <product-binding-tree-select
              v-model="addServiceProductIdArray"
              mode="single"
              @change="handleAddServiceProductChange"
            />
          </t-form-item>
          <t-form-item label="计费周期" name="billing_cycle">
            <t-select
              v-model="addServiceForm.billing_cycle"
              placeholder="请选择计费周期"
              @change="syncAddServiceAmountFromCycle"
            >
              <t-option
                v-for="item in addServiceBillingOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </t-select>
          </t-form-item>
          <t-form-item label="系统类型">
            <t-select
              v-model="addServiceForm.os"
              clearable
              filterable
              :loading="addServiceOsLoading"
              placeholder="请选择系统"
            >
              <t-option
                v-for="item in addServiceOsFlatOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </t-select>
          </t-form-item>
          <t-form-item label="服务状态" name="status">
            <t-select v-model="addServiceForm.status" placeholder="请选择状态">
              <t-option
                v-for="item in serviceStatusOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </t-select>
          </t-form-item>
          <t-form-item label="服务金额" name="amount">
            <t-input-number v-model="addServiceForm.amount" :min="0" :decimal-places="2" style="width: 100%" />
          </t-form-item>
          <t-form-item label="服务名称">
            <t-input v-model="addServiceForm.name" placeholder="为空时默认使用配置名" />
          </t-form-item>
          <t-form-item class="form-item-full">
            <div class="add-service-automation-switches">
              <div class="add-service-automation-switch">
                <span>自动续费</span>
                <t-switch v-model="addServiceForm.auto_renew" :custom-value="[1, 0]" />
              </div>
              <div class="add-service-automation-switch">
                <span>自动创建订单</span>
                <t-switch v-model="addServiceForm.create_order" :custom-value="[1, 0]" />
              </div>
              <div class="add-service-automation-switch">
                <span>自动创建账单</span>
                <t-switch
                  v-model="addServiceForm.create_invoice"
                  :custom-value="[1, 0]"
                  @change="handleAddServiceCreateInvoiceChange"
                />
              </div>
              <div class="add-service-automation-switch">
                <span>从余额扣款</span>
                <t-switch
                  v-model="addServiceForm.deduct_balance"
                  :custom-value="[1, 0]"
                  :disabled="!addServiceForm.create_invoice"
                />
              </div>
            </div>
          </t-form-item>
        </div>
        <t-form-item label="备注">
          <t-textarea v-model="addServiceForm.remark" :maxlength="200" placeholder="记录手工开通说明或交付信息" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-drawer v-model:visible="serviceDrawer.visible" size="620px" header="服务控制台" @close="closeServiceDrawer">
      <t-loading :loading="serviceDrawer.loading" size="small">
        <t-descriptions :column="1" bordered>
          <t-descriptions-item label="服务名称">{{
            fieldValue(serviceDrawer.detail.name || serviceDrawer.detail.domain)
          }}</t-descriptions-item>
          <t-descriptions-item label="状态">{{ serviceStatusLabel(serviceDrawer.detail.status) }}</t-descriptions-item>
          <t-descriptions-item label="计费周期">{{
            fieldValue(serviceDrawer.detail.billing_cycle_label)
          }}</t-descriptions-item>
          <t-descriptions-item label="金额">{{ formatMoney(serviceDrawer.detail.amount) }}</t-descriptions-item>
          <t-descriptions-item label="账单号">{{
            fieldValue(serviceDrawer.detail.invoice?.invoice_no || serviceDrawer.detail.order?.invoice_no)
          }}</t-descriptions-item>
          <t-descriptions-item label="上游"
            >{{ fieldValue(serviceDrawer.detail.upstream?.provider_key)
            }}<template v-if="serviceDrawer.detail.upstream?.host_id">
              / host #{{ serviceDrawer.detail.upstream.host_id }}</template
            ></t-descriptions-item
          >
          <t-descriptions-item label="公网 IP">{{
            fieldValue(serviceDrawer.detail.connection?.dedicated_ip || serviceDrawer.detail.upstream?.dedicated_ip)
          }}</t-descriptions-item>
          <t-descriptions-item label="登录账号">{{
            fieldValue(serviceDrawer.detail.connection?.username)
          }}</t-descriptions-item>
          <t-descriptions-item label="登录端口">{{
            fieldValue(serviceDrawer.detail.connection?.port)
          }}</t-descriptions-item>
          <t-descriptions-item label="运行状态">
            <template v-if="serviceDrawer.detail.runtime?.power_label || serviceDrawer.detail.runtime?.description">
              {{ fieldValue(serviceDrawer.detail.runtime?.power_label || serviceDrawer.detail.runtime?.description) }}
            </template>
            <t-tooltip v-else content="上游暂未返回实例的电源状态">
              <t-tag theme="warning" variant="light">未获取到运行状态</t-tag>
            </t-tooltip>
          </t-descriptions-item>
          <t-descriptions-item label="到期时间">{{
            formatDateTime(serviceDrawer.detail.expires_at)
          }}</t-descriptions-item>
        </t-descriptions>
        <div v-if="serviceDrawer.detail.upstream?.remote_error" class="drawer-alert">
          <t-alert theme="warning" :message="serviceDrawer.detail.upstream.remote_error" />
        </div>
        <div v-if="serviceSpecs.length" class="spec-grid">
          <div v-for="item in serviceSpecs" :key="item.label" class="spec-chip">
            <span>{{ item.label }}</span>
            <strong>{{ item.value }}</strong>
          </div>
        </div>
        <div class="drawer-actions">
          <t-button
            v-if="canPowerOn"
            theme="success"
            size="small"
            :loading="serviceDrawer.actionLoading === 'power:on'"
            @click="handleServicePower('on')"
            >开机</t-button
          >
          <t-button
            v-if="canPowerOff"
            theme="danger"
            variant="outline"
            size="small"
            :loading="serviceDrawer.actionLoading === 'power:off'"
            @click="handleServicePower('off')"
            >关机</t-button
          >
          <t-button
            v-if="canReboot"
            theme="warning"
            variant="outline"
            size="small"
            :loading="serviceDrawer.actionLoading === 'power:reboot'"
            @click="handleServicePower('reboot')"
            >重启</t-button
          >
          <t-button
            theme="default"
            size="small"
            :loading="serviceDrawer.actionLoading === 'remote-status'"
            @click="handleRefreshConsoleRemoteStatus"
            >刷新远程</t-button
          >
          <t-button
            theme="default"
            size="small"
            :disabled="!serviceActions.password_reset"
            :loading="serviceDrawer.actionLoading === 'reset-password'"
            @click="openResetPasswordDialog"
            >重置密码</t-button
          >
          <t-button theme="default" size="small" @click="openServiceUpstreamDialog">上游绑定</t-button>
          <t-button theme="default" size="small" @click="openServicePricingDialog">调价</t-button>
          <t-button theme="default" size="small" @click="openServiceNameDialog">改名称</t-button>
          <t-button
            theme="default"
            size="small"
            :disabled="!serviceActions.manual_provision"
            :loading="serviceDrawer.actionLoading === 'manual-provision'"
            @click="openManualProvisionDialog"
            >手动开通</t-button
          >
          <t-button
            v-if="canRefundService"
            theme="danger"
            size="small"
            :loading="serviceDrawer.actionLoading === 'refund'"
            @click="openServiceRefundDialog"
            >退款</t-button
          >
          <t-tag v-else-if="isServiceRefunded" theme="danger" variant="light">已退款</t-tag>
        </div>
        <div class="drawer-close-actions">
          <t-button variant="outline" @click="closeServiceDrawer">
            <template #icon><chevron-left-icon /></template>
            返回
          </t-button>
        </div>
      </t-loading>
    </t-drawer>

    <t-dialog
      v-model:visible="resetPasswordVisible"
      header="重置登录密码"
      width="420px"
      :confirm-btn="{ content: '确认重置', loading: serviceDrawer.actionLoading === 'reset-password' }"
      @cancel="resetPasswordVisible = false"
      @confirm="handleResetServicePassword"
    >
      <t-form ref="resetPasswordFormRef" :data="resetPasswordForm" :rules="resetPasswordRules" label-align="top">
        <t-form-item label="新密码" name="password">
          <t-input v-model="resetPasswordForm.password" type="password" placeholder="至少 8 位" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="manualProvisionVisible"
      header="手动开通 / 关联上游"
      width="420px"
      :confirm-btn="{ content: '确认关联', loading: serviceDrawer.actionLoading === 'manual-provision' }"
      @cancel="manualProvisionVisible = false"
      @confirm="handleManualProvision"
    >
      <t-form ref="manualProvisionFormRef" :data="manualProvisionForm" :rules="manualProvisionRules" label-align="top">
        <t-form-item label="上游实例 ID" name="upstream_host_id">
          <t-input-number v-model="manualProvisionForm.upstream_host_id" :min="1" style="width: 100%" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="serviceUpstreamVisible"
      header="上游绑定"
      width="520px"
      :confirm-btn="{ content: '保存', loading: serviceUpstreamSubmitting }"
      @cancel="serviceUpstreamVisible = false"
      @confirm="submitServiceUpstream"
    >
      <t-form ref="serviceUpstreamFormRef" :data="serviceUpstreamForm" :rules="serviceUpstreamRules" label-align="top">
        <t-form-item label="上游接口" name="supplier_id">
          <t-select
            v-model="serviceUpstreamForm.supplier_id"
            clearable
            filterable
            :loading="serviceUpstreamLoading"
            placeholder="请选择上游接口"
          >
            <t-option v-for="item in serviceUpstreamOptions" :key="item.id" :label="item.label" :value="item.id" />
          </t-select>
        </t-form-item>
        <t-form-item label="上游实例 ID" name="upstream_host_id">
          <t-input-number v-model="serviceUpstreamForm.upstream_host_id" :min="1" style="width: 100%" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="servicePricingVisible"
      header="调整价格"
      width="620px"
      :confirm-btn="{ content: '保存', loading: servicePricingSubmitting }"
      @cancel="servicePricingVisible = false"
      @confirm="submitServicePricing"
    >
      <t-form ref="servicePricingFormRef" :data="servicePricingForm" :rules="servicePricingRules" label-align="top">
        <t-form-item label="购买价格" name="amount">
          <t-input-number v-model="servicePricingForm.amount" :min="0" :decimal-places="2" style="width: 100%" />
        </t-form-item>
        <div v-if="servicePricingEntries.length" class="pricing-list">
          <div v-for="item in servicePricingEntries" :key="item.cycle" class="pricing-row">
            <div>
              <strong>{{ item.label }}</strong>
              <span>基础价 {{ item.base_amount ? formatMoney(item.base_amount) : '未配置' }}</span>
            </div>
            <t-switch v-model="servicePricingForm.locked_pricing[item.cycle].enabled" />
            <t-input-number
              v-model="servicePricingForm.locked_pricing[item.cycle].manual_amount"
              :min="0"
              :decimal-places="2"
              placeholder="手动价"
            />
          </div>
          <t-checkbox v-model="servicePricingForm.clear_locked_pricing">恢复默认续费价格</t-checkbox>
        </div>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="serviceNameVisible"
      header="修改实例名称"
      width="420px"
      :confirm-btn="{ content: '保存', loading: serviceNameSubmitting }"
      @cancel="serviceNameVisible = false"
      @confirm="submitServiceName"
    >
      <t-form :data="serviceNameForm" label-align="top">
        <t-form-item label="实例名称">
          <t-input v-model="serviceNameForm.service_name" :maxlength="120" placeholder="填写便于识别的实例名称" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="serviceRefundVisible"
      header="服务退款"
      width="500px"
      :confirm-btn="{ content: '确认退款', theme: 'danger', loading: serviceDrawer.actionLoading === 'refund' }"
      @cancel="serviceRefundVisible = false"
      @confirm="handleServiceRefund"
    >
      <t-alert theme="warning" message="退款将把对应账单标记为已退款，并关闭该实例的计费流程，当前仅支持全额退款。" />
      <t-form
        ref="serviceRefundFormRef"
        :data="serviceRefundForm"
        :rules="refundRules"
        label-align="top"
        class="dialog-form"
      >
        <t-form-item label="退款金额">
          <t-input :value="formatMoney(serviceRefundAmount)" disabled />
        </t-form-item>
        <t-form-item label="退款方式" name="refund_method">
          <t-radio-group v-model="serviceRefundForm.refund_method">
            <t-radio value="balance">退回余额</t-radio>
            <t-radio value="original" :disabled="!canOriginalServiceRefund">原路退款</t-radio>
          </t-radio-group>
        </t-form-item>
        <t-form-item label="退款原因" name="remark">
          <t-textarea v-model="serviceRefundForm.remark" :maxlength="200" placeholder="请输入退款原因" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-drawer v-model:visible="invoiceDrawer.visible" size="720px" header="账单详情" @close="closeInvoiceDrawer">
      <t-loading :loading="invoiceDrawer.loading" size="small">
        <div class="invoice-detail-panel">
          <section class="invoice-summary">
            <div class="invoice-summary__main">
              <span>账单编号</span>
              <strong>{{ fieldValue(currentInvoice.invoice_no) }}</strong>
              <p>
                {{
                  fieldValue(
                    currentInvoice.product_full_path ||
                      currentInvoice.product_spec_display ||
                      currentInvoice.product_display_name ||
                      currentInvoice.product?.display_name,
                  )
                }}
              </p>
            </div>
            <div>
              <span>状态</span>
              <t-tag :theme="invoiceStatusTheme(currentInvoice.status)" variant="light">{{
                invoiceStatusLabel(currentInvoice.status)
              }}</t-tag>
            </div>
            <div>
              <span>金额</span>
              <strong>{{ formatMoney(currentInvoice.amount) }}</strong>
            </div>
            <div>
              <span>支付方式</span>
              <strong>{{ fieldValue(currentInvoice.payment_summary?.gateway) }}</strong>
            </div>
          </section>

          <div class="drawer-actions">
            <t-button
              v-if="Number(currentInvoice.service?.id || 0)"
              theme="primary"
              variant="outline"
              @click="viewInvoiceLinkedService"
              >查看服务</t-button
            >
            <t-button theme="default" :loading="invoiceDrawer.loading" @click="reloadInvoiceDrawer">刷新</t-button>
            <t-button
              v-if="isCancelableInvoice(currentInvoice)"
              theme="danger"
              variant="outline"
              :loading="invoiceDrawer.cancelLoading"
              @click="handleDrawerCancelInvoice"
              >取消账单</t-button
            >
            <t-button
              v-if="canRefundInvoice"
              theme="danger"
              :loading="invoiceRefundLoading"
              @click="openInvoiceRefundDialog"
              >退款</t-button
            >
          </div>

          <section class="invoice-detail-section">
            <h4>基础信息</h4>
            <div class="invoice-detail-grid">
              <div>
                <span>账单类型</span>
                <strong>{{ currentInvoice.type_label || invoiceTypeLabel(currentInvoice.type) }}</strong>
              </div>
              <div>
                <span>订单号</span>
                <strong>{{ fieldValue(currentInvoice.order?.order_no || currentInvoice.order_no) }}</strong>
              </div>
              <div>
                <span>到期日</span>
                <strong>{{ fieldValue(currentInvoice.due_date) }}</strong>
              </div>
              <div>
                <span>创建时间</span>
                <strong>{{ formatDateTime(currentInvoice.created_at) }}</strong>
              </div>
              <div>
                <span>支付时间</span>
                <strong>{{ formatDateTime(currentInvoice.paid_at) }}</strong>
              </div>
            </div>
          </section>

          <section v-if="invoiceSceneItems.length" class="invoice-detail-section">
            <h4>账单项目</h4>
            <div class="line-list">
              <div v-for="item in invoiceSceneItems" :key="item.id || item.description" class="line-item">
                <span>{{ fieldValue(item.description) }}</span>
                <strong>{{ formatMoney(item.amount) }}</strong>
              </div>
            </div>
          </section>

          <section v-if="invoicePayments.length" class="invoice-detail-section">
            <h4>支付 / 退款记录</h4>
            <div class="line-list">
              <div v-for="payment in invoicePayments" :key="payment.id || payment.payment_no" class="line-item stacked">
                <strong>{{ fieldValue(payment.payment_no) }}</strong>
                <span>{{ fieldValue(payment.gateway) }} / {{ formatMoney(payment.amount) }}</span>
                <span>{{ formatDateTime(payment.paid_at || payment.created_at) }}</span>
              </div>
            </div>
          </section>

          <section v-if="invoiceLogs.length" class="invoice-detail-section">
            <h4>操作日志</h4>
            <div class="line-list">
              <div v-for="log in invoiceLogs" :key="log.id || log.created_at" class="line-item stacked">
                <strong>{{ fieldValue(log.summary || log.action) }}</strong>
                <span>{{ formatDateTime(log.created_at) }}</span>
              </div>
            </div>
          </section>
          <div class="drawer-close-actions">
            <t-button variant="outline" @click="closeInvoiceDrawer">
              <template #icon><chevron-left-icon /></template>
              返回
            </t-button>
          </div>
        </div>
      </t-loading>
    </t-drawer>

    <t-dialog
      v-model:visible="invoiceRefundVisible"
      header="账单退款"
      width="500px"
      :confirm-btn="{ content: '确认退款', theme: 'danger', loading: invoiceRefundLoading }"
      @cancel="invoiceRefundVisible = false"
      @confirm="handleInvoiceRefund"
    >
      <t-form ref="invoiceRefundFormRef" :data="invoiceRefundForm" :rules="refundRules" label-align="top">
        <t-form-item label="退款金额">
          <t-input :value="formatMoney(currentInvoice.paid_amount || currentInvoice.amount)" disabled />
        </t-form-item>
        <t-form-item label="退款方式" name="refund_method">
          <t-radio-group v-model="invoiceRefundForm.refund_method">
            <t-radio value="balance">退回余额</t-radio>
            <t-radio value="original" :disabled="!canOriginalInvoiceRefund">原路退款</t-radio>
          </t-radio-group>
        </t-form-item>
        <t-form-item label="退款原因" name="remark">
          <t-textarea v-model="invoiceRefundForm.remark" :maxlength="200" placeholder="请输入退款原因" />
        </t-form-item>
      </t-form>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { billingCycleLabel as billingCycleLabelOf } from '@shared/billingCycle';
import {
  INVOICE_STATUS_MAP,
  SERVICE_STATUS_MAP,
  toLabelMap,
  toSelectOptions,
  toTagTypeMap,
} from '@shared/statusConfig';
import { ChevronLeftIcon, EditIcon, SearchIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule, PageInfo, PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import type { ProductBindingRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import type { ProductRecord } from '@/api/product';
import { productApi } from '@/api/product';
import { supplierApi } from '@/api/supplier';
import type { AdminUser, PageParams } from '@/api/user';
import { userApi } from '@/api/user';
import ProductBindingTreeSelect from '@/components/product-binding-tree-select/index.vue';
import { AdminPermissions } from '@/constants/permissions';
import { fieldValue, formatDateTime, formatMoney } from '@/utils/format';
import { required } from '@/utils/formRules';
import { hasAdminPermission } from '@/utils/permission';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminUserDetail' });

type TabName = 'basic' | 'referral' | 'services' | 'invoices' | 'balance' | 'tickets' | 'logs' | 'notices';
type Row = Record<string, any>;

interface PageState {
  loading: boolean;
  refreshing?: boolean;
  refreshingStatus?: boolean;
  list: Row[];
  total: number;
  page: number;
  pageSize: number;
  filters: Record<string, string | number>;
  channel?: 'email' | 'sms';
}

const route = useRoute();
const router = useRouter();

const detailLoading = ref(false);
const saveLoading = ref(false);
const rechargeLoading = ref(false);
const loginAsLoading = ref(false);
const VALID_DETAIL_TABS: TabName[] = [
  'basic',
  'referral',
  'services',
  'invoices',
  'balance',
  'tickets',
  'logs',
  'notices',
];
const initialTab = route.query.tab as string;
const activeTab = ref<TabName>(
  initialTab && VALID_DETAIL_TABS.includes(initialTab as TabName) ? (initialTab as TabName) : 'basic',
);
const loadedTabs = reactive<Record<string, boolean>>({});
const user = ref<AdminUser>({ id: 0, status: 1 });
const stats = ref<Record<string, number | string>>({});
const referral = ref<Record<string, any>>({});

const editVisible = ref(false);
const rechargeVisible = ref(false);
const addServiceVisible = ref(false);
const addServiceLoading = ref(false);
const addServiceSubmitting = ref(false);
const addServiceOsLoading = ref(false);
const serviceUpstreamVisible = ref(false);
const serviceUpstreamLoading = ref(false);
const serviceUpstreamSubmitting = ref(false);
const servicePricingVisible = ref(false);
const servicePricingSubmitting = ref(false);
const serviceNameVisible = ref(false);
const serviceNameSubmitting = ref(false);
const resetPasswordVisible = ref(false);
const manualProvisionVisible = ref(false);
const serviceRefundVisible = ref(false);
const invoiceRefundVisible = ref(false);
const invoiceRefundLoading = ref(false);
const editFormRef = ref<FormInstanceFunctions>();
const rechargeFormRef = ref<FormInstanceFunctions>();
const addServiceFormRef = ref<FormInstanceFunctions>();
const serviceUpstreamFormRef = ref<FormInstanceFunctions>();
const servicePricingFormRef = ref<FormInstanceFunctions>();
const resetPasswordFormRef = ref<FormInstanceFunctions>();
const manualProvisionFormRef = ref<FormInstanceFunctions>();
const serviceRefundFormRef = ref<FormInstanceFunctions>();
const invoiceRefundFormRef = ref<FormInstanceFunctions>();
const editForm = reactive({
  nickname: '',
  phone: '',
  password: '',
  status: 1,
  agent_group_id: null as number | null,
});
const agentGroupOptions = ref<Array<{ id: number; name?: string; code?: string }>>([]);
const agentGroupOptionsLoading = ref(false);
const noteEditing = ref(false);
const noteSaving = ref(false);
const noteForm = ref('');
const rechargeForm = reactive({ email: '', type: 'increase', amount: 0, remark: '' });
const addServiceForm = reactive({
  product_id: undefined as number | undefined,
  billing_cycle: '',
  status: 1,
  name: '',
  amount: 0,
  auto_renew: 1,
  create_order: 1,
  create_invoice: 1,
  deduct_balance: 1,
  os: '',
  remark: '',
});
const serviceUpstreamForm = reactive({
  supplier_id: undefined as number | undefined,
  upstream_host_id: undefined as number | undefined,
});
const servicePricingForm = reactive({
  amount: 0,
  locked_pricing: {} as Record<
    string,
    { enabled: boolean; base_amount?: number | string | null; manual_amount?: number | null | '' }
  >,
  clear_locked_pricing: false,
});
const serviceNameForm = reactive({ service_name: '' });
const resetPasswordForm = reactive({ password: '' });
const manualProvisionForm = reactive({ upstream_host_id: undefined as number | undefined });
const serviceRefundForm = reactive({ refund_method: 'balance' as 'balance' | 'original', remark: '' });
const invoiceRefundForm = reactive({ refund_method: 'balance' as 'balance' | 'original', remark: '' });
const serviceDrawer = reactive({ visible: false, loading: false, actionLoading: '', serviceId: 0, detail: {} as Row });
const invoiceDrawer = reactive({
  visible: false,
  loading: false,
  cancelLoading: false,
  currentId: 0,
  detail: { invoice: {}, payments: [], items: [], logs: [] } as Row,
});
const canLoginAs = computed(() => hasAdminPermission(AdminPermissions.USER_LOGIN_AS));
const canManageUsers = computed(() => hasAdminPermission(AdminPermissions.USER_MANAGE));
const LOGIN_AS_READY_EVENT = 'turaidc:login-as-ready';
const LOGIN_AS_CODE_EVENT = 'turaidc:login-as-code';
const LOGIN_AS_READY_TIMEOUT_MS = 10000;
const addServiceProductId = ref<number | undefined>();
const addServiceProductIdArray = computed<(string | number)[]>({
  get: () => (addServiceProductId.value ? [addServiceProductId.value] : []),
  set: (val) => {
    const values = normalizeSelectionArray(val);
    addServiceProductId.value = values.length ? Number(values[0]) : undefined;
  },
});
const addServiceProductDetail = ref<ProductRecord | null>(null);
const addServiceOsOptions = ref<Row[]>([]);
const serviceUpstreamOptions = ref<Array<{ id: number; label: string }>>([]);

const services = reactive<PageState>({
  loading: false,
  refreshing: false,
  refreshingStatus: false,
  list: [],
  total: 0,
  page: 1,
  pageSize: 10,
  filters: { keyword: '', status: '' },
});
const invoices = reactive<PageState>({
  loading: false,
  list: [],
  total: 0,
  page: 1,
  pageSize: 10,
  filters: { status: '', type: '' },
});
const balance = reactive<PageState>({ loading: false, list: [], total: 0, page: 1, pageSize: 10, filters: {} });
const tickets = reactive<PageState>({ loading: false, list: [], total: 0, page: 1, pageSize: 10, filters: {} });
const logs = reactive<PageState>({
  loading: false,
  list: [],
  total: 0,
  page: 1,
  pageSize: 10,
  filters: { keyword: '', start_date: '', end_date: '', ip_address: '', source: '' },
});
const notices = reactive<PageState>({
  loading: false,
  list: [],
  total: 0,
  page: 1,
  pageSize: 10,
  filters: {},
  channel: 'email',
});

const editRules: Record<string, FormRule[]> = {
  // 后端 UpdateUserRequest 是 ['nullable','string','min:8']。此前写 6 且是 warning——
  // 既放行 6-7 位（必然 422），warning 也不阻断提交。改为与后端同口径的 error。
  // 按 trim 后长度校验：handleSave 提交的是 editForm.password.trim()，
  // 若按原始长度校验，" 1234567 " 这类值会在前端放行、到后端才 422。
  password: [
    {
      validator: (val) => !String(val ?? '').trim() || String(val).trim().length >= 8,
      message: '密码至少需要 8 位',
      type: 'error',
    },
  ],
};
const rechargeRules: Record<string, FormRule[]> = {
  amount: [required('请输入金额')],
  remark: [required('请填写操作备注')],
};
const addServiceRules: Record<string, FormRule[]> = {
  product_id: [required('请选择商品')],
  billing_cycle: [required('请选择计费周期')],
  status: [required('请选择服务状态')],
  amount: [required('请输入服务金额')],
};
const serviceUpstreamRules: Record<string, FormRule[]> = {
  supplier_id: [{ validator: validateUpstreamPair, message: '选择上游接口时必须填写上游实例 ID', type: 'error' }],
  upstream_host_id: [{ validator: validateUpstreamPair, message: '填写上游实例 ID 时必须选择上游接口', type: 'error' }],
};
const servicePricingRules: Record<string, FormRule[]> = {
  amount: [required('请输入购买价格')],
};
const resetPasswordRules: Record<string, FormRule[]> = {
  password: [{ validator: (val) => String(val || '').length >= 8, message: '密码长度至少 8 位', type: 'error' }],
};
const manualProvisionRules: Record<string, FormRule[]> = {
  upstream_host_id: [required('请输入上游实例 ID')],
};
const refundRules: Record<string, FormRule[]> = {
  refund_method: [required('请选择退款方式')],
  remark: [required('请填写退款原因')],
};

const serviceStatusLabelMap = toLabelMap(SERVICE_STATUS_MAP);
const serviceStatusTypeMap = toTagTypeMap(SERVICE_STATUS_MAP);
const invoiceStatusLabelMap = toLabelMap(INVOICE_STATUS_MAP);
const invoiceStatusTypeMap = toTagTypeMap(INVOICE_STATUS_MAP);
const serviceStatusOptions = toSelectOptions(SERVICE_STATUS_MAP, false);
const invoiceStatusOptions = toSelectOptions(INVOICE_STATUS_MAP, false);
const invoiceTypeOptions = [
  { label: '新购', value: 'new' },
  { label: '续费', value: 'renew' },
  { label: '充值', value: 'recharge' },
  { label: '扣款', value: 'deduction' },
  { label: '推荐奖励', value: 'referral_credit' },
  { label: '手工', value: 'manual' },
];

const recentReferralColumns: PrimaryTableCol<TableRowData>[] = [
  { title: '用户', colKey: 'referralUser', width: 260 },
  { title: '推荐时间', colKey: 'referredAt', width: 180 },
];
const serviceColumns: PrimaryTableCol<TableRowData>[] = [
  { title: 'ID', colKey: 'id', width: 80 },
  { title: '配置/服务', colKey: 'serviceName', width: 240 },
  { title: '公网 IP', colKey: 'upstream.dedicated_ip', width: 140 },
  { title: '金额', colKey: 'serviceAmount', width: 120 },
  { title: '产品类型', colKey: 'product.type_label', width: 120 },
  { title: '计费周期', colKey: 'billing_cycle_label', width: 120 },
  { title: '购买时间', colKey: 'serviceCreated', width: 180 },
  { title: '到期时间', colKey: 'serviceExpires', width: 180 },
  { title: '状态', colKey: 'serviceStatus', width: 120 },
  { title: '操作', colKey: 'serviceOperation', width: 180 },
];
const invoiceColumns: PrimaryTableCol<TableRowData>[] = [
  { title: '账单编号', colKey: 'invoice_no', width: 180 },
  { title: '生成时间', colKey: 'invoiceCreated', width: 180 },
  { title: '到期时间', colKey: 'due_date', width: 140 },
  { title: '支付时间', colKey: 'invoicePaid', width: 180 },
  { title: '金额', colKey: 'invoiceAmount', width: 120 },
  { title: '支付方式', colKey: 'payment_summary.gateway', width: 140 },
  { title: '状态', colKey: 'invoiceStatus', width: 120 },
  { title: '账单类型', colKey: 'invoiceType', width: 140 },
  { title: '操作', colKey: 'invoiceOperation', width: 140 },
];
const balanceColumns: PrimaryTableCol<TableRowData>[] = [
  { title: '时间', colKey: 'balanceTime', width: 180 },
  { title: '类型', colKey: 'balanceType', width: 140 },
  { title: '变动金额', colKey: 'balanceChange', width: 140 },
  { title: '变动后余额', colKey: 'balanceAfter', width: 140 },
  { title: '备注', colKey: 'remark', width: 220 },
  { title: '操作人', colKey: 'operator', width: 140 },
];
const ticketColumns: PrimaryTableCol<TableRowData>[] = [
  { title: 'ID', colKey: 'id', width: 80 },
  { title: '标题', colKey: 'subject', width: 260 },
  { title: '优先级', colKey: 'ticketPriority', width: 120 },
  { title: '状态', colKey: 'ticketStatus', width: 120 },
  { title: '创建时间', colKey: 'ticketCreated', width: 180 },
];
const logColumns: PrimaryTableCol<TableRowData>[] = [
  { title: '时间', colKey: 'logTime', width: 180 },
  { title: '动作', colKey: 'action', width: 220 },
  { title: '模块', colKey: 'module', width: 140 },
  { title: 'IP', colKey: 'ip_address', width: 160 },
];
const noticeColumns: PrimaryTableCol<TableRowData>[] = [
  { title: '接收地址', colKey: 'noticeTarget', width: 200 },
  { title: '主题/模板', colKey: 'noticeTitle', width: 240 },
  { title: '状态', colKey: 'noticeStatus', width: 120 },
  { title: '发送时间', colKey: 'noticeTime', width: 180 },
];

const userId = computed(() => String(route.params.id || ''));
const isVerified = computed(
  () => Number(user.value.is_verified || 0) === 1 || Number(user.value.verification_status || 0) === 2,
);
const recentReferrals = computed(() =>
  Array.isArray(referral.value.recent_referrals) ? referral.value.recent_referrals : [],
);
const addServiceBillingOptions = computed(() => resolveBillingOptions(addServiceProductDetail.value));
const addServiceOsFlatOptions = computed(() => flattenOptionTree(addServiceOsOptions.value));
const serviceActions = computed(() => serviceDrawer.detail.actions || {});
const serviceSpecs = computed(() =>
  (Array.isArray(serviceDrawer.detail.specs) ? serviceDrawer.detail.specs : []).map((item: Row) => ({
    label: item.label || item.name || '-',
    value: item.value || '-',
  })),
);
const canPowerOn = computed(() => {
  const available = serviceActions.value.available || [];
  return (
    Array.isArray(available) &&
    available.includes('power:on') &&
    serviceDrawer.detail.runtime?.power_state !== 'running'
  );
});
const canPowerOff = computed(() => {
  const available = serviceActions.value.available || [];
  return (
    Array.isArray(available) &&
    available.includes('power:off') &&
    serviceDrawer.detail.runtime?.power_state === 'running'
  );
});
const canReboot = computed(() => {
  const available = serviceActions.value.available || [];
  return (
    Array.isArray(available) &&
    available.includes('power:reboot') &&
    serviceDrawer.detail.runtime?.power_state === 'running'
  );
});
const canRefundService = computed(() => {
  const status = Number(serviceDrawer.detail.status);
  if ([0, 5, 6].includes(status)) return false;
  const available = serviceActions.value.available;
  return !Array.isArray(available) || available.includes('refund');
});
const isServiceRefunded = computed(() => [5, 6].includes(Number(serviceDrawer.detail.status)));
const canOriginalServiceRefund = computed(() => serviceDrawer.detail.refund?.can_original !== false);
const serviceRefundAmount = computed(
  () => serviceDrawer.detail.refund?.amount ?? serviceDrawer.detail.amount ?? serviceDrawer.detail.order?.amount ?? 0,
);
const servicePricingEntries = computed(() =>
  Object.entries(servicePricingForm.locked_pricing || {}).map(([cycle, item]) => ({
    cycle,
    label: billingCycleLabel(cycle),
    base_amount: item?.base_amount || null,
  })),
);
const currentInvoice = computed(() => {
  const detail = invoiceDrawer.detail || {};
  return detail.invoice && typeof detail.invoice === 'object' ? detail.invoice : detail;
});
const invoicePayments = computed(() =>
  Array.isArray(invoiceDrawer.detail.payments) ? invoiceDrawer.detail.payments : [],
);
const invoiceLogs = computed(() => (Array.isArray(invoiceDrawer.detail.logs) ? invoiceDrawer.detail.logs : []));
const invoiceSceneItems = computed(() => {
  const sceneItems = currentInvoice.value.scene?.items;
  if (Array.isArray(sceneItems) && sceneItems.length) return sceneItems;
  return Array.isArray(invoiceDrawer.detail.items) ? invoiceDrawer.detail.items : [];
});
const canRefundInvoice = computed(() => Number(currentInvoice.value.status) === 1);
const primaryPayment = computed(() => invoicePayments.value.find((item: Row) => Number(item.status) === 1) || null);
const canOriginalInvoiceRefund = computed(() => primaryPayment.value?.gateway === 'alipay');
const statCards = computed(() => [
  { key: 'ticket_open', label: '在线工单', value: stats.value.ticket_open || 0, tone: 'warning' },
  { key: 'cash_balance', label: '余额', value: formatMoney(user.value.cash_balance), tone: 'success' },
  { key: 'total_expense', label: '总消费', value: formatMoney(stats.value.total_expense), tone: 'primary' },
]);
const referralItems = computed(() => [
  { label: '推荐码', value: referral.value.referral_code || '-' },
  { label: '当前等级', value: referral.value.member_level?.name || user.value.member_level?.name || '未分级' },
  { label: '直推人数', value: stats.value.direct_referral_count || 0 },
  { label: '累计奖励', value: formatMoney(stats.value.total_referral_reward), tone: 'success' },
  { label: '可提现奖励', value: formatMoney(referral.value.referral_available_amount), tone: 'success' },
]);
const infoItems = computed(() => [
  { label: '邮箱', value: fieldValue(user.value.email) },
  { label: '手机号', value: fieldValue(user.value.phone) },
  {
    label: '账号状态',
    value: Number(user.value.status) === 1 ? '正常' : '禁用',
    tone: Number(user.value.status) === 1 ? 'success' : 'danger',
  },
  { label: '公司', value: fieldValue(user.value.company) },
  { label: 'QQ', value: fieldValue(user.value.qq) },
  { label: '账户余额', value: formatMoney(user.value.cash_balance), tone: 'success' },
  { label: '会员等级', value: user.value.member_level?.name || '未分级' },
  { label: '代理组', value: user.value.agent_group?.name || '未设置' },
  { label: '实名认证', value: verificationText(), tone: isVerified.value ? 'success' : 'warning' },
  { label: '证件号', value: fieldValue(user.value.id_card_masked) },
  { label: '推荐人 ID', value: fieldValue(user.value.referrer_user_id) },
  { label: '最后登录时间', value: formatDateTime(user.value.last_login_at) },
  { label: '最后登录 IP', value: fieldValue(user.value.last_login_ip) },
]);

async function loadDetail() {
  if (!userId.value) {
    await router.replace('/admin/users');
    return;
  }
  detailLoading.value = true;
  try {
    const response = await userApi.detail(userId.value);
    user.value = { id: userId.value, status: 1, ...(response.user || {}) };
    stats.value = response.stats || {};
    referral.value = response.referral || {};
    syncEditForm();
  } finally {
    detailLoading.value = false;
  }
}

function syncEditForm() {
  editForm.nickname = String(user.value.nickname || '');
  editForm.phone = String(user.value.phone || '');
  editForm.password = '';
  editForm.status = Number(user.value.status ?? 1);
  editForm.agent_group_id = Number(user.value.agent_group_id ?? user.value.agent_group?.id ?? 0) || null;
}

async function loadAgentGroupOptions() {
  if (agentGroupOptions.value.length) return;
  agentGroupOptionsLoading.value = true;
  try {
    const response = await adminApi.agentDiscount.agentGroups.list();
    agentGroupOptions.value = (Array.isArray(response) ? response : [])
      .filter((item) => Number(item.status) === 1)
      .map((item) => ({ id: Number(item.id), name: String(item.name || ''), code: String(item.code || '') }));
  } catch {
    agentGroupOptions.value = [];
  } finally {
    agentGroupOptionsLoading.value = false;
  }
}

function handleTabChange(value: string | number) {
  activeTab.value = String(value) as TabName;
  router.replace({ query: { ...route.query, tab: activeTab.value === 'basic' ? undefined : activeTab.value } });
  if (activeTab.value === 'services' && !loadedTabs.services) loadServices();
  if (activeTab.value === 'invoices' && !loadedTabs.invoices) loadInvoices();
  if (activeTab.value === 'balance' && !loadedTabs.balance) loadBalance();
  if (activeTab.value === 'tickets' && !loadedTabs.tickets) loadTickets();
  if (activeTab.value === 'logs' && !loadedTabs.logs) loadLogs();
  if (activeTab.value === 'notices' && !loadedTabs.notices) loadNotices();
}

async function loadPageState(
  state: PageState,
  loader: (params: PageParams) => Promise<{ list?: Row[]; total?: number; page?: number; page_size?: number }>,
  loadedKey: string,
) {
  state.loading = true;
  try {
    const response = await loader({ ...state.filters, page: state.page, page_size: state.pageSize });
    state.list = Array.isArray(response.list) ? response.list : [];
    state.total = Number(response.total || 0);
    state.page = Number(response.page || state.page);
    state.pageSize = Number(response.page_size || state.pageSize);
    loadedTabs[loadedKey] = true;
  } finally {
    state.loading = false;
  }
}

function loadServices() {
  return loadPageState(services, (params) => userApi.services(userId.value, params), 'services');
}
function loadInvoices() {
  return loadPageState(invoices, (params) => userApi.invoices(userId.value, params), 'invoices');
}
function loadBalance() {
  return loadPageState(balance, (params) => userApi.balanceLogs(userId.value, params), 'balance');
}
function loadTickets() {
  return loadPageState(tickets, (params) => userApi.tickets(userId.value, params), 'tickets');
}
function loadLogs() {
  return loadPageState(logs, (params) => userApi.operationLogs(userId.value, params), 'logs');
}
function loadNotices() {
  const method = notices.channel === 'email' ? userApi.emailLogs : userApi.smsLogs;
  return loadPageState(notices, (params) => method(userId.value, params), 'notices');
}

function searchServices() {
  services.page = 1;
  loadServices();
}
function searchInvoices() {
  invoices.page = 1;
  loadInvoices();
}
function searchLogs() {
  logs.page = 1;
  loadLogs();
}
function reloadNotices() {
  notices.page = 1;
  loadNotices();
}
function handlePageChange(state: PageState, loader: () => Promise<void>, pageInfo: PageInfo) {
  state.page = pageInfo.current;
  state.pageSize = pageInfo.pageSize;
  loader();
}
function handleServicesPageChange(pageInfo: PageInfo) {
  handlePageChange(services, loadServices, pageInfo);
}
function handleInvoicesPageChange(pageInfo: PageInfo) {
  handlePageChange(invoices, loadInvoices, pageInfo);
}
function handleBalancePageChange(pageInfo: PageInfo) {
  handlePageChange(balance, loadBalance, pageInfo);
}
function handleTicketsPageChange(pageInfo: PageInfo) {
  handlePageChange(tickets, loadTickets, pageInfo);
}
function handleLogsPageChange(pageInfo: PageInfo) {
  handlePageChange(logs, loadLogs, pageInfo);
}
function handleNoticesPageChange(pageInfo: PageInfo) {
  handlePageChange(notices, loadNotices, pageInfo);
}

function openEditDialog() {
  syncEditForm();
  if (canManageUsers.value) loadAgentGroupOptions();
  editVisible.value = true;
}

function startEditNote() {
  noteForm.value = user.value.admin_note || '';
  noteEditing.value = true;
}

async function saveNote() {
  noteSaving.value = true;
  try {
    await userApi.update(userId.value, { admin_note: noteForm.value });
    user.value.admin_note = noteForm.value;
    noteEditing.value = false;
    MessagePlugin.success('备注已保存');
  } finally {
    noteSaving.value = false;
  }
}

async function handleSave() {
  const result = await editFormRef.value?.validate?.();
  if (!isValidationPass(result)) return;
  saveLoading.value = true;
  try {
    await userApi.update(userId.value, {
      nickname: editForm.nickname,
      phone: editForm.phone,
      status: editForm.status,
      agent_group_id: canManageUsers.value ? editForm.agent_group_id : undefined,
      ...(editForm.password.trim() ? { password: editForm.password.trim() } : {}),
    });
    MessagePlugin.success('用户资料已更新');
    editVisible.value = false;
    await loadDetail();
  } finally {
    saveLoading.value = false;
  }
}

function openRechargeDialog() {
  rechargeForm.email = user.value.email || user.value.phone || '-';
  rechargeForm.type = 'increase';
  rechargeForm.amount = 0;
  rechargeForm.remark = '';
  rechargeVisible.value = true;
}

async function handleRecharge() {
  const result = await rechargeFormRef.value?.validate?.();
  if (!isValidationPass(result)) return;
  rechargeLoading.value = true;
  try {
    const amount = rechargeForm.type === 'decrease' ? -rechargeForm.amount : rechargeForm.amount;
    await userApi.recharge(userId.value, { amount, remark: rechargeForm.remark });
    MessagePlugin.success(rechargeForm.type === 'decrease' ? '扣减成功' : '增加成功');
    rechargeVisible.value = false;
    await loadDetail();
    if (loadedTabs.balance) await loadBalance();
  } finally {
    rechargeLoading.value = false;
  }
}

async function openAddServiceDialog() {
  resetAddServiceForm();
  addServiceVisible.value = true;
}

function resetAddServiceForm() {
  Object.assign(addServiceForm, {
    product_id: undefined,
    billing_cycle: '',
    status: 1,
    name: '',
    amount: 0,
    auto_renew: 1,
    create_order: 1,
    create_invoice: 1,
    deduct_balance: 1,
    os: '',
    remark: '',
  });
  addServiceProductId.value = undefined;
  addServiceProductDetail.value = null;
  addServiceFormRef.value?.clearValidate?.();
}

async function handleAddServiceProductChange(
  payload: { binding_ids?: unknown; bindings?: ProductBindingRecord[] } | string | number | Array<string | number>,
) {
  const bindingIds =
    typeof payload === 'object' && !Array.isArray(payload) && payload !== null && 'binding_ids' in payload
      ? normalizeSelectionArray(payload.binding_ids)
      : normalizeSelectionArray(payload);
  const productId = Number(bindingIds[0] || 0);
  addServiceForm.product_id = productId || undefined;
  addServiceProductDetail.value = null;
  addServiceForm.billing_cycle = '';
  addServiceForm.amount = 0;
  addServiceForm.os = '';
  addServiceOsOptions.value = [];
  if (!productId) return;

  addServiceLoading.value = true;
  try {
    const detail = await productApi.detail(productId);
    addServiceProductDetail.value = detail;
    addServiceForm.name = productLabel(detail);
    const firstCycle = addServiceBillingOptions.value[0];
    addServiceForm.billing_cycle = firstCycle?.value || '';
    addServiceForm.amount = firstCycle?.amount || 0;
    await fetchAddServiceOsOptions();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载商品详情失败'));
  } finally {
    addServiceLoading.value = false;
  }
}

async function fetchAddServiceOsOptions() {
  addServiceOsLoading.value = true;
  try {
    const response = await userApi.osOptions();
    addServiceOsOptions.value = Array.isArray(response.groups) ? response.groups : [];
  } catch {
    addServiceOsOptions.value = [];
  } finally {
    addServiceOsLoading.value = false;
  }
}

function syncAddServiceAmountFromCycle() {
  const matched = addServiceBillingOptions.value.find((item) => item.value === addServiceForm.billing_cycle);
  addServiceForm.amount = matched?.amount || 0;
}

function handleAddServiceCreateInvoiceChange(value: unknown) {
  if (Number(value) !== 1) {
    addServiceForm.deduct_balance = 0;
  }
}

async function handleSubmitAddService() {
  const result = await addServiceFormRef.value?.validate?.();
  if (!isValidationPass(result)) return;
  addServiceSubmitting.value = true;
  try {
    await userApi.storeService(userId.value, {
      product_id: Number(addServiceForm.product_id || 0),
      billing_cycle: addServiceForm.billing_cycle,
      status: addServiceForm.status,
      name: addServiceForm.name,
      amount: toNumber(addServiceForm.amount),
      auto_renew: Number(addServiceForm.auto_renew ? 1 : 0),
      create_order: Number(addServiceForm.create_order ? 1 : 0),
      create_invoice: Number(addServiceForm.create_invoice ? 1 : 0),
      deduct_balance: Number(addServiceForm.deduct_balance ? 1 : 0),
      os: addServiceForm.os,
      remark: addServiceForm.remark,
    });
    MessagePlugin.success('实例已创建');
    addServiceVisible.value = false;
    await Promise.all([
      loadServices(),
      loadDetail(),
      ...(loadedTabs.invoices ? [loadInvoices()] : []),
      ...(loadedTabs.balance ? [loadBalance()] : []),
    ]);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '添加实例失败'));
  } finally {
    addServiceSubmitting.value = false;
  }
}

async function handleRefreshService(row: Row) {
  if (!row?.id) return;
  services.refreshing = true;
  try {
    const detail = await userApi.serviceRemoteStatus(userId.value, row.id);
    patchServiceListItem(detail);
    MessagePlugin.success('服务状态已刷新');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '刷新服务状态失败'));
  } finally {
    services.refreshing = false;
  }
}

async function handleRefreshServicesStatus() {
  if (!services.list.length) return;
  services.refreshingStatus = true;
  try {
    await userApi.refreshServiceStatuses(userId.value, { service_ids: services.list.map((item) => item.id) });
    await loadServices();
    MessagePlugin.success('服务状态已批量刷新');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '批量刷新失败'));
  } finally {
    services.refreshingStatus = false;
  }
}

function handleDeleteServiceRow(row: Row) {
  if (!row?.id) return;
  const dialog = DialogPlugin.confirm({
    header: '删除实例记录',
    body: `确认删除实例“${serviceName(row)}”记录吗？`,
    confirmBtn: '确认删除',
    cancelBtn: '取消',
    theme: 'warning',
    async onConfirm() {
      services.refreshing = true;
      try {
        await userApi.serviceDelete(userId.value, row.id);
        MessagePlugin.success('实例记录已删除');
        dialog.hide();
        await Promise.all([loadServices(), loadDetail()]);
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '删除服务记录失败'));
      } finally {
        services.refreshing = false;
      }
    },
  });
}

async function handleLoginAs() {
  if (!canLoginAs.value) {
    MessagePlugin.error('当前账号没有代登录权限');
    return;
  }

  const popup = window.open('about:blank', '_blank');
  if (!popup) {
    MessagePlugin.error('浏览器拦截了代登录窗口，请允许弹窗后重试');
    return;
  }

  loginAsLoading.value = true;
  try {
    const response = await userApi.loginAs(userId.value);
    const code = String(response.login_code || '').trim();
    if (!code) {
      closeLoginAsPopup(popup);
      MessagePlugin.error('未获取到代登录凭证');
      return;
    }

    const target = resolveLoginAsTarget(response.target_url);
    if (!target) {
      closeLoginAsPopup(popup);
      MessagePlugin.error('客户端代登录地址无效，请检查客户端控制台配置');
      return;
    }

    popup.location.replace(target);
    await waitForLoginAsReady(popup, target);
    popup.postMessage({ type: LOGIN_AS_CODE_EVENT, code }, new URL(target, window.location.origin).origin);
    MessagePlugin.success('已打开客户端登录页');
  } catch (error) {
    closeLoginAsPopup(popup);
    MessagePlugin.error(errorMessage(error, '代登录失败'));
  } finally {
    loginAsLoading.value = false;
  }
}

function closeLoginAsPopup(popup: Window) {
  if (!popup.closed) {
    popup.close();
  }
}

function resolveLoginAsTarget(targetUrl: string | undefined) {
  const rawTarget = String(targetUrl || '').trim();
  if (!rawTarget) return '';

  try {
    const target = new URL(rawTarget, window.location.origin);
    if (!['http:', 'https:'].includes(target.protocol)) {
      return '';
    }
    target.pathname = '/client/login-as';
    target.search = '';
    target.hash = '';
    return target.toString();
  } catch {
    return '';
  }
}

function waitForLoginAsReady(targetWindow: Window, targetUrl: string) {
  const targetOrigin = new URL(targetUrl, window.location.origin).origin;

  return new Promise<void>((resolve, reject) => {
    const timer = window.setTimeout(() => {
      window.removeEventListener('message', handleMessage);
      reject(new Error('客户端代登录窗口未完成初始化'));
    }, LOGIN_AS_READY_TIMEOUT_MS);

    function handleMessage(event: MessageEvent) {
      if (event.source !== targetWindow || event.origin !== targetOrigin) {
        return;
      }

      if (event.data?.type !== LOGIN_AS_READY_EVENT) {
        return;
      }

      window.clearTimeout(timer);
      window.removeEventListener('message', handleMessage);
      resolve();
    }

    window.addEventListener('message', handleMessage);
  });
}

function closeServiceDrawer() {
  serviceDrawer.visible = false;
  serviceDrawer.loading = false;
  serviceDrawer.actionLoading = '';
  serviceDrawer.serviceId = 0;
  serviceDrawer.detail = {};
}

async function reloadServiceDrawer() {
  if (!serviceDrawer.serviceId) return;
  serviceDrawer.loading = true;
  try {
    const detail = await userApi.serviceDetail(userId.value, serviceDrawer.serviceId);
    serviceDrawer.detail = normalizeServiceDetail(detail);
    patchServiceListItem(detail);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载实例详情失败'));
  } finally {
    serviceDrawer.loading = false;
  }
}

async function handleRefreshConsoleRemoteStatus() {
  if (!serviceDrawer.serviceId) return;
  serviceDrawer.actionLoading = 'remote-status';
  try {
    const detail = await userApi.serviceRemoteStatus(userId.value, serviceDrawer.serviceId);
    serviceDrawer.detail = mergeServiceDetail(serviceDrawer.detail, detail);
    patchServiceListItem(detail);
    MessagePlugin.success('远程状态已刷新');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '刷新远程状态失败'));
  } finally {
    serviceDrawer.actionLoading = '';
  }
}

function handleServicePower(action: string) {
  if (!serviceDrawer.serviceId || !action) return;
  const label = ({ on: '开机', off: '关机', reboot: '重启' } as Record<string, string>)[action] || action;
  const dialog = DialogPlugin.confirm({
    header: `${label}确认`,
    body: `确认对实例执行“${label}”操作？`,
    confirmBtn: `确认${label}`,
    cancelBtn: '取消',
    theme: 'warning',
    async onConfirm() {
      serviceDrawer.actionLoading = `power:${action}`;
      try {
        const response = await userApi.servicePower(userId.value, serviceDrawer.serviceId, { action });
        if (response.detail) serviceDrawer.detail = mergeServiceDetail(serviceDrawer.detail, response.detail);
        MessagePlugin.success(response.message || `${label}指令已下发`);
        dialog.hide();
        await handleRefreshConsoleRemoteStatus();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, `${label}失败`));
      } finally {
        serviceDrawer.actionLoading = '';
      }
    },
  });
}

function openResetPasswordDialog() {
  resetPasswordForm.password = '';
  resetPasswordVisible.value = true;
  resetPasswordFormRef.value?.clearValidate?.();
}

async function handleResetServicePassword() {
  const result = await resetPasswordFormRef.value?.validate?.();
  if (!isValidationPass(result) || !serviceDrawer.serviceId) return;
  serviceDrawer.actionLoading = 'reset-password';
  try {
    await userApi.serviceResetPassword(userId.value, serviceDrawer.serviceId, { password: resetPasswordForm.password });
    MessagePlugin.success('密码重置指令已下发');
    resetPasswordVisible.value = false;
    await reloadServiceDrawer();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '密码重置失败'));
  } finally {
    serviceDrawer.actionLoading = '';
  }
}

function openManualProvisionDialog() {
  manualProvisionForm.upstream_host_id = undefined;
  manualProvisionVisible.value = true;
  manualProvisionFormRef.value?.clearValidate?.();
}

async function handleManualProvision() {
  const result = await manualProvisionFormRef.value?.validate?.();
  if (!isValidationPass(result) || !serviceDrawer.serviceId) return;
  serviceDrawer.actionLoading = 'manual-provision';
  try {
    await userApi.manualProvisionService(userId.value, serviceDrawer.serviceId, {
      upstream_host_id: Number(manualProvisionForm.upstream_host_id || 0),
    });
    MessagePlugin.success('手动开通指令已下发');
    manualProvisionVisible.value = false;
    await Promise.all([reloadServiceDrawer(), loadServices()]);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '手动开通失败'));
  } finally {
    serviceDrawer.actionLoading = '';
  }
}

async function openServiceUpstreamDialog() {
  await loadServiceUpstreamOptions();
  serviceUpstreamForm.supplier_id = Number(serviceDrawer.detail.upstream?.supplier_id || 0) || undefined;
  serviceUpstreamForm.upstream_host_id = Number(serviceDrawer.detail.upstream?.host_id || 0) || undefined;
  serviceUpstreamVisible.value = true;
  serviceUpstreamFormRef.value?.clearValidate?.();
}

async function loadServiceUpstreamOptions() {
  if (serviceUpstreamOptions.value.length) return;
  serviceUpstreamLoading.value = true;
  try {
    const response = await supplierApi.list({ status: 1, page: 1, page_size: 100 });
    serviceUpstreamOptions.value = (Array.isArray(response.list) ? response.list : [])
      .map((item) => {
        const id = Number(item.id || 0);
        const upstreamBinding =
          item.upstream_binding && typeof item.upstream_binding === 'object' ? (item.upstream_binding as Row) : {};
        const type = item.provider_label || upstreamBinding.provider_key || '上游';
        return { id, label: `${item.name || `接口 #${item.id}`} · ${type}` };
      })
      .filter((item) => item.id > 0);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载上游接口失败'));
  } finally {
    serviceUpstreamLoading.value = false;
  }
}

async function submitServiceUpstream() {
  const result = await serviceUpstreamFormRef.value?.validate?.();
  if (!isValidationPass(result) || !serviceDrawer.serviceId) return;
  serviceUpstreamSubmitting.value = true;
  serviceDrawer.actionLoading = 'meta-update';
  try {
    const detail = await userApi.updateServiceMeta(userId.value, serviceDrawer.serviceId, {
      supplier_id: serviceUpstreamForm.supplier_id ? Number(serviceUpstreamForm.supplier_id) : null,
      upstream_host_id: serviceUpstreamForm.upstream_host_id ? Number(serviceUpstreamForm.upstream_host_id) : null,
    });
    serviceDrawer.detail = mergeServiceDetail(serviceDrawer.detail, detail);
    patchServiceListItem(serviceDrawer.detail);
    serviceUpstreamVisible.value = false;
    MessagePlugin.success('上游绑定已更新');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '更新上游绑定失败'));
  } finally {
    serviceUpstreamSubmitting.value = false;
    serviceDrawer.actionLoading = '';
  }
}

function openServicePricingDialog() {
  servicePricingForm.amount = toNumber(serviceDrawer.detail.amount);
  servicePricingForm.locked_pricing = createLockedPricingForm(serviceDrawer.detail);
  servicePricingForm.clear_locked_pricing = false;
  servicePricingVisible.value = true;
  servicePricingFormRef.value?.clearValidate?.();
}

async function submitServicePricing() {
  const result = await servicePricingFormRef.value?.validate?.();
  if (!isValidationPass(result) || !serviceDrawer.serviceId) return;
  const payload: Row = { amount: toNumber(servicePricingForm.amount) };
  if (servicePricingForm.clear_locked_pricing) {
    payload.clear_locked_pricing = true;
  } else {
    payload.locked_pricing = Object.entries(servicePricingForm.locked_pricing).reduce(
      (resultMap, [cycle, item]) => {
        resultMap[cycle] = {
          enabled: Boolean(item.enabled),
          manual_amount:
            item.manual_amount === '' || item.manual_amount === null || item.manual_amount === undefined
              ? null
              : toNumber(item.manual_amount),
        };
        return resultMap;
      },
      {} as Record<string, { enabled: boolean; manual_amount: number | null }>,
    );
  }
  servicePricingSubmitting.value = true;
  serviceDrawer.actionLoading = 'meta-update';
  try {
    const detail = await userApi.updateServiceMeta(userId.value, serviceDrawer.serviceId, payload);
    serviceDrawer.detail = mergeServiceDetail(serviceDrawer.detail, detail);
    patchServiceListItem(serviceDrawer.detail);
    servicePricingVisible.value = false;
    MessagePlugin.success('价格信息已更新');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '更新价格信息失败'));
  } finally {
    servicePricingSubmitting.value = false;
    serviceDrawer.actionLoading = '';
  }
}

function openServiceNameDialog() {
  serviceNameForm.service_name = String(serviceDrawer.detail.custom_service_name || serviceDrawer.detail.name || '');
  serviceNameVisible.value = true;
}

async function submitServiceName() {
  if (!serviceDrawer.serviceId) return;
  serviceNameSubmitting.value = true;
  serviceDrawer.actionLoading = 'meta-update';
  try {
    const detail = await userApi.updateServiceMeta(userId.value, serviceDrawer.serviceId, {
      service_name: serviceNameForm.service_name,
    });
    serviceDrawer.detail = mergeServiceDetail(serviceDrawer.detail, detail);
    patchServiceListItem(serviceDrawer.detail);
    serviceNameVisible.value = false;
    MessagePlugin.success('实例名称已更新');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '更新实例名称失败'));
  } finally {
    serviceNameSubmitting.value = false;
    serviceDrawer.actionLoading = '';
  }
}

function openServiceRefundDialog() {
  serviceRefundForm.refund_method = 'balance';
  serviceRefundForm.remark = '';
  serviceRefundVisible.value = true;
  serviceRefundFormRef.value?.clearValidate?.();
}

async function handleServiceRefund() {
  const result = await serviceRefundFormRef.value?.validate?.();
  if (!isValidationPass(result) || !serviceDrawer.serviceId) return;
  serviceDrawer.actionLoading = 'refund';
  try {
    const response = await userApi.refundService(userId.value, serviceDrawer.serviceId, {
      refund_method: serviceRefundForm.refund_method,
      amount: serviceRefundAmount.value,
      remark: serviceRefundForm.remark,
    });
    MessagePlugin.success(response.message || '服务已完成退款');
    serviceRefundVisible.value = false;
    await Promise.all([reloadServiceDrawer(), loadServices(), loadDetail()]);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '退款失败'));
  } finally {
    serviceDrawer.actionLoading = '';
  }
}

async function openInvoiceDrawer(row: Row) {
  if (!row?.id) return;
  invoiceDrawer.currentId = Number(row.id);
  invoiceDrawer.detail = normalizeInvoiceDetail({ invoice: row }, row);
  invoiceDrawer.visible = true;
  await reloadInvoiceDrawer();
}

function closeInvoiceDrawer() {
  invoiceDrawer.visible = false;
  invoiceDrawer.loading = false;
  invoiceDrawer.cancelLoading = false;
  invoiceDrawer.currentId = 0;
  invoiceDrawer.detail = { invoice: {}, payments: [], items: [], logs: [] };
}

async function reloadInvoiceDrawer() {
  if (!invoiceDrawer.currentId) return;
  invoiceDrawer.loading = true;
  try {
    const detail = await userApi.invoiceDetail(userId.value, invoiceDrawer.currentId);
    invoiceDrawer.detail = normalizeInvoiceDetail(detail, currentInvoice.value);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载账单详情失败'));
  } finally {
    invoiceDrawer.loading = false;
  }
}

function isCancelableInvoice(row: Row) {
  const orderId = Number(row?.order?.id || row?.order_id || 0);
  const orderStatus = Number(row?.order?.status ?? 0);
  const invoiceStatus = Number(row?.status ?? -1);
  return orderId > 0 && orderStatus === 0 && [0, 3].includes(invoiceStatus);
}

function handleCancelInvoice(row: Row) {
  if (!row?.id) return;
  const dialog = DialogPlugin.confirm({
    header: '取消账单',
    body: '取消账单后将关闭关联流程，确认继续吗？',
    confirmBtn: '确认取消',
    cancelBtn: '取消',
    theme: 'warning',
    async onConfirm() {
      try {
        await adminApi.invoices.cancel(row.id);
        MessagePlugin.success('账单已取消');
        dialog.hide();
        await Promise.all([loadInvoices(), loadDetail()]);
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '取消账单失败'));
      }
    },
  });
}

async function handleDrawerCancelInvoice() {
  if (!invoiceDrawer.currentId) return;
  invoiceDrawer.cancelLoading = true;
  try {
    await adminApi.invoices.cancel(invoiceDrawer.currentId);
    MessagePlugin.success('账单已取消');
    await Promise.all([loadInvoices(), loadDetail(), reloadInvoiceDrawer()]);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '取消账单失败'));
  } finally {
    invoiceDrawer.cancelLoading = false;
  }
}

function openInvoiceRefundDialog() {
  invoiceRefundForm.refund_method = 'balance';
  invoiceRefundForm.remark = '';
  invoiceRefundVisible.value = true;
  invoiceRefundFormRef.value?.clearValidate?.();
}

async function handleInvoiceRefund() {
  const result = await invoiceRefundFormRef.value?.validate?.();
  if (!isValidationPass(result) || !invoiceDrawer.currentId) return;
  invoiceRefundLoading.value = true;
  try {
    await userApi.refundInvoice(userId.value, invoiceDrawer.currentId, {
      refund_method: invoiceRefundForm.refund_method,
      amount: currentInvoice.value.paid_amount || currentInvoice.value.amount,
      remark: invoiceRefundForm.remark,
    });
    MessagePlugin.success('账单已完成退款');
    invoiceRefundVisible.value = false;
    await Promise.all([loadInvoices(), loadDetail(), reloadInvoiceDrawer()]);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '退款失败'));
  } finally {
    invoiceRefundLoading.value = false;
  }
}

function goBack() {
  router.push('/admin/users');
}

function paginationOf(state: PageState) {
  return {
    current: state.page,
    pageSize: state.pageSize,
    total: state.total,
    pageSizeOptions: [10, 20, 50],
    showJumper: true,
  };
}
function serviceName(row: Row) {
  return (
    row.product_full_path ||
    row.name ||
    row.product_display_name ||
    row.product?.display_name ||
    (row.product_id ? `未配置规格 #${row.product_id}` : '-')
  );
}
function goServiceDetail(row: Row) {
  const id = Number(row.id || 0);
  if (!id) return;
  router.push({ path: `/admin/services/${id}`, query: { user: String(userId.value || '') } });
}
function viewInvoiceLinkedService() {
  const service = (currentInvoice.value.service as Row | undefined) || {};
  const id = Number(service.id || 0);
  if (!id) return;
  router.push({ path: `/admin/services/${id}`, query: { user: String(userId.value || '') } });
}
function normalizeServiceDetail(payload: Row = {}) {
  const empty = {
    id: 0,
    name: '',
    domain: '',
    status: 0,
    status_tone: 'default',
    billing_cycle: '',
    billing_cycle_label: '',
    amount: 0,
    expires_at: '',
    created_at: '',
    product: {},
    order: {},
    invoice: {},
    upstream: {},
    runtime: {},
    connection: {},
    actions: { refresh: true, password_reset: false, manual_provision: false, available: [] as string[] },
    specs: [] as Row[],
    custom_service_name: '',
  };
  return {
    ...empty,
    ...payload,
    product: { ...empty.product, ...(payload.product || {}) },
    order: { ...empty.order, ...(payload.order || {}) },
    invoice: { ...empty.invoice, ...(payload.invoice || {}) },
    upstream: { ...empty.upstream, ...(payload.upstream || {}) },
    runtime: { ...empty.runtime, ...(payload.runtime || {}) },
    connection: { ...empty.connection, ...(payload.connection || {}) },
    actions: { ...empty.actions, ...(payload.actions || {}) },
    specs: Array.isArray(payload.specs) ? payload.specs : [],
  };
}
function mergeServiceDetail(current: Row = {}, patch: Row = {}) {
  return normalizeServiceDetail({
    ...current,
    ...patch,
    product: { ...(current.product || {}), ...(patch.product || {}) },
    order: { ...(current.order || {}), ...(patch.order || {}) },
    invoice: { ...(current.invoice || {}), ...(patch.invoice || {}) },
    upstream: { ...(current.upstream || {}), ...(patch.upstream || {}) },
    runtime: { ...(current.runtime || {}), ...(patch.runtime || {}) },
    connection: { ...(current.connection || {}), ...(patch.connection || {}) },
    actions: { ...(current.actions || {}), ...(patch.actions || {}) },
  });
}
function patchServiceListItem(detail: Row = {}) {
  const normalized = normalizeServiceDetail(detail);
  const index = services.list.findIndex((item) => Number(item.id) === Number(normalized.id));
  if (index < 0) return;
  services.list.splice(index, 1, {
    ...services.list[index],
    name: normalized.name,
    domain: normalized.domain,
    status: normalized.status,
    status_tone: normalized.status_tone,
    amount: normalized.amount,
    created_at: normalized.created_at,
    expires_at: normalized.expires_at,
    billing_cycle_label: normalized.billing_cycle_label,
    upstream: {
      ...(services.list[index].upstream || {}),
      ...(normalized.upstream || {}),
      dedicated_ip: normalized.connection?.dedicated_ip || normalized.upstream?.dedicated_ip || '',
    },
    product: {
      ...(services.list[index].product || {}),
      ...(normalized.product || {}),
    },
    custom_service_name: normalized.custom_service_name || '',
  });
}
function normalizeInvoiceDetail(payload: Row = {}, fallback: Row = {}) {
  const invoice = payload.invoice && typeof payload.invoice === 'object' ? payload.invoice : payload;
  return {
    invoice: {
      ...fallback,
      ...invoice,
      payment_summary: { ...(fallback.payment_summary || {}), ...(invoice.payment_summary || {}) },
      order: invoice.order || fallback.order || null,
      product: invoice.product || fallback.product || null,
      scene: invoice.scene || fallback.scene || {},
    },
    payments: Array.isArray(payload.payments) ? payload.payments : [],
    items: Array.isArray(payload.items) ? payload.items : [],
    logs: Array.isArray(payload.logs) ? payload.logs : [],
  };
}
function createLockedPricingForm(detail: Row = {}) {
  const cycles = Array.isArray(detail.renew_pricing_cycles) ? detail.renew_pricing_cycles : [];
  return cycles.reduce(
    (result, item) => {
      const cycle = String(item?.billing_cycle || '').trim();
      if (!cycle) return result;
      result[cycle] = {
        enabled: Boolean(item?.enabled),
        base_amount: item?.base_amount || null,
        manual_amount: item?.manual_amount || '',
      };
      return result;
    },
    {} as Record<
      string,
      { enabled: boolean; base_amount?: number | string | null; manual_amount?: number | null | '' }
    >,
  );
}
function productLabel(product: ProductRecord | null | undefined) {
  if (!product) return '';
  return String(
    product.display_name || product.name || product.product_name || (product.id ? `商品 #${product.id}` : ''),
  ).trim();
}
function resolveBillingOptions(product: ProductRecord | null) {
  const pricing =
    product && product.pricing && typeof product.pricing === 'object' && !Array.isArray(product.pricing)
      ? product.pricing
      : {};
  return Object.entries(pricing)
    .filter(([, amount]) => toNumber(amount) > 0)
    .map(([value, amount]) => ({
      value,
      label: `${billingCycleLabel(value)} · ${formatMoney(amount)}`,
      amount: toNumber(amount),
    }));
}
function billingCycleLabel(value: unknown) {
  return billingCycleLabelOf(value) || fieldValue(value);
}
function flattenOptionTree(items: Row[] = []) {
  const result: Array<{ value: string | number; label: string }> = [];
  const walk = (nodes: Row[]) => {
    nodes.forEach((node) => {
      const value = node.value ?? node.id ?? node.name;
      const label = String(node.label || node.name || node.value || '').trim();
      if (value !== undefined && value !== null && label) result.push({ value, label });
      if (Array.isArray(node.children)) walk(node.children);
    });
  };
  walk(items);
  return result;
}
function normalizeSelectionArray(value: unknown): string[] {
  return (Array.isArray(value) ? value : [value]).map((item) => String(item || '').trim()).filter(Boolean);
}
function validateUpstreamPair() {
  const supplierId = Number(serviceUpstreamForm.supplier_id || 0);
  const hostId = Number(serviceUpstreamForm.upstream_host_id || 0);
  return (supplierId <= 0 && hostId <= 0) || (supplierId > 0 && hostId > 0);
}
function isValidationPass(result: unknown) {
  return result === true || result === undefined;
}
function serviceTone(tone: unknown): 'default' | 'success' | 'primary' | 'warning' | 'danger' {
  const normalized = String(tone || '').toLowerCase();
  if (['success', 'primary', 'warning', 'danger'].includes(normalized))
    return normalized as 'success' | 'primary' | 'warning' | 'danger';
  return 'default';
}
function serviceStatusLabel(status: unknown) {
  return serviceStatusLabelMap[String(status ?? '')] || '-';
}
function serviceStatusTheme(status: unknown): 'default' | 'success' | 'primary' | 'warning' | 'danger' {
  const value = serviceStatusTypeMap[String(status ?? '')] || 'default';
  return value === 'info' || value === 'purple' ? 'default' : serviceTone(value);
}
function verificationText() {
  if (isVerified.value) return user.value.real_name ? `已实名认证 / ${user.value.real_name}` : '已实名认证';
  if (Number(user.value.verification_status) === 3) return '实名认证失败';
  if ([1, 4].includes(Number(user.value.verification_status))) return '待实名认证';
  return '未实名认证';
}
function invoiceStatusLabel(status: unknown) {
  return invoiceStatusLabelMap[String(status ?? '')] || '-';
}
function invoiceTypeLabel(type: unknown) {
  return (
    (
      {
        new: '新购',
        renew: '续费',
        recharge: '充值',
        deduction: '扣款',
        referral_credit: '推荐奖励',
        manual: '手工',
        upgrade: '升降级账单',
      } as Record<string, string>
    )[String(type)] || '-'
  );
}
function invoiceStatusTheme(status: unknown): 'default' | 'success' | 'warning' | 'danger' {
  const value = invoiceStatusTypeMap[String(status ?? '')] || 'default';
  if (value === 'success' || value === 'warning' || value === 'danger') return value;
  return 'default';
}
function balanceTypeLabel(type: unknown) {
  const labels: Record<string, string> = {
    recharge: '充值',
    consume: '消费',
    invoice_payment: '账单支付',
    refund: '退款',
    invoice_refund: '账单退款',
    adjust: '调整',
    admin_deduct: '管理员扣款',
    manual_deduction: '手动扣款',
    manual_recharge: '手动充值',
    referral_withdraw_approved: '奖励转余额',
    referral_credit_cash: '奖励转余额',
  };
  return labels[String(type)] || fieldValue(type);
}
function balanceTheme(type: unknown): 'default' | 'success' | 'warning' | 'danger' {
  const value = String(type || '').toLowerCase();
  if (
    [
      'recharge',
      'refund',
      'invoice_refund',
      'manual_recharge',
      'referral_credit_cash',
      'referral_withdraw_approved',
    ].includes(value)
  ) {
    return 'success';
  }
  if (['admin_deduct', 'deduct', 'manual_deduction', 'invoice_payment', 'consume'].includes(value)) return 'danger';
  if (['payment', 'pay'].includes(value)) return 'warning';
  return 'default';
}
function priorityLabel(priority: unknown) {
  return (
    (
      { 1: '低', 2: '中', 3: '高', 4: '紧急', low: '低', medium: '中', high: '高', urgent: '紧急' } as Record<
        string,
        string
      >
    )[String(priority)] || '-'
  );
}
function priorityTheme(priority: unknown): 'default' | 'warning' | 'danger' {
  const value = String(priority).toLowerCase();
  if (['3', 'high', '4', 'urgent'].includes(value)) return 'danger';
  if (['2', 'medium'].includes(value)) return 'warning';
  return 'default';
}
function ticketStatusLabel(status: unknown) {
  // 文案以后端 TicketService::STATUS_LABELS 为准（shared/statusConfig.js 的 TICKET_STATUS_MAP 同口径）。
  // 此前这里写作"待处理/用户回复/客服回复"，与工单列表页的"开启/客户回复/员工回复"矛盾。
  return ({ 0: '开启', 1: '客户回复', 2: '员工回复', 3: '已关闭' } as Record<number, string>)[Number(status)] || '-';
}
function ticketStatusTheme(status: unknown): 'default' | 'success' | 'warning' {
  const value = Number(status);
  if (value === 3) return 'success';
  if ([0, 1, 2].includes(value)) return 'warning';
  return 'default';
}
function noticeLabel(status: unknown) {
  return (
    ({ success: '成功', failed: '失败', pending: '待发送' } as Record<string, string>)[String(status)] ||
    fieldValue(status)
  );
}
function noticeTheme(status: unknown): 'default' | 'success' | 'warning' | 'danger' {
  if (status === 'success') return 'success';
  if (status === 'failed') return 'danger';
  if (status === 'pending') return 'warning';
  return 'default';
}
function amountClass(value: unknown) {
  const number = toNumber(value);
  if (number > 0) return 'amount-up';
  if (number < 0) return 'amount-down';
  return '';
}
function signedMoney(value: unknown) {
  const number = toNumber(value);
  return `${number > 0 ? '+' : ''}${formatMoney(number)}`;
}
function toNumber(value: unknown) {
  const number = Number.parseFloat(String(value ?? 0));
  return Number.isFinite(number) ? number : 0;
}

onMounted(() => {
  // setup 期间已初始化 Tab 与 URL 一致
  // 若直接进入带 ?tab= 的 URL，确保懒加载对应数据
  if (activeTab.value !== 'basic') {
    handleTabChange(activeTab.value);
  }
  loadDetail();
});

// 监听 URL tab 变化（同路由复用时触发）
watch(
  () => route.query.tab,
  (val) => {
    const q = Array.isArray(val) ? val[0] : val;
    if (q && VALID_DETAIL_TABS.includes(q as TabName) && activeTab.value !== q) {
      handleTabChange(q);
    }
  },
);
</script>
