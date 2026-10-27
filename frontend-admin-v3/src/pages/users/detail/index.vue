<template>
  <div class="user-detail-page">
    <t-loading :loading="detailLoading" size="small">
      <div class="detail-action-bar">
        <t-button variant="text" theme="default" @click="goBack">
          <template #icon><chevron-left-icon /></template>
          {{ $t('users.detail.backToList') }}
        </t-button>
        <div class="user-detail-actions">
          <t-button
            v-if="canLoginAs"
            theme="primary"
            :disabled="!user.id"
            :loading="loginAsLoading"
            @click="handleLoginAs"
            >{{ $t('users.detail.loginAs') }}</t-button
          >
          <t-button theme="default" :disabled="!user.id" @click="openEditDialog">{{
            $t('users.detail.editProfile')
          }}</t-button>
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
        <t-tab-panel value="basic" :label="$t('users.detail.tabBasic')" />
        <t-tab-panel value="referral" :label="$t('users.detail.tabReferral')" />
        <t-tab-panel value="services" :label="$t('users.detail.tabServices')" />
        <t-tab-panel value="invoices" :label="$t('users.detail.tabInvoices')" />
        <t-tab-panel value="balance" :label="$t('users.detail.tabBalance')" />
        <t-tab-panel value="tickets" :label="$t('users.detail.tabTickets')" />
        <t-tab-panel value="logs" :label="$t('users.detail.tabLogs')" />
        <t-tab-panel value="notices" :label="$t('users.detail.tabNotices')" />
      </t-tabs>

      <section v-if="activeTab === 'basic'" class="user-detail-section">
        <div class="basic-grid">
          <t-card :header="$t('users.detail.basicInfo')" :bordered="true">
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
                <span>{{ $t('users.detail.adminRemark') }}</span>
                <t-button v-if="!noteEditing" variant="text" theme="primary" size="small" @click="startEditNote">{{
                  $t('users.detail.actionEdit')
                }}</t-button>
              </div>
            </template>
            <div v-if="!noteEditing" class="user-note" :class="{ 'is-empty': !user.admin_note }">
              {{ user.admin_note || $t('users.detail.noRemark') }}
            </div>
            <div v-else class="note-edit">
              <t-textarea
                v-model="noteForm"
                :placeholder="$t('users.detail.notePlaceholder')"
                :maxlength="500"
                :autosize="{ minRows: 3, maxRows: 6 }"
              />
              <div class="note-edit-footer">
                <span class="note-char-count" :class="{ 'is-over': noteForm.length >= 500 }"
                  >{{ noteForm.length }} / 500</span
                >
                <div class="note-edit-actions">
                  <t-button size="small" theme="default" @click="noteEditing = false">{{
                    $t('common.cancel')
                  }}</t-button>
                  <t-button
                    size="small"
                    theme="primary"
                    :loading="noteSaving"
                    :disabled="noteForm.length > 500"
                    @click="saveNote"
                    >{{ $t('users.detail.save') }}</t-button
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
            :placeholder="$t('users.detail.searchServicesPlaceholder')"
            @enter="searchServices"
            @clear="searchServices"
          >
            <template #suffix-icon><search-icon /></template>
          </t-input>
          <t-select
            v-model="services.filters.status"
            clearable
            :placeholder="$t('users.detail.statusPlaceholder')"
            @change="searchServices"
          >
            <t-option
              v-for="option in serviceStatusOptions"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </t-select>
          <t-button theme="primary" @click="openAddServiceDialog">{{ $t('users.detail.addInstance') }}</t-button>
          <t-button theme="default" :loading="services.refreshingStatus" @click="handleRefreshServicesStatus">{{
            $t('users.detail.refreshAllStatus')
          }}</t-button>
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
            <template #id="{ row }">
              <button
                type="button"
                class="user-link"
                :disabled="!canGoService(row.id, userId)"
                @click="goServiceDetail(row)"
              >
                <strong>{{ fieldValue(row.id) }}</strong>
              </button>
            </template>
            <template #serviceName="{ row }">
              <button
                type="button"
                class="user-link"
                :disabled="!canGoService(row.id, userId)"
                @click="goServiceDetail(row)"
              >
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
                <t-button variant="text" theme="primary" size="small" @click="goServiceDetail(row)">{{
                  $t('users.detail.manageService')
                }}</t-button>
                <t-button
                  variant="text"
                  theme="default"
                  size="small"
                  :loading="services.refreshing"
                  @click="handleRefreshService(row)"
                  >{{ $t('users.detail.actionRefresh') }}</t-button
                >
                <t-button variant="text" theme="danger" size="small" @click="handleDeleteServiceRow(row)">{{
                  $t('users.detail.actionDelete')
                }}</t-button>
              </div>
            </template>
          </t-table>
        </div>
      </section>

      <section v-else-if="activeTab === 'invoices'" class="user-detail-section">
        <div class="detail-toolbar compact">
          <t-select
            v-model="invoices.filters.status"
            clearable
            :placeholder="$t('users.detail.statusPlaceholder')"
            @change="searchInvoices"
          >
            <t-option
              v-for="option in invoiceStatusOptions"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </t-select>
          <t-select
            v-model="invoices.filters.type"
            clearable
            :placeholder="$t('users.detail.typePlaceholder')"
            @change="searchInvoices"
          >
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
            <template #invoice_no="{ row }">
              <button type="button" class="user-link" :disabled="!isEntityId(row.id)" @click="openInvoiceDrawer(row)">
                <strong>{{ fieldValue(row.invoice_no) }}</strong>
              </button>
            </template>
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
                <t-button variant="text" theme="primary" size="small" @click="openInvoiceDrawer(row)">{{
                  $t('users.detail.invoiceDetails')
                }}</t-button>
                <t-button
                  v-if="isCancelableInvoice(row)"
                  variant="text"
                  theme="danger"
                  size="small"
                  @click="handleCancelInvoice(row)"
                  >{{ $t('common.cancel') }}</t-button
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
            <template #id="{ row }">
              <button type="button" class="user-link" :disabled="!isEntityId(row.id)" @click="goTicket(router, row.id)">
                <strong>{{ fieldValue(row.id) }}</strong>
              </button>
            </template>
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
          <t-date-picker
            v-model="logs.filters.start_date"
            clearable
            :placeholder="$t('users.detail.startDate')"
            @change="searchLogs"
          />
          <t-date-picker
            v-model="logs.filters.end_date"
            clearable
            :placeholder="$t('users.detail.endDate')"
            @change="searchLogs"
          />
          <t-input
            v-model="logs.filters.keyword"
            clearable
            :placeholder="$t('users.detail.descriptionPlaceholder')"
            @enter="searchLogs"
            @clear="searchLogs"
          />
          <t-input
            v-model="logs.filters.ip_address"
            clearable
            :placeholder="$t('users.detail.ipAddressPlaceholder')"
            @enter="searchLogs"
            @clear="searchLogs"
          />
          <t-select
            v-model="logs.filters.source"
            clearable
            :placeholder="$t('users.detail.sourcePlaceholder')"
            @change="searchLogs"
          >
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
            <t-radio-button value="email">{{ $t('users.detail.emailChannel') }}</t-radio-button>
            <t-radio-button value="sms">{{ $t('users.detail.smsChannel') }}</t-radio-button>
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
      :header="$t('users.detail.editProfile')"
      width="560px"
      :confirm-btn="{ content: $t('users.detail.saveChanges'), loading: saveLoading }"
      @cancel="editVisible = false"
      @confirm="handleSave"
    >
      <t-form ref="editFormRef" :data="editForm" :rules="editRules" label-align="top">
        <t-form-item :label="$t('users.detail.email')">
          <t-input :value="user.email || '-'" disabled />
        </t-form-item>
        <t-form-item :label="$t('users.detail.nicknameLabel')" name="nickname">
          <t-input v-model="editForm.nickname" />
        </t-form-item>
        <t-form-item :label="$t('users.detail.phone')" name="phone">
          <t-input v-model="editForm.phone" />
        </t-form-item>
        <t-form-item :label="$t('users.detail.newPassword')" name="password">
          <t-input v-model="editForm.password" type="password" :placeholder="$t('users.detail.leaveBlankToKeep')" />
        </t-form-item>
        <t-form-item :label="$t('users.detail.statusLabel')" name="status">
          <t-switch v-model="editForm.status" :custom-value="[1, 0]" />
        </t-form-item>
        <t-form-item v-if="canManageUsers" :label="$t('users.detail.agentGroup')" name="agent_group_id">
          <t-select
            v-model="editForm.agent_group_id"
            filterable
            :loading="agentGroupOptionsLoading"
            :placeholder="$t('users.detail.selectAgentGroup')"
          >
            <t-option :label="$t('users.detail.noAgentGroup')" :value="0" />
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
      :header="$t('users.detail.fundManagement')"
      width="480px"
      :confirm-btn="{
        content:
          rechargeForm.type === 'decrease' ? $t('users.detail.confirmDecrease') : $t('users.detail.confirmIncrease'),
        loading: rechargeLoading,
      }"
      @cancel="rechargeVisible = false"
      @confirm="handleRecharge"
    >
      <t-form ref="rechargeFormRef" :data="rechargeForm" :rules="rechargeRules" label-align="top">
        <t-form-item :label="$t('users.detail.userLabel')">
          <t-input :value="rechargeForm.email" disabled />
        </t-form-item>
        <t-form-item :label="$t('users.detail.operationType')" name="type">
          <t-radio-group v-model="rechargeForm.type">
            <t-radio value="increase">{{ $t('users.detail.increaseBalance') }}</t-radio>
            <t-radio value="decrease">{{ $t('users.detail.decreaseBalance') }}</t-radio>
          </t-radio-group>
        </t-form-item>
        <t-form-item :label="$t('users.detail.amountLabel')" name="amount">
          <t-input-number
            v-model="rechargeForm.amount"
            :min="0.01"
            :max="999999"
            :decimal-places="2"
            style="width: 100%"
          />
        </t-form-item>
        <t-form-item :label="$t('users.detail.remarkLabel')" name="remark">
          <t-input v-model="rechargeForm.remark" :placeholder="$t('users.detail.operationReasonPlaceholder')" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="addServiceVisible"
      :header="$t('users.detail.addInstanceTitle')"
      width="760px"
      :confirm-btn="{ content: $t('users.detail.confirmCreate'), loading: addServiceSubmitting }"
      @cancel="addServiceVisible = false"
      @confirm="handleSubmitAddService"
    >
      <t-alert theme="info" :message="$t('users.detail.addInstanceHint')" />
      <t-form
        ref="addServiceFormRef"
        :data="addServiceForm"
        :rules="addServiceRules"
        label-align="top"
        class="dialog-form"
      >
        <div class="dialog-grid">
          <t-form-item :label="$t('users.detail.selectProduct')" name="product_id" class="form-item-full">
            <product-binding-tree-select
              v-model="addServiceProductIdArray"
              mode="single"
              @change="handleAddServiceProductChange"
            />
          </t-form-item>
          <t-form-item :label="$t('users.detail.billingCycle')" name="billing_cycle">
            <t-select
              v-model="addServiceForm.billing_cycle"
              :placeholder="$t('users.detail.selectBillingCycle')"
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
          <t-form-item :label="$t('users.detail.systemType')">
            <t-select
              v-model="addServiceForm.os"
              clearable
              filterable
              :loading="addServiceOsLoading"
              :placeholder="$t('users.detail.selectSystem')"
            >
              <t-option
                v-for="item in addServiceOsFlatOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </t-select>
          </t-form-item>
          <t-form-item :label="$t('users.detail.serviceStatusLabel')" name="status">
            <t-select v-model="addServiceForm.status" :placeholder="$t('users.detail.selectStatus')">
              <t-option
                v-for="item in serviceStatusOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </t-select>
          </t-form-item>
          <t-form-item :label="$t('users.detail.serviceAmount')" name="amount">
            <t-input-number v-model="addServiceForm.amount" :min="0" :decimal-places="2" style="width: 100%" />
          </t-form-item>
          <t-form-item :label="$t('users.detail.serviceNameLabel')">
            <t-input v-model="addServiceForm.name" :placeholder="$t('users.detail.defaultNameHint')" />
          </t-form-item>
          <t-form-item class="form-item-full">
            <div class="add-service-automation-switches">
              <div class="add-service-automation-switch">
                <span>{{ $t('users.detail.autoRenew') }}</span>
                <t-switch v-model="addServiceForm.auto_renew" :custom-value="[1, 0]" />
              </div>
              <div class="add-service-automation-switch">
                <span>{{ $t('users.detail.autoCreateOrder') }}</span>
                <t-switch v-model="addServiceForm.create_order" :custom-value="[1, 0]" />
              </div>
              <div class="add-service-automation-switch">
                <span>{{ $t('users.detail.autoCreateInvoice') }}</span>
                <t-switch
                  v-model="addServiceForm.create_invoice"
                  :custom-value="[1, 0]"
                  @change="handleAddServiceCreateInvoiceChange"
                />
              </div>
              <div class="add-service-automation-switch">
                <span>{{ $t('users.detail.deductFromBalance') }}</span>
                <t-switch
                  v-model="addServiceForm.deduct_balance"
                  :custom-value="[1, 0]"
                  :disabled="!addServiceForm.create_invoice"
                />
              </div>
            </div>
          </t-form-item>
        </div>
        <t-form-item :label="$t('users.detail.remarkLabel')">
          <t-textarea
            v-model="addServiceForm.remark"
            :maxlength="200"
            :placeholder="$t('users.detail.remarkPlaceholder')"
          />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-drawer
      v-model:visible="serviceDrawer.visible"
      size="620px"
      :header="$t('users.detail.serviceConsole')"
      @close="closeServiceDrawer"
    >
      <t-loading :loading="serviceDrawer.loading" size="small">
        <t-descriptions :column="1" bordered>
          <t-descriptions-item :label="$t('users.detail.serviceNameLabel')">{{
            fieldValue(serviceDrawer.detail.name || serviceDrawer.detail.domain)
          }}</t-descriptions-item>
          <t-descriptions-item :label="$t('users.detail.statusLabel')">{{
            serviceStatusLabel(serviceDrawer.detail.status)
          }}</t-descriptions-item>
          <t-descriptions-item :label="$t('users.detail.billingCycle')">{{
            fieldValue(serviceDrawer.detail.billing_cycle_label)
          }}</t-descriptions-item>
          <t-descriptions-item :label="$t('users.detail.amountShort')">{{
            formatMoney(serviceDrawer.detail.amount)
          }}</t-descriptions-item>
          <t-descriptions-item :label="$t('users.detail.invoiceNoLabel')">{{
            fieldValue(serviceDrawer.detail.invoice?.invoice_no || serviceDrawer.detail.order?.invoice_no)
          }}</t-descriptions-item>
          <t-descriptions-item :label="$t('users.detail.upstreamLabel')"
            >{{ fieldValue(serviceDrawer.detail.upstream?.provider_key)
            }}<template v-if="serviceDrawer.detail.upstream?.host_id">
              / host #{{ serviceDrawer.detail.upstream.host_id }}</template
            ></t-descriptions-item
          >
          <t-descriptions-item :label="$t('users.detail.publicIp')">{{
            fieldValue(serviceDrawer.detail.connection?.dedicated_ip || serviceDrawer.detail.upstream?.dedicated_ip)
          }}</t-descriptions-item>
          <t-descriptions-item :label="$t('users.detail.loginAccount')">{{
            fieldValue(serviceDrawer.detail.connection?.username)
          }}</t-descriptions-item>
          <t-descriptions-item :label="$t('users.detail.loginPort')">{{
            fieldValue(serviceDrawer.detail.connection?.port)
          }}</t-descriptions-item>
          <t-descriptions-item :label="$t('users.detail.runningStatus')">
            <template v-if="serviceDrawer.detail.runtime?.power_label || serviceDrawer.detail.runtime?.description">
              {{ fieldValue(serviceDrawer.detail.runtime?.power_label || serviceDrawer.detail.runtime?.description) }}
            </template>
            <t-tooltip v-else :content="$t('users.detail.upstreamPowerUnavailable')">
              <t-tag theme="warning" variant="light">{{ $t('users.detail.runningStatusUnavailable') }}</t-tag>
            </t-tooltip>
          </t-descriptions-item>
          <t-descriptions-item :label="$t('users.detail.expiresAt')">{{
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
            >{{ $t('users.detail.powerOn') }}</t-button
          >
          <t-button
            v-if="canPowerOff"
            theme="danger"
            variant="outline"
            size="small"
            :loading="serviceDrawer.actionLoading === 'power:off'"
            @click="handleServicePower('off')"
            >{{ $t('users.detail.powerOff') }}</t-button
          >
          <t-button
            v-if="canReboot"
            theme="warning"
            variant="outline"
            size="small"
            :loading="serviceDrawer.actionLoading === 'power:reboot'"
            @click="handleServicePower('reboot')"
            >{{ $t('users.detail.reboot') }}</t-button
          >
          <t-button
            theme="default"
            size="small"
            :loading="serviceDrawer.actionLoading === 'remote-status'"
            @click="handleRefreshConsoleRemoteStatus"
            >{{ $t('users.detail.refreshRemote') }}</t-button
          >
          <t-button
            theme="default"
            size="small"
            :disabled="!serviceActions.password_reset"
            :loading="serviceDrawer.actionLoading === 'reset-password'"
            @click="openResetPasswordDialog"
            >{{ $t('users.detail.resetPassword') }}</t-button
          >
          <t-button theme="default" size="small" @click="openServiceUpstreamDialog">{{
            $t('users.detail.upstreamBinding')
          }}</t-button>
          <t-button theme="default" size="small" @click="openServicePricingDialog">{{
            $t('users.detail.adjustPrice')
          }}</t-button>
          <t-button theme="default" size="small" @click="openServiceNameDialog">{{
            $t('users.detail.rename')
          }}</t-button>
          <t-button
            v-if="serviceActions.upstream_provision"
            theme="primary"
            size="small"
            :loading="serviceDrawer.actionLoading === 'upstream-provision'"
            @click="openUpstreamProvisionDialog"
            >{{ $t('users.detail.upstreamProvision') }}</t-button
          >
          <t-button
            v-if="canRefundService"
            theme="danger"
            size="small"
            :loading="serviceDrawer.actionLoading === 'refund'"
            @click="openServiceRefundDialog"
            >{{ $t('users.detail.refund') }}</t-button
          >
          <t-tag v-else-if="isServiceRefunded" theme="danger" variant="light">{{
            $t('users.detail.serviceRefunded')
          }}</t-tag>
        </div>
        <div class="drawer-close-actions">
          <t-button variant="outline" @click="closeServiceDrawer">
            <template #icon><chevron-left-icon /></template>
            {{ $t('users.detail.back') }}
          </t-button>
        </div>
      </t-loading>
    </t-drawer>

    <t-dialog
      v-model:visible="resetPasswordVisible"
      :header="$t('users.detail.resetLoginPassword')"
      width="420px"
      :confirm-btn="{
        content: $t('users.detail.confirmReset'),
        loading: serviceDrawer.actionLoading === 'reset-password',
      }"
      @cancel="resetPasswordVisible = false"
      @confirm="handleResetServicePassword"
    >
      <t-form ref="resetPasswordFormRef" :data="resetPasswordForm" :rules="resetPasswordRules" label-align="top">
        <t-form-item :label="$t('users.detail.newPassword')" name="password">
          <t-input v-model="resetPasswordForm.password" type="password" :placeholder="$t('users.detail.atLeast8')" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="upstreamProvisionVisible"
      :header="$t('users.detail.upstreamProvision')"
      width="480px"
      :confirm-btn="{
        content: $t('users.detail.upstreamProvisionConfirm'),
        theme: 'primary',
        loading: serviceDrawer.actionLoading === 'upstream-provision',
      }"
      @cancel="upstreamProvisionVisible = false"
      @confirm="handleUpstreamProvision"
    >
      <div class="upstream-provision">
        <p class="upstream-provision__hint">{{ $t('users.detail.upstreamProvisionHint') }}</p>
        <t-checkbox v-model="upstreamProvisionForm.force">{{
          $t('users.detail.upstreamProvisionForce')
        }}</t-checkbox>
        <t-input
          v-model="upstreamProvisionForm.remark"
          :placeholder="$t('users.detail.upstreamProvisionRemark')"
          :maxlength="200"
        />
      </div>
    </t-dialog>

    <t-dialog
      v-model:visible="serviceUpstreamVisible"
      :header="$t('users.detail.upstreamBinding')"
      width="520px"
      :confirm-btn="{ content: $t('users.detail.save'), loading: serviceUpstreamSubmitting }"
      @cancel="serviceUpstreamVisible = false"
      @confirm="submitServiceUpstream"
    >
      <t-form ref="serviceUpstreamFormRef" :data="serviceUpstreamForm" :rules="serviceUpstreamRules" label-align="top">
        <t-form-item :label="$t('users.detail.upstreamInterface')" name="supplier_id">
          <t-select
            v-model="serviceUpstreamForm.supplier_id"
            clearable
            filterable
            :loading="serviceUpstreamLoading"
            :placeholder="$t('users.detail.selectUpstreamInterface')"
          >
            <t-option v-for="item in serviceUpstreamOptions" :key="item.id" :label="item.label" :value="item.id" />
          </t-select>
        </t-form-item>
        <t-form-item :label="$t('users.detail.upstreamInstanceId')" name="upstream_host_id">
          <t-input-number v-model="serviceUpstreamForm.upstream_host_id" :min="1" style="width: 100%" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="servicePricingVisible"
      :header="$t('users.detail.adjustPriceTitle')"
      width="620px"
      :confirm-btn="{ content: $t('users.detail.save'), loading: servicePricingSubmitting }"
      @cancel="servicePricingVisible = false"
      @confirm="submitServicePricing"
    >
      <t-form ref="servicePricingFormRef" :data="servicePricingForm" :rules="servicePricingRules" label-align="top">
        <t-form-item :label="$t('users.detail.purchasePrice')" name="amount">
          <t-input-number v-model="servicePricingForm.amount" :min="0" :decimal-places="2" style="width: 100%" />
        </t-form-item>
        <div v-if="servicePricingEntries.length" class="pricing-list">
          <div v-for="item in servicePricingEntries" :key="item.cycle" class="pricing-row">
            <div>
              <strong>{{ item.label }}</strong>
              <span
                >{{ $t('users.detail.basePrice') }}
                {{ item.base_amount ? formatMoney(item.base_amount) : $t('users.detail.notConfigured') }}</span
              >
            </div>
            <t-switch v-model="servicePricingForm.locked_pricing[item.cycle].enabled" />
            <t-input-number
              v-model="servicePricingForm.locked_pricing[item.cycle].manual_amount"
              :min="0"
              :decimal-places="2"
              :placeholder="$t('users.detail.manualPrice')"
            />
          </div>
          <t-checkbox v-model="servicePricingForm.clear_locked_pricing">{{
            $t('users.detail.restoreRenewPrice')
          }}</t-checkbox>
        </div>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="serviceNameVisible"
      :header="$t('users.detail.renameTitle')"
      width="420px"
      :confirm-btn="{ content: $t('users.detail.save'), loading: serviceNameSubmitting }"
      @cancel="serviceNameVisible = false"
      @confirm="submitServiceName"
    >
      <t-form :data="serviceNameForm" label-align="top">
        <t-form-item :label="$t('users.detail.instanceName')">
          <t-input
            v-model="serviceNameForm.service_name"
            :maxlength="120"
            :placeholder="$t('users.detail.instanceNamePlaceholder')"
          />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="serviceRefundVisible"
      :header="$t('users.detail.serviceRefund')"
      width="500px"
      :confirm-btn="{
        content: $t('users.detail.confirmRefund'),
        theme: 'danger',
        loading: serviceDrawer.actionLoading === 'refund',
      }"
      @cancel="serviceRefundVisible = false"
      @confirm="handleServiceRefund"
    >
      <t-alert theme="warning" :message="$t('users.detail.refundHint')" />
      <t-form
        ref="serviceRefundFormRef"
        :data="serviceRefundForm"
        :rules="refundRules"
        label-align="top"
        class="dialog-form"
      >
        <t-form-item :label="$t('users.detail.refundAmount')">
          <t-input :value="formatMoney(serviceRefundAmount)" disabled />
        </t-form-item>
        <t-form-item :label="$t('users.detail.refundMethod')" name="refund_method">
          <t-radio-group v-model="serviceRefundForm.refund_method">
            <t-radio value="balance">{{ $t('users.detail.refundToBalance') }}</t-radio>
            <t-radio value="original" :disabled="!canOriginalServiceRefund">{{
              $t('users.detail.refundOriginal')
            }}</t-radio>
          </t-radio-group>
        </t-form-item>
        <t-form-item :label="$t('users.detail.refundReason')" name="remark">
          <t-textarea
            v-model="serviceRefundForm.remark"
            :maxlength="200"
            :placeholder="$t('users.detail.refundReasonPlaceholder')"
          />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-drawer
      v-model:visible="invoiceDrawer.visible"
      size="720px"
      :header="$t('users.detail.invoiceDetail')"
      @close="closeInvoiceDrawer"
    >
      <t-loading :loading="invoiceDrawer.loading" size="small">
        <div class="invoice-detail-panel">
          <section class="invoice-summary">
            <div class="invoice-summary__main">
              <span>{{ $t('users.detail.invoiceNoLabel') }}</span>
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
              <span>{{ $t('users.detail.statusLabel') }}</span>
              <t-tag :theme="invoiceStatusTheme(currentInvoice.status)" variant="light">{{
                invoiceStatusLabel(currentInvoice.status)
              }}</t-tag>
            </div>
            <div>
              <span>{{ $t('users.detail.amountShort') }}</span>
              <strong>{{ formatMoney(currentInvoice.amount) }}</strong>
            </div>
            <div>
              <span>{{ $t('users.detail.paymentMethodLabel') }}</span>
              <strong>{{ fieldValue(currentInvoice.payment_summary?.gateway) }}</strong>
            </div>
          </section>

          <div class="drawer-actions">
            <t-button
              v-if="Number(currentInvoice.service?.id || 0)"
              theme="primary"
              variant="outline"
              @click="viewInvoiceLinkedService"
              >{{ $t('users.detail.viewService') }}</t-button
            >
            <t-button theme="default" :loading="invoiceDrawer.loading" @click="reloadInvoiceDrawer">{{
              $t('users.detail.refresh')
            }}</t-button>
            <t-button
              v-if="isCancelableInvoice(currentInvoice)"
              theme="danger"
              variant="outline"
              :loading="invoiceDrawer.cancelLoading"
              @click="handleDrawerCancelInvoice"
              >{{ $t('users.detail.cancelInvoice') }}</t-button
            >
            <t-button
              v-if="canRefundInvoice"
              theme="danger"
              :loading="invoiceRefundLoading"
              @click="openInvoiceRefundDialog"
              >{{ $t('users.detail.refund') }}</t-button
            >
          </div>

          <section class="invoice-detail-section">
            <h4>{{ $t('users.detail.basicInfo') }}</h4>
            <div class="invoice-detail-grid">
              <div>
                <span>{{ $t('users.detail.invoiceTypeLabel') }}</span>
                <strong>{{ currentInvoice.type_label || invoiceTypeLabel(currentInvoice.type) }}</strong>
              </div>
              <div>
                <span>{{ $t('users.detail.orderNo') }}</span>
                <strong>{{ fieldValue(currentInvoice.order?.order_no || currentInvoice.order_no) }}</strong>
              </div>
              <div>
                <span>{{ $t('users.detail.dueDate') }}</span>
                <strong>{{ fieldValue(currentInvoice.due_date) }}</strong>
              </div>
              <div>
                <span>{{ $t('users.detail.createdAt') }}</span>
                <strong>{{ formatDateTime(currentInvoice.created_at) }}</strong>
              </div>
              <div>
                <span>{{ $t('users.detail.paidAt') }}</span>
                <strong>{{ formatDateTime(currentInvoice.paid_at) }}</strong>
              </div>
            </div>
          </section>

          <section v-if="invoiceSceneItems.length" class="invoice-detail-section">
            <h4>{{ $t('users.detail.invoiceItems') }}</h4>
            <div class="line-list">
              <div v-for="item in invoiceSceneItems" :key="item.id || item.description" class="line-item">
                <span>{{ fieldValue(item.description) }}</span>
                <strong>{{ formatMoney(item.amount) }}</strong>
              </div>
            </div>
          </section>

          <section v-if="invoicePayments.length" class="invoice-detail-section">
            <h4>{{ $t('users.detail.paymentRecords') }}</h4>
            <div class="line-list">
              <div v-for="payment in invoicePayments" :key="payment.id || payment.payment_no" class="line-item stacked">
                <strong>{{ fieldValue(payment.payment_no) }}</strong>
                <span>{{ fieldValue(payment.gateway) }} / {{ formatMoney(payment.amount) }}</span>
                <span>{{ formatDateTime(payment.paid_at || payment.created_at) }}</span>
              </div>
            </div>
          </section>

          <section v-if="invoiceLogs.length" class="invoice-detail-section">
            <h4>{{ $t('users.detail.operationLogs') }}</h4>
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
              {{ $t('users.detail.back') }}
            </t-button>
          </div>
        </div>
      </t-loading>
    </t-drawer>

    <t-dialog
      v-model:visible="invoiceRefundVisible"
      :header="$t('users.detail.invoiceRefund')"
      width="500px"
      :confirm-btn="{ content: $t('users.detail.confirmRefund'), theme: 'danger', loading: invoiceRefundLoading }"
      @cancel="invoiceRefundVisible = false"
      @confirm="handleInvoiceRefund"
    >
      <t-form ref="invoiceRefundFormRef" :data="invoiceRefundForm" :rules="refundRules" label-align="top">
        <t-form-item :label="$t('users.detail.refundAmount')">
          <t-input :value="formatMoney(currentInvoice.paid_amount || currentInvoice.amount)" disabled />
        </t-form-item>
        <t-form-item :label="$t('users.detail.refundMethod')" name="refund_method">
          <t-radio-group v-model="invoiceRefundForm.refund_method">
            <t-radio value="balance">{{ $t('users.detail.refundToBalance') }}</t-radio>
            <t-radio value="original" :disabled="!canOriginalInvoiceRefund">{{
              $t('users.detail.refundOriginal')
            }}</t-radio>
          </t-radio-group>
        </t-form-item>
        <t-form-item :label="$t('users.detail.refundReason')" name="remark">
          <t-textarea
            v-model="invoiceRefundForm.remark"
            :maxlength="200"
            :placeholder="$t('users.detail.refundReasonPlaceholder')"
          />
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
import type { AdminUser, PageParams, UserUpdatePayload } from '@/api/user';
import { userApi } from '@/api/user';
import ProductBindingTreeSelect from '@/components/product-binding-tree-select/index.vue';
import { AdminPermissions } from '@/constants/permissions';
import { t } from '@/locales';
import { fieldValue, formatDateTime, formatMoney } from '@/utils/format';
import { required } from '@/utils/formRules';
import { buildIdempotencyKey } from '@/utils/idempotency';
import { hasAdminPermission } from '@/utils/permission';
import { canGoService, goService, goTicket, isEntityId } from '@/utils/route/adminEntityLink';
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
const upstreamProvisionVisible = ref(false);
const serviceRefundVisible = ref(false);
const invoiceRefundVisible = ref(false);
const invoiceRefundLoading = ref(false);
const editFormRef = ref<FormInstanceFunctions>();
const rechargeFormRef = ref<FormInstanceFunctions>();
const addServiceFormRef = ref<FormInstanceFunctions>();
const serviceUpstreamFormRef = ref<FormInstanceFunctions>();
const servicePricingFormRef = ref<FormInstanceFunctions>();
const resetPasswordFormRef = ref<FormInstanceFunctions>();
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
/** 本次调账的幂等标识，拦截按钮连点与网络重试导致的重复加/扣余额 */
const rechargeIdempotencyKey = ref(buildIdempotencyKey('admin-balance-adjust'));
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
const upstreamProvisionForm = reactive({ force: false, remark: '' });
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
      message: t('users.detail.passwordMinLength'),
      type: 'error',
    },
  ],
};
const rechargeRules: Record<string, FormRule[]> = {
  amount: [required(t('users.detail.amountRequired'))],
  remark: [required(t('users.detail.remarkRequired'))],
};
const addServiceRules: Record<string, FormRule[]> = {
  product_id: [required(t('users.detail.productRequired'))],
  billing_cycle: [required(t('users.detail.billingCycleRequired'))],
  status: [required(t('users.detail.statusRequired'))],
  amount: [required(t('users.detail.serviceAmountRequired'))],
};
const serviceUpstreamRules: Record<string, FormRule[]> = {
  supplier_id: [{ validator: validateUpstreamPair, message: t('users.detail.upstreamIdRequired'), type: 'error' }],
  upstream_host_id: [
    { validator: validateUpstreamPair, message: t('users.detail.upstreamInterfaceRequired'), type: 'error' },
  ],
};
const servicePricingRules: Record<string, FormRule[]> = {
  amount: [required(t('users.detail.purchasePriceRequired'))],
};
const resetPasswordRules: Record<string, FormRule[]> = {
  password: [
    { validator: (val) => String(val || '').length >= 8, message: t('users.detail.passwordLengthMin'), type: 'error' },
  ],
};
const refundRules: Record<string, FormRule[]> = {
  refund_method: [required(t('users.detail.refundMethodRequired'))],
  remark: [required(t('users.detail.refundReasonRequired'))],
};

const serviceStatusLabelMap = toLabelMap(SERVICE_STATUS_MAP);
const serviceStatusTypeMap = toTagTypeMap(SERVICE_STATUS_MAP);
const invoiceStatusLabelMap = toLabelMap(INVOICE_STATUS_MAP);
const invoiceStatusTypeMap = toTagTypeMap(INVOICE_STATUS_MAP);
const serviceStatusOptions = toSelectOptions(SERVICE_STATUS_MAP, false);
const invoiceStatusOptions = toSelectOptions(INVOICE_STATUS_MAP, false);
const invoiceTypeOptions = [
  { label: t('users.detail.changeTypeNew'), value: 'new' },
  { label: t('users.detail.changeTypeRenew'), value: 'renew' },
  { label: t('users.detail.changeTypeRecharge'), value: 'recharge' },
  { label: t('users.detail.changeTypeDeduction'), value: 'deduction' },
  { label: t('users.detail.changeTypeReferralReward'), value: 'referral_credit' },
  { label: t('users.detail.changeTypeManual'), value: 'manual' },
];

const recentReferralColumns: PrimaryTableCol<TableRowData>[] = [
  { title: t('users.detail.referralUser'), colKey: 'referralUser', width: 260 },
  { title: t('users.detail.referredAt'), colKey: 'referredAt', width: 180 },
];
const serviceColumns: PrimaryTableCol<TableRowData>[] = [
  { title: 'ID', colKey: 'id', width: 80 },
  { title: t('users.detail.serviceName'), colKey: 'serviceName', width: 240 },
  { title: t('users.detail.publicIp'), colKey: 'upstream.dedicated_ip', width: 140 },
  { title: t('users.detail.amount'), colKey: 'serviceAmount', width: 120 },
  { title: t('users.detail.productType'), colKey: 'product.type_label', width: 120 },
  { title: t('users.detail.billingCycle'), colKey: 'billing_cycle_label', width: 120 },
  { title: t('users.detail.purchasedAt'), colKey: 'serviceCreated', width: 180 },
  { title: t('users.detail.expiresAt'), colKey: 'serviceExpires', width: 180 },
  { title: t('common.status'), colKey: 'serviceStatus', width: 120 },
  { title: t('common.action'), colKey: 'serviceOperation', width: 180 },
];
const invoiceColumns: PrimaryTableCol<TableRowData>[] = [
  { title: t('users.detail.invoiceNo'), colKey: 'invoice_no', width: 180 },
  { title: t('users.detail.invoiceCreatedAt'), colKey: 'invoiceCreated', width: 180 },
  { title: t('users.detail.expiresAt'), colKey: 'due_date', width: 140 },
  { title: t('users.detail.invoicePaidAt'), colKey: 'invoicePaid', width: 180 },
  { title: t('users.detail.amount'), colKey: 'invoiceAmount', width: 120 },
  { title: t('users.detail.paymentMethod'), colKey: 'payment_summary.gateway', width: 140 },
  { title: t('common.status'), colKey: 'invoiceStatus', width: 120 },
  { title: t('users.detail.invoiceType'), colKey: 'invoiceType', width: 140 },
  { title: t('common.action'), colKey: 'invoiceOperation', width: 140 },
];
const balanceColumns: PrimaryTableCol<TableRowData>[] = [
  { title: t('users.detail.time'), colKey: 'balanceTime', width: 180 },
  { title: t('users.detail.balanceType'), colKey: 'balanceType', width: 140 },
  { title: t('users.detail.balanceChange'), colKey: 'balanceChange', width: 140 },
  { title: t('users.detail.balanceAfter'), colKey: 'balanceAfter', width: 140 },
  { title: t('users.detail.remark'), colKey: 'remark', width: 220 },
  { title: t('users.detail.operator'), colKey: 'operator', width: 140 },
];
const ticketColumns: PrimaryTableCol<TableRowData>[] = [
  { title: 'ID', colKey: 'id', width: 80 },
  { title: t('users.detail.ticketSubject'), colKey: 'subject', width: 260 },
  { title: t('users.detail.ticketPriority'), colKey: 'ticketPriority', width: 120 },
  { title: t('common.status'), colKey: 'ticketStatus', width: 120 },
  { title: t('users.detail.ticketCreatedAt'), colKey: 'ticketCreated', width: 180 },
];
const logColumns: PrimaryTableCol<TableRowData>[] = [
  { title: t('users.detail.time'), colKey: 'logTime', width: 180 },
  { title: t('users.detail.logAction'), colKey: 'action', width: 220 },
  { title: t('users.detail.logModule'), colKey: 'module', width: 140 },
  { title: 'IP', colKey: 'ip_address', width: 160 },
];
const noticeColumns: PrimaryTableCol<TableRowData>[] = [
  { title: t('users.detail.noticeTarget'), colKey: 'noticeTarget', width: 200 },
  { title: t('users.detail.noticeTitle'), colKey: 'noticeTitle', width: 240 },
  { title: t('common.status'), colKey: 'noticeStatus', width: 120 },
  { title: t('users.detail.noticeSentAt'), colKey: 'noticeTime', width: 180 },
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
  { key: 'ticket_open', label: t('users.detail.openTickets'), value: stats.value.ticket_open || 0, tone: 'warning' },
  {
    key: 'cash_balance',
    label: t('users.detail.balance'),
    value: formatMoney(user.value.cash_balance),
    tone: 'success',
  },
  {
    key: 'total_expense',
    label: t('users.detail.totalSpending'),
    value: formatMoney(stats.value.total_expense),
    tone: 'primary',
  },
]);
const referralItems = computed(() => [
  { label: t('users.detail.referralCode'), value: referral.value.referral_code || '-' },
  {
    label: t('users.detail.currentLevel'),
    value: referral.value.member_level?.name || user.value.member_level?.name || t('users.detail.ungraded'),
  },
  { label: t('users.detail.directReferralCount'), value: stats.value.direct_referral_count || 0 },
  {
    label: t('users.detail.totalReferralReward'),
    value: formatMoney(stats.value.total_referral_reward),
    tone: 'success',
  },
  {
    label: t('users.detail.withdrawableReward'),
    value: formatMoney(referral.value.referral_available_amount),
    tone: 'success',
  },
]);
const infoItems = computed(() => [
  { label: t('users.detail.email'), value: fieldValue(user.value.email) },
  { label: t('users.detail.phone'), value: fieldValue(user.value.phone) },
  {
    label: t('users.detail.accountStatus'),
    value: Number(user.value.status) === 1 ? t('users.index.statusNormal') : t('users.index.statusDisabled'),
    tone: Number(user.value.status) === 1 ? 'success' : 'danger',
  },
  { label: t('users.detail.company'), value: fieldValue(user.value.company) },
  { label: 'QQ', value: fieldValue(user.value.qq) },
  { label: t('users.detail.accountBalance'), value: formatMoney(user.value.cash_balance), tone: 'success' },
  { label: t('users.detail.memberLevel'), value: user.value.member_level?.name || t('users.detail.ungraded') },
  { label: t('users.detail.agentGroup'), value: user.value.agent_group?.name || t('users.detail.notSet') },
  {
    label: t('users.detail.realNameVerified'),
    value: verificationText(),
    tone: isVerified.value ? 'success' : 'warning',
  },
  { label: t('users.detail.idNumber'), value: fieldValue(user.value.id_card_masked) },
  { label: t('users.detail.referrerId'), value: fieldValue(user.value.referrer_user_id) },
  { label: t('users.detail.lastLoginAt'), value: formatDateTime(user.value.last_login_at) },
  { label: t('users.detail.lastLoginIp'), value: fieldValue(user.value.last_login_ip) },
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
  // 0 表示“无代理分组”（null），作为下拉中的显式可选项。
  editForm.agent_group_id = Number(user.value.agent_group_id ?? user.value.agent_group?.id ?? 0) || 0;
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
  loader: (_params: PageParams) => Promise<{ list?: Row[]; total?: number; page?: number; page_size?: number }>,
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
    MessagePlugin.success(t('users.detail.remarkSaved'));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('users.detail.noteSaveFailed')));
  } finally {
    noteSaving.value = false;
  }
}

async function handleSave() {
  const result = await editFormRef.value?.validate?.();
  if (!isValidationPass(result)) return;
  saveLoading.value = true;
  try {
    const payload: UserUpdatePayload = {
      nickname: editForm.nickname,
      status: editForm.status,
      // 下拉中 0 表示“无代理分组”，保存时转换为 null（后端 agent_group_id 可空）。
      agent_group_id: canManageUsers.value
        ? editForm.agent_group_id
          ? Number(editForm.agent_group_id)
          : null
        : undefined,
    };
    // 无原始隐私查看权限时，回显的手机号是脱敏值（如 138****1234）。
    // 若原样提交，后端会剥掉 * 得到残缺号码从而校验失败或写坏数据，
    // 因此脱敏值一律视为“未修改”，不随表单提交（空串则用于清空手机号）。
    const phone = String(editForm.phone || '').trim();
    if (!phone.includes('*')) {
      payload.phone = phone;
    }
    const password = editForm.password.trim();
    if (password) payload.password = password;
    await userApi.update(userId.value, payload);
    MessagePlugin.success(t('users.detail.profileUpdated'));
    editVisible.value = false;
    // 写入已成功；刷新失败不得回退成"更新失败"，否则管理员会误以为没保存而重复提交
    try {
      await loadDetail();
    } catch {
      MessagePlugin.warning(t('users.detail.profileRefreshFailed'));
    }
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('users.detail.profileSaveFailed')));
  } finally {
    saveLoading.value = false;
  }
}

function openRechargeDialog() {
  rechargeForm.email = user.value.email || user.value.phone || '-';
  rechargeForm.type = 'increase';
  rechargeForm.amount = 0;
  rechargeForm.remark = '';
  // 每次打开表单都是一次独立的调账意图，换新幂等标识：
  // 上一次已提交的标识若被复用，改金额再提交会被服务端判为「同键不同额」而拒绝。
  rechargeIdempotencyKey.value = buildIdempotencyKey('admin-balance-adjust');
  rechargeVisible.value = true;
}

async function handleRecharge() {
  const result = await rechargeFormRef.value?.validate?.();
  if (!isValidationPass(result)) return;
  rechargeLoading.value = true;
  try {
    const amount = rechargeForm.type === 'decrease' ? -rechargeForm.amount : rechargeForm.amount;
    await userApi.recharge(userId.value, {
      amount,
      remark: rechargeForm.remark,
      idempotency_key: rechargeIdempotencyKey.value,
    });
    MessagePlugin.success(
      rechargeForm.type === 'decrease'
        ? t('users.detail.rechargeDecreaseSuccess')
        : t('users.detail.rechargeIncreaseSuccess'),
    );
    rechargeVisible.value = false;
    // 资金已写入（余额已变、流水已建）；刷新失败绝不能回退成"失败"，
    // 否则管理员会重试而造成重复扣款/充值。
    try {
      await loadDetail();
      if (loadedTabs.balance) await loadBalance();
    } catch {
      MessagePlugin.warning(t('users.detail.actionRefreshFailed'));
    }
  } catch (error) {
    MessagePlugin.error(
      errorMessage(
        error,
        rechargeForm.type === 'decrease'
          ? t('users.detail.rechargeDecreaseFailed')
          : t('users.detail.rechargeIncreaseFailed'),
      ),
    );
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
    MessagePlugin.error(errorMessage(error, t('users.detail.loadProductFailed')));
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
    MessagePlugin.success(t('users.detail.instanceCreated'));
    addServiceVisible.value = false;
    await Promise.all([
      loadServices(),
      loadDetail(),
      ...(loadedTabs.invoices ? [loadInvoices()] : []),
      ...(loadedTabs.balance ? [loadBalance()] : []),
    ]);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('users.detail.addInstanceFailed')));
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
    MessagePlugin.success(t('users.detail.serviceStatusRefreshed'));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('users.detail.refreshServiceFailed')));
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
    MessagePlugin.success(t('users.detail.serviceStatusBatchRefreshed'));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('users.detail.batchRefreshFailed')));
  } finally {
    services.refreshingStatus = false;
  }
}

function handleDeleteServiceRow(row: Row) {
  if (!row?.id) return;
  const dialog = DialogPlugin.confirm({
    header: t('users.detail.deleteServiceRecord'),
    body: t('users.detail.deleteServiceConfirm', { name: serviceName(row) }),
    confirmBtn: t('common.confirmDelete'),
    cancelBtn: t('common.cancel'),
    theme: 'warning',
    async onConfirm() {
      services.refreshing = true;
      try {
        await userApi.serviceDelete(userId.value, row.id);
        MessagePlugin.success(t('users.detail.instanceDeleted'));
        dialog.hide();
        await Promise.all([loadServices(), loadDetail()]);
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('users.detail.deleteServiceFailed')));
      } finally {
        services.refreshing = false;
      }
    },
  });
}

async function handleLoginAs() {
  if (!canLoginAs.value) {
    MessagePlugin.error(t('users.detail.delegateLoginForbidden'));
    return;
  }

  const popup = window.open('about:blank', '_blank');
  if (!popup) {
    MessagePlugin.error(t('users.detail.delegateLoginPopupBlocked'));
    return;
  }

  loginAsLoading.value = true;
  try {
    const response = await userApi.loginAs(userId.value);
    const code = String(response.login_code || '').trim();
    if (!code) {
      closeLoginAsPopup(popup);
      MessagePlugin.error(t('users.detail.delegateLoginCredentialMissing'));
      return;
    }

    const target = resolveLoginAsTarget(response.target_url);
    if (!target) {
      closeLoginAsPopup(popup);
      MessagePlugin.error(t('users.detail.delegateLoginUrlInvalid'));
      return;
    }

    popup.location.replace(target);
    await waitForLoginAsReady(popup, target);
    popup.postMessage({ type: LOGIN_AS_CODE_EVENT, code }, new URL(target, window.location.origin).origin);
    MessagePlugin.success(t('users.detail.delegateLoginOpened'));
  } catch (error) {
    closeLoginAsPopup(popup);
    MessagePlugin.error(errorMessage(error, t('users.detail.loginAsFailed')));
  } finally {
    loginAsLoading.value = false;
  }
}

function closeLoginAsPopup(popup: Window) {
  if (!popup.closed) {
    popup.close();
  }
}

/**
 * 在后端下发的控制台地址后接上代登录页路径。
 *
 * 后端返回的 target_url 已经是完整的代登录页地址（形如
 * https://example.com/console/client/login-as）。这里只需清空
 * query 与 hash，保留原有 pathname 不变。
 */
function resolveLoginAsTarget(targetUrl: string | undefined) {
  const rawTarget = String(targetUrl || '').trim();
  if (!rawTarget) return '';

  try {
    const target = new URL(rawTarget, window.location.origin);
    if (!['http:', 'https:'].includes(target.protocol)) {
      return '';
    }
    // 后端已返回完整的代登录页地址（含 /client/login-as），
    // 仅需清空 query 与 hash，保持 pathname 不变。
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
      reject(new Error(t('users.detail.loginAsInitTimeout')));
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
    MessagePlugin.error(errorMessage(error, t('users.detail.loadInstanceFailed')));
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
    MessagePlugin.success(t('users.detail.remoteStatusRefreshed'));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('users.detail.refreshRemoteFailed')));
  } finally {
    serviceDrawer.actionLoading = '';
  }
}

function handleServicePower(action: string) {
  if (!serviceDrawer.serviceId || !action) return;
  const label =
    (
      { on: t('users.detail.powerOn'), off: t('users.detail.powerOff'), reboot: t('users.detail.reboot') } as Record<
        string,
        string
      >
    )[action] || action;
  const dialog = DialogPlugin.confirm({
    header: t('users.detail.powerConfirmTitle', { action: label }),
    body: t('users.detail.powerConfirmBody', { action: label }),
    confirmBtn: t('users.detail.powerConfirmButton', { action: label }),
    cancelBtn: t('common.cancel'),
    theme: 'warning',
    async onConfirm() {
      serviceDrawer.actionLoading = `power:${action}`;
      try {
        const response = await userApi.servicePower(userId.value, serviceDrawer.serviceId, { action });
        if (response.detail) serviceDrawer.detail = mergeServiceDetail(serviceDrawer.detail, response.detail);
        MessagePlugin.success(response.message || t('users.detail.powerDispatched', { action: label }));
        dialog.hide();
        await handleRefreshConsoleRemoteStatus();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('users.detail.powerFailed', { action: label })));
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
    MessagePlugin.success(t('users.detail.passwordResetDispatched'));
    resetPasswordVisible.value = false;
    await reloadServiceDrawer();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('users.detail.passwordResetFailed')));
  } finally {
    serviceDrawer.actionLoading = '';
  }
}

function openUpstreamProvisionDialog() {
  upstreamProvisionForm.force = false;
  upstreamProvisionForm.remark = '';
  upstreamProvisionVisible.value = true;
}

async function handleUpstreamProvision() {
  if (!serviceDrawer.serviceId) return;
  serviceDrawer.actionLoading = 'upstream-provision';
  try {
    await userApi.provisionUpstreamService(userId.value, serviceDrawer.serviceId, {
      force: upstreamProvisionForm.force,
      remark: upstreamProvisionForm.remark || undefined,
    });
    MessagePlugin.success(t('users.detail.upstreamProvisionSuccess'));
    upstreamProvisionVisible.value = false;
    await Promise.all([reloadServiceDrawer(), loadServices()]);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('users.detail.upstreamProvisionFailed')));
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
        const type = item.provider_label || upstreamBinding.provider_key || t('users.detail.upstreamFallback');
        const interfaceName = item.name || t('users.detail.interfaceWithId', { id: item.id });
        return { id, label: `${interfaceName} · ${type}` };
      })
      .filter((item) => item.id > 0);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('users.detail.loadUpstreamInterfacesFailed')));
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
    MessagePlugin.success(t('users.detail.upstreamBindingUpdated'));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('users.detail.updateUpstreamBindingFailed')));
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
    MessagePlugin.success(t('users.detail.priceUpdated'));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('users.detail.updatePricingFailed')));
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
    MessagePlugin.success(t('users.detail.instanceNameUpdated'));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('users.detail.updateInstanceNameFailed')));
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
    MessagePlugin.success(response.message || t('users.detail.serviceRefunded'));
    serviceRefundVisible.value = false;
    await Promise.all([reloadServiceDrawer(), loadServices(), loadDetail()]);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('users.detail.refundFailed')));
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
    MessagePlugin.error(errorMessage(error, t('users.detail.loadInvoiceFailed')));
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
    header: t('users.detail.cancelInvoice'),
    body: t('users.detail.cancelInvoiceConfirm'),
    confirmBtn: t('common.confirmCancel'),
    cancelBtn: t('common.cancel'),
    theme: 'warning',
    async onConfirm() {
      try {
        await adminApi.invoices.cancel(row.id);
        MessagePlugin.success(t('users.detail.invoiceCancelled'));
        dialog.hide();
        await Promise.all([loadInvoices(), loadDetail()]);
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('users.detail.cancelInvoiceFailed')));
      }
    },
  });
}

async function handleDrawerCancelInvoice() {
  if (!invoiceDrawer.currentId) return;
  invoiceDrawer.cancelLoading = true;
  try {
    await adminApi.invoices.cancel(invoiceDrawer.currentId);
    MessagePlugin.success(t('users.detail.invoiceCancelled'));
    await Promise.all([loadInvoices(), loadDetail(), reloadInvoiceDrawer()]);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('users.detail.cancelInvoiceFailed')));
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
    MessagePlugin.success(t('users.detail.invoiceRefunded'));
    invoiceRefundVisible.value = false;
    await Promise.all([loadInvoices(), loadDetail(), reloadInvoiceDrawer()]);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('users.detail.refundFailed')));
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
    (row.product_id ? t('users.detail.unconfiguredSpec', { id: row.product_id }) : '-')
  );
}
function goServiceDetail(row: Row) {
  goService(router, row.id, userId.value);
}
function viewInvoiceLinkedService() {
  const service = (currentInvoice.value.service as Row | undefined) || {};
  goService(router, service.id, userId.value);
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
    product.display_name ||
      product.name ||
      product.product_name ||
      (product.id ? t('users.detail.productWithId', { id: product.id }) : ''),
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
  if (isVerified.value) {
    return user.value.real_name
      ? t('users.detail.verifiedWithName', { name: user.value.real_name })
      : t('users.detail.verified');
  }
  if (Number(user.value.verification_status) === 3) return t('users.detail.verificationFailed');
  if ([1, 4].includes(Number(user.value.verification_status))) return t('users.detail.verificationPending');
  return t('users.detail.verificationNone');
}
function invoiceStatusLabel(status: unknown) {
  return invoiceStatusLabelMap[String(status ?? '')] || '-';
}
function invoiceTypeLabel(type: unknown) {
  return (
    (
      {
        new: t('users.detail.changeTypeNew'),
        renew: t('users.detail.changeTypeRenew'),
        recharge: t('users.detail.changeTypeRecharge'),
        deduction: t('users.detail.changeTypeDeduction'),
        referral_credit: t('users.detail.changeTypeReferralReward'),
        manual: t('users.detail.changeTypeManual'),
        upgrade: t('users.detail.invoiceTypeUpgrade'),
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
    recharge: t('users.detail.changeTypeRecharge'),
    consume: t('users.detail.balanceTypeConsume'),
    invoice_payment: t('users.detail.balanceTypeInvoicePayment'),
    refund: t('users.detail.balanceTypeRefund'),
    invoice_refund: t('users.detail.balanceTypeInvoiceRefund'),
    adjust: t('users.detail.balanceTypeAdjust'),
    admin_deduct: t('users.detail.balanceTypeAdminDeduct'),
    manual_deduction: t('users.detail.balanceTypeManualDeduction'),
    manual_recharge: t('users.detail.balanceTypeManualRecharge'),
    referral_withdraw_approved: t('users.detail.balanceTypeReferralWithdraw'),
    referral_credit_cash: t('users.detail.balanceTypeReferralWithdraw'),
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
      {
        1: t('users.detail.priorityLow'),
        2: t('users.detail.priorityMedium'),
        3: t('users.detail.priorityHigh'),
        4: t('users.detail.priorityUrgent'),
        low: t('users.detail.priorityLow'),
        medium: t('users.detail.priorityMedium'),
        high: t('users.detail.priorityHigh'),
        urgent: t('users.detail.priorityUrgent'),
      } as Record<string, string>
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
  return (
    (
      {
        0: t('tickets.index.statusOpen'),
        1: t('tickets.index.statusCustomerReply'),
        2: t('tickets.index.statusStaffReply'),
        3: t('tickets.index.statusClosed'),
      } as Record<number, string>
    )[Number(status)] || '-'
  );
}
function ticketStatusTheme(status: unknown): 'default' | 'success' | 'warning' {
  const value = Number(status);
  if (value === 3) return 'success';
  if ([0, 1, 2].includes(value)) return 'warning';
  return 'default';
}
function noticeLabel(status: unknown) {
  return (
    (
      {
        success: t('users.detail.noticeSuccess'),
        failed: t('users.detail.noticeFailed'),
        pending: t('users.detail.noticePending'),
      } as Record<string, string>
    )[String(status)] || fieldValue(status)
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
