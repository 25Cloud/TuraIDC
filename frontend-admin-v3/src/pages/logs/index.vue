<template>
  <div class="logs-page">
    <template v-if="isLogTab(activeTab)">
      <t-card :bordered="false">
        <div class="log-filter-grid" :class="`log-filter-grid--${activeTab}`">
          <t-select
            v-if="showFilter('level')"
            v-model="filters.level"
            clearable
            :placeholder="$t('logs.index.filterLevel')"
            @change="handleLogSearch"
          >
            <t-option v-for="item in logLevelOptions" :key="item" :label="item" :value="item" />
          </t-select>
          <t-select
            v-if="showFilter('task_key')"
            v-model="filters.task_key"
            clearable
            :placeholder="$t('logs.index.filterTaskName')"
            @change="handleLogSearch"
          >
            <t-option v-for="item in taskLogOptions" :key="item.value" :label="item.label" :value="item.value" />
          </t-select>
          <t-select
            v-if="showFilter('method')"
            v-model="filters.method"
            clearable
            :placeholder="$t('logs.index.filterRequestMethod')"
            @change="handleLogSearch"
          >
            <t-option v-for="item in methodOptions" :key="item" :label="item" :value="item" />
          </t-select>
          <t-input
            v-if="showFilter('actor_keyword')"
            v-model="filters.actor_keyword"
            clearable
            :placeholder="$t('logs.index.filterActor')"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('description_keyword')"
            v-model="filters.description_keyword"
            clearable
            :placeholder="$t('logs.index.filterDescriptionKeyword')"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('ip_address')"
            v-model="filters.ip_address"
            clearable
            :placeholder="$t('logs.index.filterIpAddress')"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('module')"
            v-model="filters.module"
            clearable
            :placeholder="$t('logs.index.filterModule')"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('ticket_id')"
            v-model="filters.ticket_id"
            clearable
            :placeholder="$t('logs.index.filterTicketId')"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('ticket_reply_id')"
            v-model="filters.ticket_reply_id"
            clearable
            :placeholder="$t('logs.index.filterTicketReplyId')"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('operation')"
            v-model="filters.operation"
            clearable
            :placeholder="$t('logs.index.filterOperation')"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('event')"
            v-model="filters.event"
            clearable
            :placeholder="$t('logs.index.filterEvent')"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('reason_code')"
            v-model="filters.reason_code"
            clearable
            :placeholder="$t('logs.index.filterReasonCode')"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('provider_key')"
            v-model="filters.provider_key"
            clearable
            placeholder="Provider key"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('supplier_id')"
            v-model="filters.supplier_id"
            clearable
            :placeholder="$t('logs.index.filterSupplierId')"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-select
            v-if="showFilter('user_type')"
            v-model="filters.user_type"
            clearable
            :placeholder="$t('logs.index.filterCaller')"
            @change="handleLogSearch"
          >
            <t-option :label="$t('logs.index.actorAdmin')" value="admin" />
            <t-option :label="$t('logs.index.actorCustomer')" value="client" />
            <t-option :label="$t('logs.index.actorGuest')" value="guest" />
          </t-select>
          <t-select
            v-if="showFilter('status')"
            v-model="filters.status"
            clearable
            :placeholder="statusPlaceholder"
            @change="handleLogSearch"
          >
            <t-option v-for="item in statusOptions" :key="String(item.value)" :label="item.label" :value="item.value" />
          </t-select>
          <t-input
            v-if="showFilter('phone')"
            v-model="filters.phone"
            clearable
            :placeholder="$t('logs.index.filterPhone')"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('email')"
            v-model="filters.email"
            clearable
            :placeholder="$t('logs.index.filterEmail')"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-select
            v-if="showFilter('gateway')"
            v-model="filters.gateway"
            clearable
            :placeholder="$t('logs.index.filterGateway')"
            @change="handleLogSearch"
          >
            <t-option v-for="item in gatewayOptions" :key="item.value" :label="item.label" :value="item.value" />
          </t-select>
          <t-input
            v-if="showFilter('plugin_id')"
            v-model="filters.plugin_id"
            clearable
            :placeholder="$t('logs.index.filterPluginId')"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('gateway_key')"
            v-model="filters.gateway_key"
            clearable
            :placeholder="$t('logs.index.filterGatewayKey')"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('driver_key')"
            v-model="filters.driver_key"
            clearable
            :placeholder="$t('logs.index.filterDriverKey')"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('trace_id')"
            v-model="filters.trace_id"
            clearable
            placeholder="Trace ID"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-select
            v-if="showFilter('action')"
            v-model="filters.action"
            clearable
            :placeholder="$t('logs.index.filterGatewayAction')"
            @change="handleLogSearch"
          >
            <t-option v-for="item in gatewayActionOptions" :key="item.value" :label="item.label" :value="item.value" />
          </t-select>
          <t-select
            v-if="showFilter('result_status')"
            v-model="filters.result_status"
            clearable
            :placeholder="$t('logs.index.filterResultStatus')"
            @change="handleLogSearch"
          >
            <t-option
              v-for="item in gatewayResultOptions"
              :key="String(item.value)"
              :label="item.label"
              :value="item.value"
            />
          </t-select>
          <t-select
            v-if="showFilter('actor_type')"
            v-model="filters.actor_type"
            clearable
            :placeholder="$t('logs.index.filterActorType')"
            @change="handleLogSearch"
          >
            <t-option :label="$t('logs.index.actorAdmin')" value="admin" />
            <t-option :label="$t('logs.index.actorCustomer')" value="client" />
            <t-option :label="$t('logs.index.actorSystem')" value="system" />
          </t-select>
          <t-select
            v-if="showFilter('subject_type')"
            v-model="filters.subject_type"
            clearable
            :placeholder="$t('logs.index.filterSubjectType')"
            @change="handleLogSearch"
          >
            <t-option
              v-for="item in activitySubjectOptions"
              :key="item.value"
              :label="item.label"
              :value="item.value"
            />
          </t-select>
          <t-input
            v-if="showFilter('keyword')"
            v-model="filters.keyword"
            clearable
            :placeholder="keywordPlaceholder"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          >
            <template #suffix-icon><search-icon /></template>
          </t-input>
          <t-date-picker
            v-if="showFilter('date')"
            v-model="filters.start_date"
            clearable
            mode="date"
            format="YYYY-MM-DD"
            value-type="YYYY-MM-DD"
            :placeholder="$t('logs.index.filterStartDate')"
            @change="handleLogSearch"
          />
          <t-date-picker
            v-if="showFilter('date')"
            v-model="filters.end_date"
            clearable
            mode="date"
            format="YYYY-MM-DD"
            value-type="YYYY-MM-DD"
            :placeholder="$t('logs.index.filterEndDate')"
            @change="handleLogSearch"
          />
        </div>

        <div v-if="!isMobile" class="table-scroll" :class="{ 'table-scroll--upstream': isUpstreamLogTab }">
          <t-table
            row-key="id"
            :data="displayLogRows"
            :columns="logTableColumns"
            :loading="logLoading"
            hover
            table-layout="fixed"
            :class="{ 'upstream-log-table': isUpstreamLogTab }"
            :expand-icon="isUpstreamLogTab"
            :expanded-row-keys="expandedLogGroupKeys"
            @expand-change="handleLogGroupExpand"
          >
            <template #time="{ row }">{{
              formatDate(row.occurred_at || row.time || row.created_at || row.sent_at)
            }}</template>
            <template #primary="{ row }">
              <div class="stack-cell">
                <strong>{{ primaryTitle(row) }}</strong>
                <span>{{ primarySubText(row) }}</span>
              </div>
            </template>
            <template #request="{ row }">
              <div class="request-cell">
                <div>
                  <t-tag variant="light">{{ fieldValue(row.method) }}</t-tag>
                  <strong>{{ fieldValue(row.path) }}</strong>
                </div>
                <span>{{ fieldValue(row.request_id || $t('logs.index.noRequestId')) }}</span>
              </div>
            </template>
            <template #level="{ row }">
              <t-tag :theme="levelTheme(row.level)" variant="light">{{ fieldValue(row.level) }}</t-tag>
            </template>
            <template #status="{ row }">
              <t-tag :theme="statusTheme(statusValue(row))" variant="light">{{ statusLabel(statusValue(row)) }}</t-tag>
            </template>
            <template #ticket_id="{ row }">
              <div class="stack-cell">
                <button
                  type="button"
                  class="user-link"
                  :disabled="!isEntityId(row.ticket_id)"
                  @click="goTicket(router, row.ticket_id)"
                >
                  <strong>{{ $t('logs.index.ticketLabel') }} #{{ fieldValue(row.ticket_id) }}</strong>
                  <span>{{ $t('logs.index.pushRecordCount', { count: row.log_count || 1 }) }}</span>
                </button>
              </div>
            </template>
            <template #httpStatus="{ row }">
              <t-tag :theme="httpStatusTheme(row.status)" variant="light">{{ fieldValue(row.status) }}</t-tag>
            </template>
            <template #subject_type="{ row }">
              {{ subjectText(row) }}
            </template>
            <template #message="{ row }">
              <div v-if="isTextLog" class="log-message">
                <t-tag v-if="messageTag(row)" size="small" variant="light" class="log-message__tag">{{
                  messageTag(row)
                }}</t-tag>
                <span class="log-message__body">
                  <template v-for="(seg, si) in parseMessageSegments(row)" :key="si">
                    <span v-if="seg.type === 'text'">{{ seg.text }}</span>
                    <t-tag
                      v-else
                      size="small"
                      variant="outline"
                      class="log-message__id-tag"
                      :title="idSegmentTitle(seg)"
                      @click="copyId(seg.label, seg.id)"
                      >{{ seg.label }}:{{ seg.id }}</t-tag
                    >
                  </template>
                </span>
              </div>
              <span v-else class="line-clamp">{{ messageText(row) }}</span>
            </template>
            <template #error="{ row }">
              <span :class="{ 'danger-text': row.error_msg }">{{ fieldValue(row.error_msg) }}</span>
            </template>
            <template #gateway="{ row }">
              {{ gatewayLabel(row.gateway) }}
            </template>
            <template #result_status="{ row }">
              <t-tag :theme="gatewayResultStatusTheme(row.result_status)" variant="light">{{
                gatewayResultStatusLabel(row.result_status)
              }}</t-tag>
            </template>
            <template #actions="{ row }">
              <t-button theme="primary" variant="text" @click="openDetail(row)">{{ $t('logs.index.detail') }}</t-button>
            </template>
            <template #expandedRow="{ row }">
              <div v-if="isUpstreamLogTab" class="upstream-log-group">
                <div class="upstream-log-group__title">
                  {{ $t('logs.index.totalPushRecords', { count: row.log_count || 0 }) }}
                </div>
                <div v-for="log in upstreamGroupLogs(row)" :key="String(log.id)" class="upstream-log-group__item">
                  <div class="upstream-log-group__meta">
                    <t-tag :theme="statusTheme(log.status)" variant="light" size="small">{{
                      statusLabel(log.status)
                    }}</t-tag>
                    <span>{{ fieldValue(log.operation) }}</span>
                    <span>{{ fieldValue(log.event) }}</span>
                    <time>{{ formatDate(log.occurred_at) }}</time>
                  </div>
                  <div class="upstream-log-group__message">{{ fieldValue(log.message || log.reason_code) }}</div>
                  <t-button theme="primary" variant="text" size="small" @click="openDetail(log)">{{
                    $t('logs.index.detail')
                  }}</t-button>
                </div>
              </div>
            </template>
          </t-table>
        </div>

        <div v-else class="table-scroll">
          <t-loading :loading="logLoading" size="small">
            <div v-if="displayLogRows.length" class="log-mobile-stack">
              <article
                v-for="row in displayLogRows"
                :key="String(row.id || row.order_no || row.sent_no)"
                class="log-mobile-card"
              >
                <div class="log-mobile-card__head">
                  <span class="log-mobile-card__time">{{
                    formatDate(row.occurred_at || row.time || row.created_at || row.sent_at)
                  }}</span>
                  <t-tag v-if="row.level" :theme="levelTheme(row.level)" variant="light" size="small">{{
                    fieldValue(row.level)
                  }}</t-tag>
                  <t-tag v-if="isTextLog && messageTag(row)" size="small" variant="light">{{ messageTag(row) }}</t-tag>
                  <t-tag
                    v-if="logStatusValue(row)"
                    :theme="statusTheme(logStatusValue(row))"
                    variant="light"
                    size="small"
                    >{{ statusLabel(logStatusValue(row)) }}</t-tag
                  >
                  <t-tag
                    v-if="row.result_status"
                    :theme="gatewayResultStatusTheme(row.result_status)"
                    variant="light"
                    size="small"
                    >{{ gatewayResultStatusLabel(row.result_status) }}</t-tag
                  >
                </div>
                <div class="log-mobile-card__body">{{ logCardMessage(row) }}</div>
                <div class="log-mobile-card__meta">
                  <t-tag v-if="row.method" variant="outline" size="small">{{ fieldValue(row.method) }}</t-tag>
                  <span v-if="row.path" class="muted-text">{{ fieldValue(row.path) }}</span>
                  <span v-if="row.gateway" class="muted-text"
                    >{{ $t('logs.index.gatewayLabel') }}: {{ gatewayLabel(row.gateway) }}</span
                  >
                  <span v-if="row.gateway_key" class="muted-text"
                    >{{ $t('logs.index.gatewayKeyLabel') }}: {{ fieldValue(row.gateway_key) }}</span
                  >
                  <span v-if="row.driver_key" class="muted-text"
                    >{{ $t('logs.index.driverKeyLabel') }}: {{ fieldValue(row.driver_key) }}</span
                  >
                  <span v-if="row.plugin_key" class="muted-text"
                    >{{ $t('logs.index.pluginLabel') }}: {{ fieldValue(row.plugin_key) }}</span
                  >
                  <span v-if="row.trace_id" class="muted-text">Trace: {{ fieldValue(row.trace_id) }}</span>
                  <span v-if="row.status" class="muted-text">HTTP {{ fieldValue(row.status) }}</span>
                </div>
                <div class="log-mobile-card__actions">
                  <t-button
                    v-if="isUpstreamLogTab"
                    theme="primary"
                    variant="text"
                    size="small"
                    @click="toggleLogGroup(row)"
                  >
                    {{
                      isLogGroupExpanded(row)
                        ? $t('logs.index.collapseLogs')
                        : $t('logs.index.viewLogs', { count: row.log_count || 0 })
                    }}
                  </t-button>
                  <t-button theme="primary" variant="text" size="small" @click="openDetail(row)">{{
                    $t('logs.index.detail')
                  }}</t-button>
                </div>
                <div
                  v-if="isUpstreamLogTab && isLogGroupExpanded(row)"
                  class="upstream-log-group upstream-log-group--mobile"
                >
                  <div v-for="log in upstreamGroupLogs(row)" :key="String(log.id)" class="upstream-log-group__item">
                    <div class="upstream-log-group__meta">
                      <t-tag :theme="statusTheme(log.status)" variant="light" size="small">{{
                        statusLabel(log.status)
                      }}</t-tag>
                      <span>{{ fieldValue(log.operation) }}</span>
                      <span>{{ fieldValue(log.event) }}</span>
                      <time>{{ formatDate(log.occurred_at) }}</time>
                    </div>
                    <div class="upstream-log-group__message">{{ fieldValue(log.message || log.reason_code) }}</div>
                    <t-button theme="primary" variant="text" size="small" @click="openDetail(log)">{{
                      $t('logs.index.detail')
                    }}</t-button>
                  </div>
                </div>
              </article>
            </div>
            <t-empty v-else :description="$t('logs.index.emptyLogs')" />
          </t-loading>
        </div>

        <div v-if="logPagination.total > 0" class="pagination-row">
          <t-pagination
            :current="logPagination.page"
            :page-size="logPagination.page_size"
            :total="logPagination.total"
            :page-size-options="[10, 15, 20, 50, 100]"
            show-jumper
            @change="handleLogPageChange"
          />
        </div>
      </t-card>
    </template>

    <template v-else-if="activeTab === 'schedules'">
      <t-card v-if="!canViewSchedules" :bordered="false">
        <t-alert theme="warning" :message="$t('logs.index.scheduleNoPermission')" />
      </t-card>
      <template v-else>
        <t-card v-if="scheduleEnvAlerts.length > 0" :bordered="false" class="schedule-env-card">
          <div class="schedule-env-grid">
            <article
              v-for="alert in scheduleEnvAlerts"
              :key="alert.key"
              class="schedule-env-item"
              :class="[
                `schedule-env-item--${alert.theme}`,
                { 'schedule-env-item--clickable': isQueueEnvKey(alert.key) && canManageQueueJobs },
              ]"
              :role="isQueueEnvKey(alert.key) && canManageQueueJobs ? 'button' : undefined"
              @click="openQueueDrawerFromEnv(alert.key)"
            >
              <div class="schedule-env-item__head">
                <t-tag :theme="alert.theme" variant="light">{{ alert.label }}</t-tag>
                <strong>{{ alert.value }}</strong>
              </div>
              <span v-if="alert.detail" class="schedule-env-item__detail">{{ alert.detail }}</span>
            </article>
          </div>
        </t-card>

        <t-card :bordered="false" :loading="scheduleLoading">
          <template #title>{{ $t('logs.index.registeredTasks') }}</template>
          <template #subtitle>{{ $t('logs.index.taskCount', { count: scheduleTasks.length }) }}</template>
          <t-alert theme="info" :message="$t('logs.index.scheduleExecutionTip')" class="schedule-execution-tip" />
          <div class="schedule-task-toolbar">
            <div class="schedule-task-stats" :aria-label="$t('logs.index.taskSourceStats')">
              <div class="schedule-task-stat">
                <span>{{ $t('logs.index.systemTasks') }}</span>
                <strong>{{ scheduleSystemTasks.length }}</strong>
              </div>
              <div class="schedule-task-stat schedule-task-stat--third-party">
                <span>{{ $t('logs.index.thirdPartyTasks') }}</span>
                <strong>{{ scheduleThirdPartyTasks.length }}</strong>
              </div>
            </div>
            <t-radio-group v-model="scheduleSourceFilter" variant="default-filled" class="schedule-task-filter">
              <t-radio-button v-for="item in scheduleSourceOptions" :key="item.value" :value="item.value">
                {{ item.label }}
              </t-radio-button>
            </t-radio-group>
            <t-button v-if="canManageQueueJobs" variant="outline" @click="openQueueDrawer('failed')">
              {{ $t('logs.index.queueJobsButton') }}
            </t-button>
          </div>
          <div class="schedule-task-groups">
            <section v-for="group in visibleScheduleTaskGroups" :key="group.key" class="schedule-task-group">
              <div class="schedule-task-group__head">
                <div>
                  <h3>{{ group.title }}</h3>
                  <p>{{ group.description }}</p>
                </div>
                <t-tag :theme="group.theme" variant="light">{{
                  $t('logs.index.groupTaskCount', { count: group.tasks.length })
                }}</t-tag>
              </div>
              <div v-if="group.tasks.length" class="table-scroll">
                <t-table row-key="key" :data="group.tasks" :columns="scheduleColumns" hover table-layout="fixed">
                  <template #task="{ row }">
                    <div class="stack-cell schedule-task-cell">
                      <div class="schedule-task-cell__name">
                        <strong>{{ fieldValue(row.title || row.key) }}</strong>
                        <t-tag size="small" :theme="scheduleTaskSourceTheme(row)" variant="light">
                          {{ fieldValue(row.source_label || group.title) }}
                        </t-tag>
                      </div>
                      <span>{{ fieldValue(row.category) }}</span>
                    </div>
                  </template>
                  <template #cycle="{ row }">
                    <div class="stack-cell">
                      <strong>{{ formatScheduleCycle(row) }}</strong>
                      <span>{{ fieldValue(row.expression) }}</span>
                    </div>
                  </template>
                  <template #last="{ row }">{{ fieldValue(row.last_run_at || toRecord(row.last_log).time) }}</template>
                  <template #level="{ row }">
                    <t-tag :theme="levelTheme(toRecord(row.last_log).level)" variant="light">
                      {{ fieldValue(toRecord(row.last_log).level || $t('logs.index.pendingRun')) }}
                    </t-tag>
                  </template>
                  <template #next="{ row }">{{ fieldValue(row.next_run_at) }}</template>
                  <template #actions="{ row }">
                    <t-button
                      v-if="row.manual_triggerable"
                      theme="primary"
                      variant="outline"
                      size="small"
                      :loading="triggeringKey === row.key"
                      :disabled="!canTriggerSchedules"
                      @click="triggerTask(row)"
                    >
                      {{ $t('logs.index.runNow') }}
                    </t-button>
                    <span v-else class="muted-text">{{ $t('logs.index.automatic') }}</span>
                  </template>
                </t-table>
              </div>
              <t-empty v-else :description="$t('logs.index.emptyGroup', { name: group.title })" />
            </section>
          </div>
        </t-card>
      </template>
    </template>

    <template v-else>
      <t-card :bordered="false" :loading="cleanupLoading">
        <t-alert theme="warning" :message="$t('logs.index.cleanupIrreversible')" />
        <t-alert v-if="!canManageLogCleanup" theme="warning" :message="$t('logs.index.cleanupNoPermission')" />

        <section class="cleanup-section">
          <div class="section-title">
            <h2>{{ $t('logs.index.databaseLogOverview') }}</h2>
            <p>{{ $t('logs.index.databaseLogOverviewDesc') }}</p>
          </div>
          <div class="cleanup-card-grid">
            <article v-for="item in databaseCards" :key="item.key">
              <span>{{ item.label }}</span>
              <strong>{{ item.value }}</strong>
            </article>
          </div>
        </section>

        <section class="cleanup-section">
          <div class="section-title">
            <h2>{{ $t('logs.index.fileLogOverview') }}</h2>
            <p>{{ $t('logs.index.fileLogOverviewDesc') }}</p>
          </div>
          <div class="cleanup-card-grid">
            <article v-for="item in fileCards" :key="item.key">
              <span>{{ item.label }}</span>
              <strong>{{ item.value }}</strong>
            </article>
          </div>
          <div class="file-meta-grid">
            <article>
              <span>{{ $t('logs.index.filePath') }}</span>
              <strong>{{ fieldValue(toRecord(cleanupOverview.file).path) }}</strong>
            </article>
            <article>
              <span>{{ $t('logs.index.lastUpdated') }}</span>
              <strong>{{ formatDate(toRecord(cleanupOverview.file).updated_at) }}</strong>
            </article>
          </div>
        </section>

        <section class="cleanup-section">
          <div class="section-title">
            <h2>{{ $t('logs.index.runCleanup') }}</h2>
            <p>{{ $t('logs.index.runCleanupDesc') }}</p>
          </div>
          <t-form :data="cleanupForm" label-align="top" class="cleanup-form">
            <t-form-item :label="$t('logs.index.cleanupType')" name="type">
              <t-select v-model="cleanupForm.type" :placeholder="$t('logs.index.selectCleanupType')">
                <t-option
                  v-for="item in cleanupTypes"
                  :key="String(item.value)"
                  :label="String(item.label || item.value)"
                  :value="String(item.value)"
                />
              </t-select>
            </t-form-item>
            <t-form-item :label="$t('logs.index.keepDays')" name="keep_days">
              <t-input-number v-model="cleanupForm.keep_days" :min="1" :max="3650" theme="normal" />
            </t-form-item>
            <t-form-item :label="$t('logs.index.confirmText')" name="confirm_text">
              <t-input
                v-model="cleanupForm.confirm_text"
                clearable
                :placeholder="$t('logs.index.confirmCleanupPlaceholder')"
              />
            </t-form-item>
            <div class="cleanup-actions">
              <t-button
                theme="danger"
                :loading="cleanupSubmitting"
                :disabled="cleanupSubmitDisabled"
                @click="handleCleanup"
                >{{ $t('logs.index.cleanupNow') }}</t-button
              >
              <t-button variant="outline" @click="cleanupForm.confirm_text = ''">{{
                $t('logs.index.clearConfirm')
              }}</t-button>
            </div>
          </t-form>
        </section>

        <section v-if="lastCleanupResult" class="cleanup-section">
          <div class="section-title">
            <h2>{{ $t('logs.index.lastCleanupResult') }}</h2>
            <p>{{ $t('logs.index.lastCleanupResultDesc') }}</p>
          </div>
          <div class="file-meta-grid">
            <article>
              <span>{{ $t('logs.index.cleanupType') }}</span>
              <strong>{{ fieldValue(lastCleanupResult.type) }}</strong>
            </article>
            <article>
              <span>{{ $t('logs.index.keepDays') }}</span>
              <strong>{{ fieldValue(lastCleanupResult.keep_days) }} {{ $t('logs.index.unitDay') }}</strong>
            </article>
            <article>
              <span>{{ $t('logs.index.cutoffTime') }}</span>
              <strong>{{ formatDate(lastCleanupResult.cutoff_at) }}</strong>
            </article>
            <article>
              <span>{{ $t('logs.index.affectedCount') }}</span>
              <strong>{{ affectedCountText }}</strong>
            </article>
          </div>
          <pre class="json-block">{{ formatJson(lastCleanupResult.affected) }}</pre>
        </section>
      </t-card>
    </template>

    <log-detail-drawer
      :visible="detailVisible"
      :current-log="currentLog"
      :active-tab="activeTab"
      :drawer-size="drawerSize"
      @close="closeDetailDrawer"
    />

    <queue-jobs-drawer
      :visible="queueDrawerVisible"
      :initial-queue="queueDrawerQueue"
      @close="queueDrawerVisible = false"
      @changed="loadScheduleOverview"
    />
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { SearchIcon } from 'tdesign-icons-vue-next';
import type { PageInfo, PrimaryTableCol, TableRowData, TagProps } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import type { LogListParams, PaginatedList } from '@/api/admin';
import { adminApi } from '@/api/admin';
import { AdminPermissions, hasPermissionInList } from '@/constants/permissions';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { t } from '@/locales';
import { useUserStore } from '@/store';
import { fieldValue, formatDateTime } from '@/utils/format';
import { goTicket, isEntityId } from '@/utils/route/adminEntityLink';
import { errorMessage } from '@/utils/userMessage';

import LogDetailDrawer from './components/LogDetailDrawer.vue';
import QueueJobsDrawer from './components/QueueJobsDrawer.vue';

type LogTab =
  | 'system'
  | 'runtime'
  | 'admin-logins'
  | 'api'
  | 'sms'
  | 'email'
  | 'tasks'
  | 'gateway'
  | 'upstream'
  | 'upstream-uploads';
type LogsTab = LogTab | 'schedules' | 'cleanup';
type RecordRow = Record<string, unknown>;
type TagTheme = NonNullable<TagProps['theme']>;

const route = useRoute();
const router = useRouter();
const userStore = useUserStore();
const isMobile = useMediaQuery('(max-width: 768px)');
const validTabs: LogsTab[] = [
  'system',
  'runtime',
  'admin-logins',
  'api',
  'sms',
  'email',
  'tasks',
  'gateway',
  'upstream',
  'upstream-uploads',
  'schedules',
  'cleanup',
];
const activeTab = ref<LogsTab>(resolveRouteTab());
const logLoading = ref(false);
const scheduleLoading = ref(false);
const scheduleSourceFilter = ref<'all' | 'system' | 'third_party'>('all');
const cleanupLoading = ref(false);
const cleanupSubmitting = ref(false);
const triggeringKey = ref('');
const detailVisible = ref(false);
const currentLog = ref<RecordRow | null>(null);
const logRows = ref<RecordRow[]>([]);
const expandedLogGroupKeys = ref<Array<string | number>>([]);
const scheduleOverview = ref<Record<string, unknown>>({ tasks: [], recent_logs: [] });
const cleanupOverview = ref<Record<string, unknown>>({ database: {}, file: {}, supported_cleanup_types: [] });
const lastCleanupResult = ref<Record<string, unknown> | null>(null);

const filters = reactive({
  keyword: '',
  actor_keyword: '',
  description_keyword: '',
  ip_address: '',
  level: '',
  task_key: '',
  method: '',
  module: '',
  user_type: '',
  status: '' as string | number,
  phone: '',
  email: '',
  start_date: '',
  end_date: '',
  gateway: '',
  gateway_key: '',
  driver_key: '',
  plugin_id: '',
  trace_id: '',
  action: '',
  result_status: '',
  actor_type: '',
  subject_type: '',
  ticket_id: '',
  ticket_reply_id: '',
  operation: '',
  event: '',
  reason_code: '',
  provider_key: '',
  supplier_id: '',
});
type LogFilterKey = keyof typeof filters;

const logPagination = reactive({
  page: 1,
  page_size: 15,
  total: 0,
});
const cleanupForm = reactive({
  type: '',
  keep_days: 30,
  confirm_text: '',
});

const logLevelOptions = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
const methodOptions = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
const httpStatusOptions = [200, 201, 204, 400, 401, 403, 404, 422, 500].map((value) => ({
  label: String(value),
  value,
}));
const notifyStatusOptions = [
  { label: t('logs.notify.pending'), value: 'pending' },
  { label: t('logs.notify.sent'), value: 'success' },
  { label: t('logs.notify.failed'), value: 'failed' },
];
const gatewayOptions = [
  { label: t('logs.gateway.alipay'), value: 'alipay' },
  { label: t('logs.gateway.wechat'), value: 'wechat' },
  { label: 'Stripe', value: 'stripe' },
  { label: t('logs.gateway.alipayF2f'), value: 'alipay_f2f' },
];
const gatewayActionOptions = [
  { label: t('logs.gateway.actionPrecreate'), value: 'precreate' },
  { label: t('logs.gateway.actionNotify'), value: 'notify' },
  { label: t('logs.gateway.actionQuery'), value: 'query' },
  { label: t('logs.gateway.actionRefund'), value: 'refund' },
];
const gatewayResultOptions = [
  { label: t('logs.status.success'), value: 'success' },
  { label: t('logs.status.failed'), value: 'failed' },
  { label: t('logs.status.processing'), value: 'pending' },
  { label: t('logs.status.unknown'), value: 'unknown' },
];
const activitySubjectOptions = [
  { label: t('logs.activity.invoice'), value: 'invoice' },
  { label: t('logs.activity.order'), value: 'order' },
  { label: t('logs.activity.payment'), value: 'payment' },
  { label: t('logs.activity.service'), value: 'service' },
  { label: t('logs.activity.user'), value: 'user' },
  { label: t('logs.index.ticketLabel'), value: 'ticket' },
  { label: t('logs.activity.product'), value: 'product' },
  { label: t('logs.activity.coupon'), value: 'coupon' },
  { label: t('logs.activity.system'), value: 'system' },
];
const taskLogOptions = [
  { value: 'refresh-hosting-panel-auth', label: t('logs.task.refreshPanelAuth') },
  { value: 'service-auto-renew', label: t('logs.task.serviceAutoRenew') },
  { value: 'referral-release-rewards', label: t('logs.task.referralRewardRelease') },
  { value: 'service-lifecycle-maintenance', label: t('logs.task.serviceLifecycle') },
  { value: 'service-status-sync', label: t('logs.task.serviceStatusSync') },
  { value: 'billing-maintenance', label: t('logs.task.billingMaintenance') },
  { value: 'product-upstream-config-sync', label: t('logs.task.productConfigSync') },
  { value: 'coupon-campaign-dispatch', label: t('logs.task.couponCampaignDispatch') },
  { value: 'ticket-auto-close', label: t('logs.task.ticketAutoClose') },
  { value: 'order-cleanup', label: t('logs.task.billingCleanup') },
];
const logMeta: Record<LogTab, { title: string; description: string; filters: string[]; keyword: string }> = {
  system: {
    title: t('logs.tab.system'),
    description: t('logs.tab.systemDesc'),
    filters: ['actor_keyword', 'description_keyword', 'ip_address', 'module', 'actor_type', 'subject_type', 'date'],
    keyword: t('logs.index.keywordSystem'),
  },
  runtime: {
    title: t('logs.tab.runtime'),
    description: t('logs.tab.runtimeDesc'),
    filters: ['level', 'plugin_id', 'gateway_key', 'driver_key', 'trace_id', 'keyword', 'date'],
    keyword: t('logs.index.keywordRuntime'),
  },
  'admin-logins': {
    title: t('logs.tab.adminLogin'),
    description: t('logs.tab.adminLoginDesc'),
    filters: ['keyword', 'date'],
    keyword: t('logs.index.keywordAdminLogin'),
  },
  api: {
    title: t('logs.tab.api'),
    description: t('logs.tab.apiDesc'),
    filters: ['keyword', 'method', 'module', 'user_type', 'status', 'date'],
    keyword: t('logs.index.keywordApi'),
  },
  sms: {
    title: t('logs.tab.sms'),
    description: t('logs.tab.smsDesc'),
    filters: ['phone', 'plugin_id', 'driver_key', 'trace_id', 'keyword', 'status'],
    keyword: t('logs.index.keywordSms'),
  },
  email: {
    title: t('logs.tab.email'),
    description: t('logs.tab.emailDesc'),
    filters: ['email', 'plugin_id', 'driver_key', 'trace_id', 'keyword', 'status'],
    keyword: t('logs.index.keywordEmail'),
  },
  tasks: {
    title: t('logs.tab.task'),
    description: t('logs.tab.taskDesc'),
    filters: ['task_key', 'status', 'level', 'keyword', 'date'],
    keyword: t('logs.index.keywordTask'),
  },
  gateway: {
    title: t('logs.tab.gateway'),
    description: t('logs.tab.gatewayDesc'),
    filters: ['keyword', 'gateway', 'gateway_key', 'plugin_id', 'trace_id', 'action', 'result_status', 'date'],
    keyword: t('logs.index.keywordGateway'),
  },
  upstream: {
    title: t('logs.tab.ticketPush'),
    description: t('logs.tab.ticketPushDesc'),
    filters: [
      'keyword',
      'ticket_id',
      'ticket_reply_id',
      'status',
      'operation',
      'event',
      'reason_code',
      'provider_key',
      'supplier_id',
      'date',
    ],
    keyword: t('logs.index.keywordUpstream'),
  },
  'upstream-uploads': {
    title: t('logs.tab.upload'),
    description: t('logs.tab.uploadDesc'),
    filters: ['level', 'keyword', 'date'],
    keyword: t('logs.index.keywordUpstreamUpload'),
  },
};

const baseLogColumns: Record<LogTab, PrimaryTableCol<RecordRow>[]> = {
  system: [
    { colKey: 'time', title: t('logs.index.time'), width: 170 },
    { colKey: 'primary', title: t('logs.index.actor'), minWidth: 180 },
    { colKey: 'actor_type', title: t('logs.index.actorType'), width: 120 },
    { colKey: 'message', title: t('logs.index.description'), minWidth: 320 },
    { colKey: 'ip_address', title: t('logs.index.ipAddress'), width: 150 },
    { colKey: 'subject_type', title: t('logs.index.relatedObject'), width: 140 },
    { colKey: 'actions', title: t('common.action'), fixed: 'right', width: 90 },
  ],
  runtime: [
    { colKey: 'time', title: t('logs.index.recordedAt'), width: 170 },
    { colKey: 'level', title: t('logs.index.level'), width: 100 },
    { colKey: 'plugin_key', title: t('logs.index.pluginKey'), minWidth: 120, ellipsis: true },
    { colKey: 'trace_id', title: 'Trace ID', minWidth: 180, ellipsis: true },
    { colKey: 'message', title: t('logs.index.content'), minWidth: 520 },
    { colKey: 'actions', title: t('common.action'), fixed: 'right', width: 90 },
  ],
  'admin-logins': [
    { colKey: 'primary', title: t('logs.index.accountInfo'), minWidth: 230 },
    { colKey: 'role_name', title: t('logs.index.role'), minWidth: 140 },
    { colKey: 'ip_address', title: t('logs.index.loginIp'), minWidth: 150 },
    { colKey: 'time', title: t('logs.index.loginAt'), width: 170 },
    { colKey: 'status', title: t('logs.index.source'), width: 120 },
    { colKey: 'actions', title: t('common.action'), fixed: 'right', width: 90 },
  ],
  api: [
    { colKey: 'request', title: t('logs.index.request'), minWidth: 320 },
    { colKey: 'httpStatus', title: t('logs.index.statusCode'), width: 100 },
    { colKey: 'module', title: t('logs.index.module'), minWidth: 120 },
    { colKey: 'primary', title: t('logs.index.caller'), minWidth: 160 },
    { colKey: 'ip_address', title: 'IP', minWidth: 140 },
    { colKey: 'time', title: t('logs.index.recordedAt'), width: 170 },
    { colKey: 'actions', title: t('common.action'), fixed: 'right', width: 90 },
  ],
  sms: [
    { colKey: 'id', title: 'ID', width: 72 },
    { colKey: 'primary', title: t('logs.index.receiver'), minWidth: 200 },
    { colKey: 'template_code', title: t('logs.index.templateCode'), width: 116, ellipsis: true },
    { colKey: 'message', title: t('logs.index.contentSummary'), minWidth: 240 },
    { colKey: 'status', title: t('common.status'), width: 100 },
    { colKey: 'driver_key', title: t('logs.index.driverKeyLabel'), minWidth: 112, ellipsis: true },
    { colKey: 'trace_id', title: 'Trace ID', minWidth: 150, ellipsis: true },
    { colKey: 'request_id', title: t('logs.index.requestId'), minWidth: 150, ellipsis: true },
    { colKey: 'error', title: t('logs.index.errorMessage'), minWidth: 190 },
    { colKey: 'time', title: t('logs.index.createdAt'), width: 156 },
    { colKey: 'actions', title: t('common.action'), fixed: 'right', width: 80 },
  ],
  email: [
    { colKey: 'id', title: 'ID', width: 72 },
    { colKey: 'primary', title: t('logs.index.recipient'), minWidth: 200 },
    { colKey: 'template_code', title: t('logs.index.templateCode'), width: 116, ellipsis: true },
    { colKey: 'subject', title: t('logs.index.subject'), minWidth: 200, ellipsis: true },
    { colKey: 'message', title: t('logs.index.bodySummary'), minWidth: 240 },
    { colKey: 'status', title: t('common.status'), width: 100 },
    { colKey: 'driver_key', title: t('logs.index.driverKeyLabel'), minWidth: 112, ellipsis: true },
    { colKey: 'trace_id', title: 'Trace ID', minWidth: 150, ellipsis: true },
    { colKey: 'error', title: t('logs.index.errorMessage'), minWidth: 190 },
    { colKey: 'time', title: t('logs.index.createdAt'), width: 156 },
    { colKey: 'actions', title: t('common.action'), fixed: 'right', width: 80 },
  ],
  tasks: [
    { colKey: 'time', title: t('logs.index.recordedAt'), width: 170 },
    { colKey: 'primary', title: t('logs.index.task'), minWidth: 220 },
    { colKey: 'status', title: t('common.status'), width: 110 },
    { colKey: 'level', title: t('logs.index.level'), width: 100 },
    { colKey: 'duration_ms', title: t('logs.index.durationMs'), width: 110 },
    { colKey: 'message', title: t('logs.index.summary'), minWidth: 360 },
    { colKey: 'actions', title: t('common.action'), fixed: 'right', width: 90 },
  ],
  gateway: [
    { colKey: 'time', title: t('logs.index.recordedAt'), width: 170 },
    { colKey: 'gateway', title: t('logs.index.gatewayLabel'), width: 110 },
    { colKey: 'gateway_key', title: t('logs.index.gatewayKeyLabel'), minWidth: 120, ellipsis: true },
    { colKey: 'action', title: t('common.action'), width: 110 },
    { colKey: 'out_trade_no', title: t('logs.index.merchantOrderNo'), minWidth: 180, ellipsis: true },
    { colKey: 'trade_no', title: t('logs.index.tradeNo'), minWidth: 180, ellipsis: true },
    { colKey: 'trace_id', title: 'Trace ID', minWidth: 170, ellipsis: true },
    { colKey: 'result_status', title: t('logs.index.result'), width: 90 },
    { colKey: 'error_msg', title: t('logs.index.errorMessage'), minWidth: 150, ellipsis: true },
    { colKey: 'actions', title: t('common.action'), fixed: 'right', width: 90 },
  ],
  upstream: [
    { colKey: 'time', title: t('logs.index.occurredAt'), width: 170 },
    { colKey: 'ticket_id', title: t('logs.index.ticketNo'), width: 100 },
    { colKey: 'operation', title: t('common.action'), width: 130 },
    { colKey: 'event', title: t('logs.index.event'), width: 110 },
    { colKey: 'status', title: t('common.status'), width: 100 },
    { colKey: 'reason_code', title: t('logs.index.reasonCode'), minWidth: 170, ellipsis: true },
    { colKey: 'message', title: t('logs.index.resultMessage'), minWidth: 320 },
    { colKey: 'actions', title: t('common.action'), fixed: 'right', width: 90 },
  ],
  'upstream-uploads': [
    { colKey: 'time', title: t('logs.index.recordedAt'), width: 170 },
    { colKey: 'level', title: t('logs.index.level'), width: 100 },
    { colKey: 'message', title: t('logs.index.content'), minWidth: 520 },
    { colKey: 'actions', title: t('common.action'), fixed: 'right', width: 90 },
  ],
};
const scheduleColumns: PrimaryTableCol<RecordRow>[] = [
  { colKey: 'task', title: t('logs.index.taskName'), minWidth: 260 },
  { colKey: 'cycle', title: t('logs.index.refreshCycle'), minWidth: 180 },
  { colKey: 'last', title: t('logs.index.lastRun'), width: 170 },
  { colKey: 'level', title: t('common.status'), width: 110 },
  { colKey: 'next', title: t('logs.index.nextRun'), width: 170 },
  { colKey: 'actions', title: t('common.action'), fixed: 'right', width: 120 },
];
const currentLogMeta = computed(() => (isLogTab(activeTab.value) ? logMeta[activeTab.value] : logMeta.system));
const logTableColumns = computed(() => (isLogTab(activeTab.value) ? baseLogColumns[activeTab.value] : []));
const isUpstreamLogTab = computed(() => activeTab.value === 'upstream');
const upstreamLogGroups = computed<RecordRow[]>(() => {
  if (!isUpstreamLogTab.value) return [];

  if (logRows.value.some((row) => Array.isArray(row.logs))) {
    return logRows.value.map((row) => {
      const logs = Array.isArray(row.logs) ? (row.logs as RecordRow[]) : [row];
      return {
        ...row,
        logs,
        log_count: Number(row.log_count || logs.length),
      };
    });
  }

  const groups = new Map<string, RecordRow>();
  for (const row of logRows.value) {
    const ticketId = String(row.ticket_id || row.id || 'unknown');
    const groupKey = `ticket-${ticketId}`;
    const current = groups.get(groupKey);
    if (current) {
      (current.logs as RecordRow[]).push(row);
      current.log_count = Number(current.log_count || 0) + 1;
      continue;
    }

    groups.set(groupKey, {
      ...row,
      id: groupKey,
      group_key: groupKey,
      logs: [row],
      log_count: 1,
    });
  }

  return [...groups.values()];
});
const displayLogRows = computed(() => (isUpstreamLogTab.value ? upstreamLogGroups.value : logRows.value));
const isTextLog = computed(
  () =>
    activeTab.value === 'system' ||
    activeTab.value === 'runtime' ||
    activeTab.value === 'tasks' ||
    activeTab.value === 'upstream-uploads',
);
const keywordPlaceholder = computed(() => currentLogMeta.value.keyword);
const statusPlaceholder = computed(() => {
  if (activeTab.value === 'api') return t('logs.index.statusPlaceholderAllCodes');
  if (activeTab.value === 'tasks') return t('logs.index.statusPlaceholderAllTaskStatuses');
  if (activeTab.value === 'upstream') return t('logs.index.statusPlaceholderAllForwardStatuses');
  return t('logs.index.statusPlaceholderAllSendStatuses');
});
const taskStatusOptions = [
  { label: t('logs.status.success'), value: 'success' },
  { label: t('logs.status.failed'), value: 'failed' },
  { label: t('logs.status.skipped'), value: 'skipped' },
];
const upstreamStatusOptions = [
  { label: t('logs.forward.pending'), value: 'pending' },
  { label: t('logs.forward.sending'), value: 'sending' },
  { label: t('logs.forward.delivered'), value: 'delivered' },
  { label: t('logs.forward.failed'), value: 'failed' },
  { label: t('logs.forward.notForwarded'), value: 'skipped' },
];
const statusOptions = computed(() => {
  if (activeTab.value === 'api') return httpStatusOptions;
  if (activeTab.value === 'tasks') return taskStatusOptions;
  if (activeTab.value === 'upstream') return upstreamStatusOptions;
  return notifyStatusOptions;
});
const drawerSize = computed(() => (isMobile.value ? '100%' : '700px'));
const scheduleTasks = computed(() => asArray(scheduleOverview.value.tasks));
const scheduleSystemTasks = computed(() =>
  scheduleTasks.value.filter((task) => scheduleTaskSourceType(task) === 'system'),
);
const scheduleThirdPartyTasks = computed(() =>
  scheduleTasks.value.filter((task) => scheduleTaskSourceType(task) === 'third_party'),
);
const scheduleSourceOptions = computed(() => [
  { label: t('logs.index.scheduleSourceAll', { count: scheduleTasks.value.length }), value: 'all' },
  { label: t('logs.index.scheduleSourceSystem', { count: scheduleSystemTasks.value.length }), value: 'system' },
  {
    label: t('logs.index.scheduleSourceThirdParty', { count: scheduleThirdPartyTasks.value.length }),
    value: 'third_party',
  },
]);
const scheduleTaskGroups = computed(() => [
  {
    key: 'system',
    title: t('logs.index.systemTasks'),
    description: t('logs.schedule.coreAutomation'),
    theme: 'primary' as const,
    tasks: scheduleSystemTasks.value,
  },
  {
    key: 'third_party',
    title: t('logs.index.thirdPartyTasks'),
    description: t('logs.schedule.pluginAddonHook'),
    theme: 'warning' as const,
    tasks: scheduleThirdPartyTasks.value,
  },
]);
const visibleScheduleTaskGroups = computed(() => {
  if (scheduleSourceFilter.value === 'all') return scheduleTaskGroups.value;
  return scheduleTaskGroups.value.filter((group) => group.key === scheduleSourceFilter.value);
});
const scheduleEnvironment = computed(() => toRecord(scheduleOverview.value.environment));
const scheduleMutex = computed(() => toRecord(scheduleEnvironment.value.schedule_mutex));
const scheduleAutomationConfig = computed(() => toRecord(scheduleEnvironment.value.automation_config));
const scheduleEnvAlerts = computed(() => {
  const env = scheduleEnvironment.value;
  if (Object.keys(env).length === 0) return [];
  const alerts: Array<{
    key: string;
    label: string;
    value: string;
    detail?: string;
    theme: 'success' | 'warning' | 'danger';
  }> = [];

  const mutex = scheduleMutex.value;
  const mutexEnabled = Boolean(mutex.enabled);
  alerts.push({
    key: 'mutex',
    label: t('logs.schedule.mutex'),
    value: mutexEnabled ? t('logs.index.scheduleMutexEnabled') : t('logs.index.scheduleMutexDegraded'),
    detail: mutexEnabled
      ? String(mutex.mode || 'without_overlapping')
      : t('logs.index.scheduleMutexDetail', {
          reason: String(mutex.reason || t('logs.index.scheduleUnknownReason')),
          cacheStore: String(mutex.cache_store || t('logs.index.scheduleUnknownCache')),
          osFamily: String(mutex.os_family || t('logs.index.scheduleUnknownOs')),
        }),
    theme: mutexEnabled ? 'success' : 'warning',
  });

  const businessQueue = String(env.business_queue || '');
  const automationQueue = String(env.automation_queue || '');
  if (businessQueue && automationQueue) {
    alerts.push({
      key: 'queue_isolation',
      label: t('logs.schedule.queueIsolation'),
      value: t('logs.index.scheduleQueueIsolated'),
      detail: t('logs.index.scheduleQueueDetail', { business: businessQueue, automation: automationQueue }),
      theme: 'success',
    });
  }

  const pendingJobs = env.pending_jobs;
  if (typeof pendingJobs === 'number') {
    alerts.push({
      key: 'pending_jobs',
      label: t('logs.schedule.pendingJobs'),
      value: String(pendingJobs),
      detail: env.queue_runtime_mode
        ? t('logs.index.scheduleQueueMode', { mode: String(env.queue_runtime_mode) })
        : undefined,
      theme: pendingJobs > 50 ? 'danger' : pendingJobs > 10 ? 'warning' : 'success',
    });
  }

  const failedJobs = env.failed_jobs;
  if (typeof failedJobs === 'number') {
    alerts.push({
      key: 'failed_jobs',
      label: t('logs.schedule.failedJobs'),
      value: String(failedJobs),
      detail: failedJobs > 0 ? t('logs.index.scheduleFailedJobsDetail') : undefined,
      theme: failedJobs > 0 ? 'danger' : 'success',
    });
  }

  const automationStatus = String(scheduleAutomationConfig.value.status || 'loaded');
  if (automationStatus !== 'loaded') {
    alerts.push({
      key: 'automation_config',
      label: t('logs.schedule.automationConfig'),
      value: automationStatus === 'fallback_default' ? t('logs.index.scheduleAutomationFallback') : automationStatus,
      detail: String(scheduleAutomationConfig.value.fallback_reason || t('logs.index.scheduleAutomationReadFailed')),
      theme: 'warning',
    });
  }

  return alerts;
});
const canViewSchedules = computed(() => hasPermission(AdminPermissions.SCHEDULE_VIEW));
const canTriggerSchedules = computed(() => hasPermission(AdminPermissions.SCHEDULE_TRIGGER));
const canManageQueueJobs = computed(() => hasPermission(AdminPermissions.SCHEDULE_QUEUE_MANAGE));
const queueDrawerVisible = ref(false);
const queueDrawerQueue = ref<'pending' | 'failed'>('failed');

// 环境卡片里的待执行/失败任务计数同时是队列抽屉的入口；没有管理权限时纯展示
function isQueueEnvKey(key: string) {
  return key === 'pending_jobs' || key === 'failed_jobs';
}

function openQueueDrawer(target: 'pending' | 'failed') {
  queueDrawerQueue.value = target;
  queueDrawerVisible.value = true;
}

function openQueueDrawerFromEnv(key: string) {
  if (!canManageQueueJobs.value || !isQueueEnvKey(key)) return;
  openQueueDrawer(key === 'pending_jobs' ? 'pending' : 'failed');
}

const canManageLogCleanup = computed(() => hasPermission(AdminPermissions.LOG_MANAGE));
const cleanupSubmitDisabled = computed(
  () =>
    cleanupSubmitting.value ||
    !canManageLogCleanup.value ||
    !cleanupForm.type ||
    !Number.isFinite(Number(cleanupForm.keep_days)) ||
    Number(cleanupForm.keep_days) < 1 ||
    cleanupForm.confirm_text.trim() !== '立即清理',
);
const cleanupTypes = computed(() => asArray(cleanupOverview.value.supported_cleanup_types));
const databaseCards = computed(() => {
  const database = toRecord(cleanupOverview.value.database);
  return [
    { key: 'sms', label: t('logs.index.databaseSms'), value: numberText(database.sms) },
    { key: 'email', label: t('logs.index.databaseEmail'), value: numberText(database.email) },
    { key: 'api', label: t('logs.index.databaseApi'), value: numberText(database.api) },
    { key: 'admin_login', label: t('logs.index.databaseAdminLogin'), value: numberText(database.admin_login) },
    { key: 'schedule_run', label: t('logs.index.databaseScheduleRun'), value: numberText(database.schedule_run) },
    { key: 'ticket_upstream', label: t('logs.index.databaseTicketPush'), value: numberText(database.ticket_upstream) },
  ];
});
const fileCards = computed(() => {
  const file = toRecord(cleanupOverview.value.file);
  return [
    {
      key: 'exists',
      label: t('logs.index.fileStatus'),
      value: file.exists ? t('logs.index.fileExists') : t('logs.index.fileMissing'),
    },
    { key: 'size_bytes', label: t('logs.index.fileSize'), value: formatBytes(file.size_bytes) },
    { key: 'task_log_count', label: t('logs.index.taskLogCount'), value: numberText(file.task_log_count) },
    {
      key: 'runtime_log_count',
      label: t('logs.index.runtimeLogCount'),
      value: numberText(file.runtime_log_count ?? file.system_log_count),
    },
  ];
});
const affectedCountText = computed(() => {
  const affected = Object.values(toRecord(lastCleanupResult.value?.affected));
  return affected.reduce((total: number, value) => total + Number(value || 0), 0);
});

function resolveTabValue(value: unknown): LogsTab | null {
  const tab = Array.isArray(value) ? value[0] : value;
  return validTabs.includes(tab as LogsTab) ? (tab as LogsTab) : null;
}

function resolveRouteTab(): LogsTab {
  return resolveTabValue(route.query.tab) || resolveTabValue(route.meta.logTab) || 'system';
}

function isLogTab(value: LogsTab): value is LogTab {
  return [
    'system',
    'runtime',
    'admin-logins',
    'api',
    'sms',
    'email',
    'tasks',
    'gateway',
    'upstream',
    'upstream-uploads',
  ].includes(value);
}

function showFilter(name: string) {
  return currentLogMeta.value.filters.includes(name);
}

function refreshCurrentTab() {
  if (isLogTab(activeTab.value)) return loadLogs();
  if (activeTab.value === 'schedules') return loadScheduleOverview();
  return loadCleanupOverview();
}

function buildLogParams(): LogListParams {
  const params: LogListParams = {
    page: logPagination.page,
    page_size: logPagination.page_size,
  };
  for (const key of currentLogMeta.value.filters) {
    if (key === 'date') {
      if (filters.start_date.trim()) params.start_date = filters.start_date.trim();
      if (filters.end_date.trim()) params.end_date = filters.end_date.trim();
      continue;
    }
    const value = filters[key as keyof typeof filters];
    if (value !== '' && value !== null && value !== undefined) {
      (params as Record<string, unknown>)[key] = typeof value === 'string' ? value.trim() : value;
    }
  }
  return params;
}

function applyRouteFilterQuery() {
  for (const key of currentLogMeta.value.filters) {
    if (key === 'date') continue;
    const queryValue = route.query[key];
    if (queryValue === undefined) continue;

    const normalizedValue = Array.isArray(queryValue) ? queryValue[0] : queryValue;
    if (normalizedValue !== null && key in filters) {
      (filters as Record<LogFilterKey, string | number>)[key as LogFilterKey] = String(normalizedValue);
    }
  }
}

const logRequestSeq = ref(0);

async function loadLogs() {
  if (!isLogTab(activeTab.value)) return;
  logLoading.value = true;
  const seq = ++logRequestSeq.value;
  try {
    const params = buildLogParams();
    const response = await requestLogList(activeTab.value, params);
    if (seq !== logRequestSeq.value) return;
    logRows.value = response.list || [];
    expandedLogGroupKeys.value = [];
    logPagination.total = Number(response.total || 0);
    logPagination.page = Number(response.page || logPagination.page);
    logPagination.page_size = Number(response.page_size || logPagination.page_size);
  } catch (error) {
    if (seq !== logRequestSeq.value) return;
    logRows.value = [];
    logPagination.total = 0;
    MessagePlugin.error(errorMessage(error, t('logs.index.loadLogFailed', { title: currentLogMeta.value.title })));
  } finally {
    if (seq === logRequestSeq.value) {
      logLoading.value = false;
    }
  }
}

function requestLogList(tab: LogTab, params: LogListParams): Promise<PaginatedList> {
  const map = {
    system: adminApi.logs.system,
    runtime: adminApi.logs.runtime,
    'admin-logins': adminApi.logs.adminLogins,
    api: adminApi.logs.api,
    sms: adminApi.logs.sms,
    email: adminApi.logs.email,
    tasks: adminApi.logs.tasks,
    gateway: adminApi.logs.gateway,
    upstream: adminApi.logs.upstream,
    'upstream-uploads': adminApi.logs.upstreamUploads,
  };
  return map[tab](params);
}

function handleLogSearch() {
  logPagination.page = 1;
  loadLogs();
}

function resetLogFilters(shouldLoad = true) {
  Object.assign(filters, {
    keyword: '',
    actor_keyword: '',
    description_keyword: '',
    ip_address: '',
    level: '',
    task_key: '',
    method: '',
    module: '',
    user_type: '',
    status: '',
    phone: '',
    email: '',
    start_date: '',
    end_date: '',
    gateway: '',
    gateway_key: '',
    driver_key: '',
    plugin_id: '',
    trace_id: '',
    action: '',
    result_status: '',
    actor_type: '',
    subject_type: '',
    ticket_id: '',
    ticket_reply_id: '',
    operation: '',
    event: '',
    reason_code: '',
    provider_key: '',
    supplier_id: '',
  });
  logPagination.page = 1;
  if (shouldLoad && isLogTab(activeTab.value)) loadLogs();
}

function handleLogPageChange(data: PageInfo) {
  logPagination.page = data.current;
  logPagination.page_size = data.pageSize;
  loadLogs();
}

function handleLogGroupExpand(keys: Array<string | number>) {
  if (isUpstreamLogTab.value) expandedLogGroupKeys.value = keys;
}

function upstreamGroupLogs(row: RecordRow): RecordRow[] {
  return Array.isArray(row.logs) ? (row.logs as RecordRow[]) : [row];
}

function isLogGroupExpanded(row: RecordRow) {
  return expandedLogGroupKeys.value.includes(String(row.id));
}

function toggleLogGroup(row: RecordRow) {
  const key = String(row.id);
  expandedLogGroupKeys.value = isLogGroupExpanded(row)
    ? expandedLogGroupKeys.value.filter((item) => String(item) !== key)
    : [...expandedLogGroupKeys.value, key];
}

async function loadScheduleOverview() {
  if (!canViewSchedules.value) return;
  scheduleLoading.value = true;
  try {
    scheduleOverview.value = { ...(await adminApi.schedules.overview()) };
  } catch (error) {
    scheduleOverview.value = { tasks: [], recent_logs: [] };
    MessagePlugin.error(errorMessage(error, t('logs.index.scheduleLoadFailed')));
  } finally {
    scheduleLoading.value = false;
  }
}

async function triggerTask(row: TableRowData) {
  if (!canTriggerSchedules.value) {
    MessagePlugin.warning(t('logs.schedule.triggerForbidden'));
    return;
  }

  const task = String(row.key || '');
  if (!task) return;
  triggeringKey.value = task;
  try {
    const response = await adminApi.schedules.trigger({ task });
    const modeText =
      {
        sync: t('logs.index.executionSync'),
        after_response: t('logs.index.executionAfterResponse'),
        queue: t('logs.index.executionQueue'),
      }[String(response.execution_mode || '').toLowerCase()] || t('logs.index.executionSubmitted');
    MessagePlugin.success(t('logs.index.triggerSuccess', { title: fieldValue(row.title || row.key), mode: modeText }));
    MessagePlugin.info(t('logs.schedule.runningTip'));
    await loadScheduleOverview();
  } catch (error) {
    MessagePlugin.error(
      errorMessage(error, t('logs.index.triggerFailed', { title: fieldValue(row.title || row.key) })),
    );
  } finally {
    triggeringKey.value = '';
  }
}

async function loadCleanupOverview() {
  cleanupLoading.value = true;
  try {
    cleanupOverview.value = await adminApi.logs.cleanupOverview();
    if (!cleanupForm.type) cleanupForm.type = String(cleanupTypes.value[0]?.value || 'sms');
  } catch (error) {
    cleanupOverview.value = { database: {}, file: {}, supported_cleanup_types: [] };
    MessagePlugin.error(errorMessage(error, t('logs.index.cleanupOverviewLoadFailed')));
  } finally {
    cleanupLoading.value = false;
  }
}

async function handleCleanup() {
  if (!canManageLogCleanup.value) {
    MessagePlugin.warning(t('logs.cleanup.forbidden'));
    return;
  }
  if (!cleanupForm.type) {
    MessagePlugin.warning(t('logs.index.selectCleanupType'));
    return;
  }
  if (!Number.isFinite(Number(cleanupForm.keep_days)) || Number(cleanupForm.keep_days) < 1) {
    MessagePlugin.warning(t('logs.cleanup.keepDaysPositive'));
    return;
  }
  if (cleanupForm.confirm_text.trim() !== '立即清理') {
    MessagePlugin.warning(t('logs.cleanup.confirmTextMismatch'));
    return;
  }
  cleanupSubmitting.value = true;
  try {
    lastCleanupResult.value = await adminApi.logs.cleanup({
      type: cleanupForm.type,
      keep_days: Number(cleanupForm.keep_days),
      confirm_text: cleanupForm.confirm_text.trim(),
    });
    cleanupForm.confirm_text = '';
    await loadCleanupOverview();
    MessagePlugin.success(t('logs.cleanup.done'));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('logs.index.cleanupFailed')));
  } finally {
    cleanupSubmitting.value = false;
  }
}

async function openDetail(row: TableRowData) {
  const detailRow =
    activeTab.value === 'upstream' && Array.isArray((row as RecordRow).logs)
      ? upstreamGroupLogs(row as RecordRow)[0]
      : (row as RecordRow);
  currentLog.value = detailRow;
  detailVisible.value = true;

  if (!isLogTab(activeTab.value) || detailRow.id === undefined || detailRow.id === null || detailRow.id === '') {
    return;
  }

  try {
    const detail = await adminApi.logs.detail(activeTab.value, detailRow.id as string | number);
    currentLog.value = { ...detailRow, ...detail };
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('logs.index.detailLoadFailed')));
  }
}

function closeDetailDrawer() {
  detailVisible.value = false;
  currentLog.value = null;
}

function primaryTitle(row: RecordRow) {
  if (activeTab.value === 'system') return fieldValue(row.actor_name || t('logs.index.actorSystem'));
  if (activeTab.value === 'admin-logins') return fieldValue(row.admin_username);
  if (activeTab.value === 'api') return fieldValue(row.actor_name || userTypeLabel(row.user_type));
  if (activeTab.value === 'sms') return fieldValue(row.phone);
  if (activeTab.value === 'email') return fieldValue(row.to_email);
  if (activeTab.value === 'tasks') return fieldValue(row.task_title || row.task_key);
  if (activeTab.value === 'upstream') return t('logs.index.ticketWithId', { id: fieldValue(row.ticket_id) });
  return fieldValue(row.id);
}

function primarySubText(row: RecordRow) {
  if (activeTab.value === 'system')
    return fieldValue(row.actor_type === 'system' ? t('logs.index.systemOperation') : `ID: ${row.actor_id || '-'}`);
  if (activeTab.value === 'admin-logins')
    return fieldValue(row.admin_nickname || row.actor_name || t('logs.index.noNickname'));
  if (activeTab.value === 'api') return userTypeLabel(row.user_type);
  if (activeTab.value === 'sms' || activeTab.value === 'email')
    return t('logs.index.sentAtWithTime', { time: formatDate(row.sent_at) });
  if (activeTab.value === 'tasks') return fieldValue(row.task_key);
  if (activeTab.value === 'upstream') return `${fieldValue(row.operation)} · ${fieldValue(row.event)}`;
  return '';
}

function messageText(row: RecordRow) {
  if (activeTab.value === 'email') return contentPreview(row.content);
  if (activeTab.value === 'system') return fieldValue(row.description);
  return fieldValue(row.message || row.content || row.reason_code);
}

function subjectText(row: RecordRow) {
  const type = fieldValue(row.subject_type);
  const id = fieldValue(row.subject_id);
  if (type === '-' && id === '-') return '-';
  if (id === '-') return type;
  if (type === '-') return id;
  return `${type} #${id}`;
}

function messageTag(row: RecordRow): string {
  const text = messageText(row);
  const match = text.match(/^\[([^\]]+)\]/);
  return match ? match[1] : '';
}

function messageBody(row: RecordRow): string {
  const text = messageText(row);
  return text.replace(/^\[[^\]]+\]\s*/, '');
}

type MessageSegment = { type: 'text'; text: string } | { type: 'id'; label: string; id: string };

const ID_PATTERNS: Array<{ label: string; regex: RegExp }> = [
  { label: 'Invoice ID', regex: /Invoice\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Order ID', regex: /Order\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Host ID', regex: /Host\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'User ID', regex: /User\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Service ID', regex: /Service\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Ticket ID', regex: /Ticket\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Payment ID', regex: /Payment\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Product ID', regex: /Product\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Supplier ID', regex: /Supplier\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Admin ID', regex: /Admin\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Transaction ID', regex: /Transaction\s*ID\s*[:：]\s*(\d+)/gi },
];

function parseMessageSegments(row: RecordRow): MessageSegment[] {
  const text = messageBody(row);
  if (!text) return [{ type: 'text', text: '-' }];

  const matches: Array<{ index: number; end: number; label: string; id: string }> = [];

  for (const pattern of ID_PATTERNS) {
    pattern.regex.lastIndex = 0;
    let match: RegExpExecArray | null = pattern.regex.exec(text);
    while (match !== null) {
      matches.push({
        index: match.index,
        end: match.index + match[0].length,
        label: pattern.label,
        id: match[1],
      });
      match = pattern.regex.exec(text);
    }
  }

  if (matches.length === 0) return [{ type: 'text', text }];

  matches.sort((a, b) => a.index - b.index);

  const segments: MessageSegment[] = [];
  let cursor = 0;

  for (const m of matches) {
    if (m.index > cursor) {
      segments.push({ type: 'text', text: text.slice(cursor, m.index) });
    }
    segments.push({ type: 'id', label: m.label, id: m.id });
    cursor = m.end;
  }

  if (cursor < text.length) {
    segments.push({ type: 'text', text: text.slice(cursor) });
  }

  return segments;
}

function copyId(label: string, id: string) {
  const value = `${label}:${id}`;
  navigator.clipboard.writeText(value).then(
    () => MessagePlugin.success(t('logs.index.copied', { value })),
    () => MessagePlugin.warning(t('logs.copyFailed')),
  );
  const routePath = routeForIdSegment(label, id);
  if (routePath) {
    router.push(routePath);
  }
}

function idSegmentTitle(seg: Extract<MessageSegment, { type: 'id' }>) {
  return routeForIdSegment(seg.label, seg.id)
    ? t('logs.index.clickCopyAndNavigate', { value: `${seg.label}:${seg.id}` })
    : t('logs.index.clickCopy', { value: `${seg.label}:${seg.id}` });
}

function routeForIdSegment(label: string, id: string) {
  const cleanId = String(id || '').trim();
  if (!cleanId) return '';
  const routes: Record<string, string> = {
    'Order ID': `/admin/finance/orders/${cleanId}`,
    'User ID': `/admin/users/${cleanId}`,
    'Ticket ID': `/admin/ticket-conversations/${cleanId}`,
    'Host ID': `/admin/services?id=${encodeURIComponent(cleanId)}`,
    'Service ID': `/admin/services?id=${encodeURIComponent(cleanId)}`,
    'Product ID': `/admin/products?product_id=${encodeURIComponent(cleanId)}`,
  };
  return routes[label] || '';
}

function statusLabel(status: unknown) {
  if (activeTab.value === 'admin-logins') return sourceLabel(status);
  const statusKey = String(status || '').toLowerCase();
  if (activeTab.value === 'tasks') {
    return String(
      { success: t('logs.status.success'), failed: t('logs.status.failed'), skipped: t('logs.status.skipped') }[
        statusKey
      ] || fieldValue(status),
    );
  }
  if (activeTab.value === 'upstream') {
    return String(
      {
        pending: t('logs.forward.pending'),
        sending: t('logs.forward.sending'),
        delivered: t('logs.forward.delivered'),
        failed: t('logs.forward.failed'),
        skipped: t('logs.forward.notForwarded'),
      }[statusKey] || fieldValue(status),
    );
  }
  return String(
    { success: t('logs.notify.sent'), failed: t('logs.notify.failed'), pending: t('logs.notify.pending') }[statusKey] ||
      fieldValue(status),
  );
}

function statusValue(row: RecordRow) {
  return activeTab.value === 'admin-logins' ? row.source : row.status;
}

/** 为移动端卡片提取状态值（兼容多种日志类型） */
function logStatusValue(row: RecordRow) {
  if (activeTab.value === 'admin-logins') return row.source;
  if (row.result_status) return row.result_status;
  return row.status;
}

/** 为移动端卡片提取核心消息文本 */
function logCardMessage(row: RecordRow) {
  const text = messageText(row);
  if (text) {
    const cleaned = String(text).replace(/\n+/g, ' ').trim();
    return cleaned.length > 160 ? `${cleaned.slice(0, 160)}…` : cleaned;
  }
  return primaryTitle(row) || primarySubText(row) || '-';
}

function statusTheme(status: unknown): TagTheme {
  if (activeTab.value === 'admin-logins') return String(status) === 'operation_log' ? 'success' : 'warning';
  const themes: Record<string, TagTheme> = {
    success: 'success',
    failed: 'danger',
    pending: 'warning',
    skipped: 'warning',
    delivered: 'success',
    sending: 'warning',
  };
  return themes[String(status || '').toLowerCase()] || 'default';
}

function levelTheme(level: unknown): TagTheme {
  const themes: Record<string, TagTheme> = {
    DEBUG: 'default',
    INFO: 'success',
    NOTICE: 'primary',
    WARNING: 'warning',
    ERROR: 'danger',
    CRITICAL: 'danger',
    ALERT: 'danger',
    EMERGENCY: 'danger',
    SUCCESS: 'success',
  };
  return themes[String(level || '').toUpperCase()] || 'default';
}

function httpStatusTheme(status: unknown): TagTheme {
  const code = Number(status || 0);
  if (code >= 500) return 'danger';
  if (code >= 400) return 'warning';
  if (code >= 200) return 'success';
  return 'default';
}

function userTypeLabel(value: unknown) {
  return (
    {
      admin: t('logs.index.actorAdmin'),
      client: t('logs.index.actorCustomer'),
      guest: t('logs.index.actorGuest'),
    }[String(value || '').toLowerCase()] || t('logs.index.actorSystem')
  );
}

function sourceLabel(value: unknown) {
  return (
    {
      operation_log: t('logs.source.operationLog'),
      admin_snapshot: t('logs.source.adminSnapshot'),
    }[String(value || '').toLowerCase()] || t('logs.source.unknown')
  );
}

function hasPermission(permission: string) {
  const permissions = userStore.userInfo?.permissions || [];
  return hasPermissionInList(permissions, permission);
}

function formatDate(value: unknown) {
  return formatDateTime(value);
}

function asArray(value: unknown): RecordRow[] {
  return Array.isArray(value) ? (value as RecordRow[]) : [];
}

function scheduleTaskSourceType(row: RecordRow) {
  return String(row.source_type || '').trim() === 'third_party' ? 'third_party' : 'system';
}

function scheduleTaskSourceTheme(row: RecordRow) {
  return scheduleTaskSourceType(row) === 'third_party' ? 'warning' : 'primary';
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

function numberText(value: unknown) {
  return Number(value || 0).toLocaleString('zh-CN');
}

function formatJson(value: unknown, fallback = '-') {
  if (value === null || value === undefined || value === '') return fallback;
  if (typeof value === 'string') {
    const text = value.trim();
    if (!text) return fallback;
    try {
      return JSON.stringify(JSON.parse(text), null, 2);
    } catch {
      return text;
    }
  }
  try {
    return JSON.stringify(value, null, 2);
  } catch {
    return fallback;
  }
}

function contentPreview(value: unknown) {
  const text = String(value ?? '')
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/gi, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  if (!text) return '-';
  return text.length > 96 ? `${text.slice(0, 96)}...` : text;
}

function formatBytes(value: unknown) {
  const size = Number(value || 0);
  if (!Number.isFinite(size) || size <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  let current = size;
  let unitIndex = 0;
  while (current >= 1024 && unitIndex < units.length - 1) {
    current /= 1024;
    unitIndex += 1;
  }
  return `${current.toFixed(current >= 10 || unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
}

function gatewayResultStatusLabel(value: unknown) {
  return (
    {
      success: t('logs.status.success'),
      failed: t('logs.status.failed'),
      pending: t('logs.status.processing'),
    }[String(value || '').toLowerCase()] ||
    fieldValue(value) ||
    t('logs.status.unknown')
  );
}

function gatewayLabel(value: unknown) {
  const key = String(value || '').toLowerCase();
  return gatewayOptions.find((item) => item.value === key)?.label || fieldValue(value);
}

function gatewayResultStatusTheme(value: unknown): TagTheme {
  const themes: Record<string, TagTheme> = {
    success: 'success',
    failed: 'danger',
    pending: 'warning',
  };
  return themes[String(value || '').toLowerCase()] || 'default';
}

function formatScheduleCycle(row: RecordRow) {
  const expression = String(row.expression || '').trim();
  if (!expression) return '--';
  if (expression === '* * * * *') return t('logs.index.cycleEveryMinute');
  if (expression === '0 * * * *') return t('logs.index.cycleEveryHour');
  const everyMinutesMatch = expression.match(/^\*\/(\d+) \* \* \* \*$/);
  if (everyMinutesMatch) return t('logs.index.cycleEveryNMinutes', { count: everyMinutesMatch[1] });
  const fixedMinuteHourlyMatch = expression.match(/^(\d{1,2}) \* \* \* \*$/);
  if (fixedMinuteHourlyMatch) return t('logs.index.cycleHourlyAtMinute', { minute: fixedMinuteHourlyMatch[1] });
  const dailyMatch = expression.match(/^(\d{1,2}) (\d{1,2}) \* \* \*$/);
  if (dailyMatch) {
    return t('logs.index.cycleDailyAt', {
      time: `${String(dailyMatch[2]).padStart(2, '0')}:${String(dailyMatch[1]).padStart(2, '0')}`,
    });
  }
  return t('logs.index.cycleCustom');
}

watch(
  () => [route.path, route.query.tab, route.meta.logTab],
  () => {
    const nextTab = resolveRouteTab();
    if (nextTab === activeTab.value) return;
    activeTab.value = nextTab;
    resetLogFilters(false);
    applyRouteFilterQuery();
    refreshCurrentTab();
  },
);

onMounted(() => {
  applyRouteFilterQuery();
  refreshCurrentTab();
});
</script>
