<template>
  <div class="notifications-page">
    <template v-if="isTemplateTab">
      <t-card :bordered="false" :loading="templatesLoading">
        <div class="template-toolbar">
          <div>
            <h2>{{ templateTitle }}</h2>
            <p>{{ templateDescription }}</p>
          </div>
          <t-radio-group v-if="templateChannel === 'email'" v-model="templateAudience" variant="default-filled">
            <t-radio-button v-for="item in templateAudienceOptions" :key="item.value" :value="item.value">
              {{ item.label }}
            </t-radio-button>
          </t-radio-group>
        </div>

        <div v-if="!isMobile" class="table-scroll">
          <t-table
            row-key="code"
            :data="filteredTemplates"
            :columns="templateColumns"
            hover
            table-layout="fixed"
            @row-click="openTemplate"
          >
            <template #name="{ row }">
              <div class="stack-cell">
                <strong>{{ row.name }}</strong>
                <span>{{ row.description }}</span>
              </div>
            </template>
            <template #code="{ row }">
              <t-space size="small">
                <t-tag variant="light">{{ row.code }}</t-tag>
                <t-tag :theme="row.audience === 'admin' ? 'warning' : 'success'" variant="light">
                  {{ row.audience === 'admin' ? $t('notifications.adminAudience') : $t('notifications.userAudience') }}
                </t-tag>
              </t-space>
            </template>
            <template #preview="{ row }">{{ row.preview }}</template>
            <template #subject="{ row }">{{
              row.channel === 'email' ? fieldValue(row.subject) : row.preview
            }}</template>
            <template #variables="{ row }">{{ row.variables.length }}</template>
            <template #templateType="{ row }">
              <t-tag :theme="row.channel === 'email' ? 'primary' : 'success'" variant="light">{{
                row.channel === 'email' ? $t('notifications.emailChannel') : $t('notifications.smsChannel')
              }}</t-tag>
            </template>
            <template #bodyType="{ row }">
              <t-tag :theme="row.isHtml ? 'primary' : 'default'" variant="light">{{
                row.isHtml ? 'HTML' : $t('notifications.textType')
              }}</t-tag>
            </template>
            <template #status="{ row }">
              <span class="template-status-switch" @click.stop>
                <t-switch
                  v-model="row.is_enabled"
                  :custom-value="[true, false]"
                  :label="[$t('notifications.enabled'), $t('notifications.disabled')]"
                  :loading="statusSavingKey === templateRowKey(row)"
                  @change="(value: unknown) => updateTemplateEnabled(row, value)"
                />
              </span>
            </template>
            <template #actions="{ row }">
              <t-space size="small">
                <t-button variant="text" @click.stop="openTestSend(row)">{{
                  $t('notifications.template.actionTestSend')
                }}</t-button>
                <t-button theme="primary" variant="text" @click.stop="openTemplate(row)">{{
                  $t('notifications.template.actionEdit')
                }}</t-button>
              </t-space>
            </template>
          </t-table>
        </div>

        <div v-else class="mobile-list">
          <article v-for="row in filteredTemplates" :key="row.code" class="template-mobile-card">
            <div class="template-mobile-card__head">
              <strong>{{ row.name }}</strong>
              <t-tag variant="light">{{ row.code }}</t-tag>
            </div>
            <p>{{ row.description }}</p>
            <dl>
              <div>
                <dt>{{ row.channel === 'email' ? $t('notifications.dtSubject') : $t('notifications.dtBody') }}</dt>
                <dd>{{ row.channel === 'email' ? fieldValue(row.subject) : row.preview }}</dd>
              </div>
              <div>
                <dt>{{ $t('notifications.templateType') }}</dt>
                <dd>
                  {{ row.channel === 'email' ? $t('notifications.emailChannel') : $t('notifications.smsChannel') }}
                </dd>
              </div>
              <div>
                <dt>{{ $t('notifications.variableCount') }}</dt>
                <dd>{{ row.variables.length }}</dd>
              </div>
              <div>
                <dt>{{ $t('notifications.bodyType') }}</dt>
                <dd>{{ row.isHtml ? 'HTML' : $t('notifications.textType') }}</dd>
              </div>
              <div>
                <dt>{{ $t('notifications.sendStatus') }}</dt>
                <dd>
                  <t-switch
                    v-model="row.is_enabled"
                    :custom-value="[true, false]"
                    :label="[$t('notifications.enabled'), $t('notifications.disabled')]"
                    :loading="statusSavingKey === templateRowKey(row)"
                    @change="(value: unknown) => updateTemplateEnabled(row, value)"
                  />
                </dd>
              </div>
              <div v-if="row.channel === 'email'">
                <dt>{{ $t('notifications.audience') }}</dt>
                <dd>
                  {{ row.audience === 'admin' ? $t('notifications.adminAudience') : $t('notifications.userAudience') }}
                </dd>
              </div>
            </dl>
            <div class="template-mobile-card__actions">
              <t-button variant="outline" @click="openTestSend(row)">{{ $t('notifications.testSend') }}</t-button>
              <t-button theme="primary" variant="outline" @click="openTemplate(row)">{{
                $t('notifications.edit')
              }}</t-button>
            </div>
          </article>
        </div>
      </t-card>
    </template>

    <template v-else>
      <section class="api-layout">
        <t-card :bordered="false" class="api-sidebar">
          <template #title>{{ $t('notifications.api.categoryList') }}</template>
          <div class="api-meta-grid">
            <article>
              <span>{{ $t('notifications.apiInterface') }}</span
              ><strong>{{ apiMeta.total || apiItems.length }}</strong>
            </article>
            <article>
              <span>{{ $t('notifications.apiModule') }}</span
              ><strong>{{ apiMeta.moduleCount || apiModules.length }}</strong>
            </article>
            <article>
              <span>{{ $t('notifications.apiPermission') }}</span
              ><strong>{{ apiMeta.accessCounts?.permission || 0 }}</strong>
            </article>
            <article>
              <span>{{ $t('notifications.apiLogin') }}</span
              ><strong>{{ apiMeta.accessCounts?.auth || 0 }}</strong>
            </article>
          </div>
          <div class="api-category-list">
            <button type="button" :class="{ active: selectedApiModule === 'all' }" @click="selectedApiModule = 'all'">
              <span>{{ $t('notifications.allApis') }}</span>
              <strong>{{ apiItems.length }}</strong>
            </button>
            <button
              v-for="item in apiModules"
              :key="item.key"
              type="button"
              :class="{ active: selectedApiModule === item.key }"
              @click="selectedApiModule = item.key"
            >
              <span>{{ item.label }}</span>
              <strong>{{ item.count }}</strong>
            </button>
          </div>
        </t-card>

        <div class="api-main">
          <t-card :bordered="false">
            <div class="api-directory-head">
              <div>
                <h2>{{ $t('notifications.apiPageTitle') }}</h2>
                <p>
                  {{
                    $t('notifications.apiMeta', {
                      source: apiMeta.dataSource || '-',
                      generated: apiMeta.generatedAt || '-',
                      base: apiMeta.baseURL || '/api',
                    })
                  }}
                </p>
              </div>
              <t-button variant="outline" @click="resetApiFilters">{{ $t('notifications.resetFilters') }}</t-button>
            </div>
            <div class="api-filter-grid">
              <t-input v-model="apiFilters.keyword" clearable :placeholder="$t('notifications.searchApiPlaceholder')">
                <template #suffix-icon><search-icon /></template>
              </t-input>
              <t-select v-model="apiFilters.access" :placeholder="$t('notifications.accessLevel')">
                <t-option :label="$t('notifications.allLevels')" value="all" />
                <t-option :label="$t('notifications.publicAccess')" value="public" />
                <t-option :label="$t('notifications.authOnly')" value="auth" />
                <t-option :label="$t('notifications.permissionAccess')" value="permission" />
              </t-select>
              <t-select v-model="apiFilters.method" :placeholder="$t('notifications.requestMethod')">
                <t-option :label="$t('notifications.allMethods')" value="all" />
                <t-option v-for="item in methodOptions" :key="item" :label="item" :value="item" />
              </t-select>
              <t-select v-model="apiFilters.source" :placeholder="$t('notifications.frontendSource')">
                <t-option :label="$t('notifications.allSources')" value="all" />
                <t-option :label="$t('notifications.adminFrontend')" value="frontend-admin-v3" />
                <t-option :label="$t('notifications.userWww')" value="frontend-user-v3-www" />
                <t-option :label="$t('notifications.userConsole')" value="frontend-user-v4-console" />
                <t-option :label="$t('notifications.untracked')" value="untracked" />
              </t-select>
            </div>
          </t-card>

          <t-card :bordered="false">
            <template #title>{{ $t('notifications.apiDetail') }}</template>
            <template #subtitle>Showing {{ filteredApiItems.length }} / {{ apiItems.length }}</template>
            <div class="table-scroll">
              <t-table row-key="id" :data="filteredApiItems" :columns="apiColumns" hover table-layout="fixed">
                <template #category="{ row }">
                  <div class="stack-cell">
                    <strong>{{ row.scopeLabel }} / {{ row.moduleLabel }}</strong>
                    <span>{{ row.module }}</span>
                  </div>
                </template>
                <template #method="{ row }">
                  <t-tag :theme="methodTheme(row.method)" variant="light">{{ row.method }}</t-tag>
                </template>
                <template #path="{ row }">
                  <div class="stack-cell">
                    <strong>{{ row.callPath }}</strong>
                    <span>{{ row.backendPath }}</span>
                  </div>
                </template>
                <template #access="{ row }">
                  <div class="tag-stack">
                    <t-tag :theme="accessTheme(row.access)" variant="light">{{ row.accessLabel }}</t-tag>
                    <t-tag v-if="row.permission" variant="light">{{ row.permission }}</t-tag>
                    <span v-else>{{ $t('notifications.api.noExtraPermission') }}</span>
                  </div>
                </template>
                <template #source="{ row }">
                  <div class="tag-stack">
                    <t-tag v-for="label in row.sourceAppLabels" :key="label" variant="light">{{ label }}</t-tag>
                    <span v-if="!row.sourceAppLabels.length">{{ $t('notifications.api.noFrontendCall') }}</span>
                  </div>
                </template>
                <template #handler="{ row }">
                  <div class="stack-cell">
                    <strong>{{ row.handler }}</strong>
                    <span>{{ $t('notifications.sourceMatches', { count: row.sourceFiles.length }) }}</span>
                  </div>
                </template>
                <template #actions="{ row }">
                  <t-button theme="primary" variant="text" @click="copyApiPath(row.backendPath)">{{
                    $t('notifications.api.actionCopy')
                  }}</t-button>
                </template>
              </t-table>
            </div>
          </t-card>
        </div>
      </section>
    </template>

    <t-dialog
      v-model:visible="testSendVisible"
      :header="testSendDialogTitle"
      width="560px"
      :confirm-btn="{ content: $t('notifications.confirmSend'), loading: testSending }"
      @confirm="submitTestSend"
    >
      <div class="template-test-dialog">
        <div v-if="testSendTemplate" class="template-test-target">
          <strong>{{ testSendTemplate.name }}</strong>
          <span
            >{{
              testSendTemplate.channel === 'sms' ? $t('notifications.templateSms') : $t('notifications.templateEmail')
            }}
            · {{ testSendTemplate.code }}</span
          >
        </div>
        <t-form label-align="top">
          <t-form-item
            :label="
              testSendTemplate?.channel === 'sms'
                ? $t('notifications.recipientPhone')
                : $t('notifications.recipientEmail')
            "
          >
            <t-input
              v-model="testSendRecipient"
              :placeholder="testSendPlaceholder"
              clearable
              :type="testSendTemplate?.channel === 'sms' ? 'tel' : 'text'"
              @input="testSendResult = null"
            />
          </t-form-item>
        </t-form>

        <div
          v-if="testSendResult"
          class="template-test-feedback"
          :class="`template-test-feedback--${testSendResult.status}`"
        >
          <strong>{{ testSendSummaryText }}</strong>
          <span>{{
            $t('notifications.successFailed', {
              success: testSendResult.success_count,
              failed: testSendResult.failed_count,
            })
          }}</span>
        </div>
        <div v-if="testSendResult?.results?.length" class="template-test-results">
          <div
            v-for="item in testSendResult.results"
            :key="`${item.recipient}:${item.status}`"
            class="template-test-result-row"
          >
            <span>{{ item.recipient }}</span>
            <t-tag :theme="item.status === 'success' ? 'success' : 'danger'" variant="light">
              {{ item.status === 'success' ? $t('notifications.success') : $t('notifications.failed') }}
            </t-tag>
            <small v-if="item.error">{{ item.error }}</small>
          </div>
        </div>
      </div>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { SearchIcon } from 'tdesign-icons-vue-next';
import type { PrimaryTableCol, TableRowData, TagProps } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import type { NotificationTemplateItem, NotificationTemplateTestSendResponse, SettingItem } from '@/api/admin';
import { adminApi } from '@/api/admin';
import apiCatalogData from '@/data/apiCatalog.generated.json';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { t } from '@/locales';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminNotifications' });

type NotificationTab = 'email-templates' | 'sms-templates' | 'api-directory';
type ThemeType = NonNullable<TagProps['theme']>;
type TemplateChannel = 'email' | 'sms';
type TemplateAudience = 'user' | 'admin';

interface TemplateRow extends NotificationTemplateItem {
  subject: string;
  preview: string;
  isHtml: boolean;
  is_enabled: boolean;
}

interface ApiCatalogItem extends Record<string, unknown> {
  id: string;
  scope: string;
  scopeLabel: string;
  module: string;
  moduleLabel: string;
  method: string;
  methods?: string[];
  callPath: string;
  backendPath: string;
  access: string;
  accessLabel: string;
  permission?: string;
  handler?: string;
  sourceApps: string[];
  sourceAppLabels: string[];
  sourceFiles: string[];
}

interface ApiCatalogMeta {
  total?: number;
  moduleCount?: number;
  dataSource?: string;
  generatedAt?: string;
  baseURL?: string;
  accessCounts?: Record<string, number>;
}

const route = useRoute();
const router = useRouter();
const templateAudience = ref<TemplateAudience>(normalizeTemplateAudience(route.query.tab) || 'user');
const activeTab = ref<NotificationTab>(resolveRouteTab());
const templatesLoading = ref(false);
const statusSavingKey = ref('');
const testSendVisible = ref(false);
const testSending = ref(false);
const testSendRecipient = ref('');
const testSendTemplate = ref<TemplateRow | null>(null);
const testSendResult = ref<NotificationTemplateTestSendResponse | null>(null);
const settingsMap = ref<Record<string, unknown>>({});
const templateSummaries = ref<NotificationTemplateItem[]>([]);
const selectedApiModule = ref('all');
const apiFilters = reactive({
  keyword: '',
  access: 'all',
  method: 'all',
  source: 'all',
});

const apiCatalog = apiCatalogData as { meta?: ApiCatalogMeta; items?: ApiCatalogItem[] };
const apiMeta = apiCatalog.meta || {};
const apiItems = (apiCatalog.items || []).map((item) => ({
  ...item,
  sourceApps: item.sourceApps || [],
  sourceAppLabels: item.sourceAppLabels || [],
  sourceFiles: item.sourceFiles || [],
}));

const isMobile = useMediaQuery('(max-width: 768px)');
const isTemplateTab = computed(() => activeTab.value === 'email-templates' || activeTab.value === 'sms-templates');
const templateChannel = computed<TemplateChannel>(() => (activeTab.value === 'sms-templates' ? 'sms' : 'email'));
const templateTitle = computed(() =>
  templateChannel.value === 'sms' ? t('notifications.templateSms') : t('notifications.templateEmail'),
);
const templateDescription = computed(() => {
  if (templateChannel.value === 'sms') {
    return t('notifications.smsDescription');
  }

  return t('notifications.emailDescription');
});
const testSendDialogTitle = computed(() =>
  testSendTemplate.value?.channel === 'sms' ? t('notifications.testSendSms') : t('notifications.testSendEmail'),
);
const testSendPlaceholder = computed(() =>
  testSendTemplate.value?.channel === 'sms'
    ? t('notifications.smsRecipientPlaceholder')
    : t('notifications.emailRecipientPlaceholder'),
);
const testSendSummaryText = computed(() => {
  if (!testSendResult.value) return '';
  if (testSendResult.value.status === 'success') return t('notifications.testSendSuccess');
  if (testSendResult.value.status === 'partial_failed') return t('notifications.testSendPartialFailed');
  return t('notifications.testSendFailed');
});
const templateAudienceOptions = computed(() => [
  {
    label: t('notifications.userTemplateCount', {
      count: templateRows.value.filter((item) => item.audience === 'user').length,
    }),
    value: 'user',
  },
  {
    label: t('notifications.adminTemplateCount', {
      count: templateRows.value.filter((item) => item.audience === 'admin').length,
    }),
    value: 'admin',
  },
]);
const templateRows = computed<TemplateRow[]>(() =>
  templateSummaries.value.map((template) => {
    const subjectKey = stringValue(template.setting_keys?.subject) || `email_template_subject_${template.code}`;
    const contentKey =
      stringValue(template.setting_keys?.content) || `${template.channel}_template_content_${template.code}`;
    const subject = stringValue(settingsMap.value[subjectKey]) || stringValue(template.subject);
    const content = stringValue(settingsMap.value[contentKey]) || template.content;
    const preview = stripHtml(content);
    return {
      ...template,
      subject,
      preview: preview ? (preview.length > 88 ? `${preview.slice(0, 88)}...` : preview) : '-',
      isHtml: /<[a-z][a-z0-9]*(?:\s|>)/i.test(content.trim()),
      is_enabled: toBooleanValue(template.is_enabled ?? true),
    };
  }),
);
const filteredTemplates = computed(() =>
  templateChannel.value === 'email'
    ? templateRows.value.filter((item) => item.audience === templateAudience.value)
    : templateRows.value,
);
const apiModules = computed(() => {
  const moduleMap = new Map<string, { key: string; label: string; count: number }>();
  apiItems.forEach((item) => {
    const key = `${item.scope}:${item.module}`;
    const current = moduleMap.get(key) || { key, label: `${item.scopeLabel} / ${item.moduleLabel}`, count: 0 };
    current.count += 1;
    moduleMap.set(key, current);
  });
  return Array.from(moduleMap.values()).sort((left, right) => right.count - left.count);
});
const methodOptions = computed(() => Array.from(new Set(apiItems.map((item) => item.method))).sort());
const filteredApiItems = computed(() => {
  const keyword = apiFilters.keyword.trim().toLowerCase();
  return apiItems.filter((item) => {
    if (selectedApiModule.value !== 'all' && `${item.scope}:${item.module}` !== selectedApiModule.value) return false;
    if (apiFilters.access !== 'all' && item.access !== apiFilters.access) return false;
    if (apiFilters.method !== 'all' && item.method !== apiFilters.method) return false;
    if (apiFilters.source === 'untracked' && item.sourceApps.length > 0) return false;
    if (
      apiFilters.source !== 'all' &&
      apiFilters.source !== 'untracked' &&
      !item.sourceApps.includes(apiFilters.source)
    ) {
      return false;
    }
    if (!keyword) return true;
    return [
      item.scopeLabel,
      item.moduleLabel,
      item.method,
      item.callPath,
      item.backendPath,
      item.accessLabel,
      item.permission,
      item.handler,
      ...item.sourceAppLabels,
      ...item.sourceFiles,
    ]
      .join(' ')
      .toLowerCase()
      .includes(keyword);
  });
});

const templateColumns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'name', title: t('pages.notification.apiTemplate'), minWidth: 260 },
  { colKey: 'code', title: t('pages.notification.apiCodeObject'), minWidth: 180 },
  { colKey: 'subject', title: t('pages.notification.apiCurrentSubject'), minWidth: 220, ellipsis: true },
  { colKey: 'preview', title: t('pages.notification.apiBodyPreview'), minWidth: 260, ellipsis: true },
  { colKey: 'variables', title: t('pages.notification.apiVariableCount'), width: 90 },
  { colKey: 'templateType', title: t('pages.notification.apiTemplateType'), width: 100 },
  { colKey: 'bodyType', title: t('pages.notification.apiBodyType'), width: 110 },
  { colKey: 'status', title: t('pages.notification.apiSendStatus'), width: 130 },
  { colKey: 'actions', title: t('common.action'), fixed: 'right', width: 150 },
];
const apiColumns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'category', title: t('pages.notification.apiCategory'), minWidth: 220 },
  { colKey: 'method', title: t('pages.notification.apiMethod'), width: 100 },
  { colKey: 'path', title: t('pages.notification.apiPath'), minWidth: 340 },
  { colKey: 'access', title: t('pages.notification.apiAccess'), minWidth: 210 },
  { colKey: 'source', title: t('pages.notification.apiSource'), minWidth: 170 },
  { colKey: 'handler', title: t('pages.notification.apiHandler'), minWidth: 220 },
  { colKey: 'actions', title: t('common.action'), fixed: 'right', width: 90 },
];

function normalizeTab(value: unknown): NotificationTab {
  const tab = Array.isArray(value) ? value[0] : value;
  if (tab === 'admin' || tab === 'user') return 'email-templates';
  if (tab === 'api-directory' || tab === 'sms-templates') return tab;
  return 'email-templates';
}

function resolveRouteTab(): NotificationTab {
  const tab = Array.isArray(route.query.tab) ? route.query.tab[0] : route.query.tab;
  if (tab === 'api-directory' || tab === 'sms-templates' || tab === 'admin' || tab === 'user') return normalizeTab(tab);
  return normalizeTab(route.meta.notificationTab);
}

function normalizeTemplateAudience(value: unknown): TemplateAudience | null {
  const tab = Array.isArray(value) ? value[0] : value;
  if (tab === 'admin' || tab === 'user') return tab;
  return null;
}

function refreshCurrentTab() {
  if (isTemplateTab.value) return loadTemplates();
  return undefined;
}

async function loadTemplates() {
  templatesLoading.value = true;
  try {
    const [templateResponse, response] = await Promise.all([
      adminApi.settings.notificationTemplates({ channel: templateChannel.value }),
      adminApi.settings.list({ group: 'notification' }),
    ]);
    templateSummaries.value = templateResponse.list || [];
    settingsMap.value = normalizeSettings(response);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('notifications.loadTemplatesFailed', { title: templateTitle.value })));
  } finally {
    templatesLoading.value = false;
  }
}

function openTemplate(row: TemplateRow | TableRowData) {
  const code = String((row as TemplateRow).code || '');
  if (!code) return;
  const channel = ((row as TemplateRow).channel || templateChannel.value) === 'sms' ? 'sms' : 'email';
  const audience = channel === 'email' ? templateAudience.value : 'user';
  router.push({ path: `/admin/notifications/${channel}-templates/${code}`, query: { tab: audience } });
}

function openTestSend(row: TemplateRow | TableRowData) {
  const template = row as TemplateRow;
  if (!template?.code) return;

  testSendTemplate.value = template;
  testSendRecipient.value = '';
  testSendResult.value = null;
  testSendVisible.value = true;
}

async function submitTestSend() {
  const template = testSendTemplate.value;
  if (!template) return;

  const recipient = normalizeRecipient(testSendRecipient.value, template.channel);
  if (!recipient) {
    MessagePlugin.warning(
      template.channel === 'sms'
        ? t('notifications.recipientPhoneRequired')
        : t('notifications.recipientEmailRequired'),
    );
    return;
  }

  testSending.value = true;
  try {
    const result = await adminApi.settings.testNotificationTemplateSend({
      channel: template.channel,
      code: template.code,
      recipient,
    });
    testSendResult.value = result;

    if (result.failed_count > 0) {
      MessagePlugin.warning(
        result.status === 'partial_failed'
          ? t('notifications.testSendPartialFailed')
          : t('notifications.testSendFailed'),
      );
    } else {
      MessagePlugin.success(t('pages.notification.testSent'));
    }
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('notifications.testSendFailed')));
  } finally {
    testSending.value = false;
  }
}

function resetApiFilters() {
  selectedApiModule.value = 'all';
  apiFilters.keyword = '';
  apiFilters.access = 'all';
  apiFilters.method = 'all';
  apiFilters.source = 'all';
}

async function copyApiPath(path: string) {
  try {
    await navigator.clipboard?.writeText(path);
    MessagePlugin.success(t('pages.notification.pathCopied'));
  } catch {
    MessagePlugin.error(t('pages.notification.copyFailed'));
  }
}

async function updateTemplateEnabled(row: TemplateRow | TableRowData, value: unknown) {
  const template = row as TemplateRow;
  const key = templateRowKey(template);
  const previous = toBooleanValue(
    templateSummaries.value.find((item) => item.channel === template.channel && item.code === template.code)
      ?.is_enabled ?? template.is_enabled,
  );
  const enabled = toBooleanValue(value);

  setTemplateEnabled(template, enabled);
  statusSavingKey.value = key;
  try {
    await adminApi.settings.save({
      group: 'notification',
      settings: {
        [templateEnabledSettingKey(template)]: enabled ? 1 : 0,
      },
    });
    MessagePlugin.success(enabled ? t('notifications.templateEnabled') : t('notifications.templateDisabled'));
  } catch (error) {
    setTemplateEnabled(template, previous);
    MessagePlugin.error(errorMessage(error, t('notifications.saveTemplateStatusFailed')));
  } finally {
    if (statusSavingKey.value === key) statusSavingKey.value = '';
  }
}

function setTemplateEnabled(template: Pick<TemplateRow, 'channel' | 'code'>, enabled: boolean) {
  templateSummaries.value = templateSummaries.value.map((item) =>
    item.channel === template.channel && item.code === template.code ? { ...item, is_enabled: enabled } : item,
  );
}

function templateEnabledSettingKey(template: Pick<NotificationTemplateItem, 'channel' | 'code' | 'setting_keys'>) {
  return stringValue(template.setting_keys?.enabled) || `${template.channel}_template_enabled_${template.code}`;
}

function templateRowKey(row: Pick<NotificationTemplateItem, 'channel' | 'code'>) {
  return `${row.channel}:${row.code}`;
}

function normalizeRecipient(value: string, channel: TemplateChannel) {
  const recipient = value.trim();
  if (channel === 'sms') return recipient.replace(/[\s-]+/g, '');
  return recipient;
}

function normalizeSettings(response: SettingItem[] | Record<string, unknown>) {
  const items = extractSettingItems(response);
  if (items.length) return Object.fromEntries(items.map((item) => [item.key, item.value]));

  const record = toRecord(response);
  return record;
}

function extractSettingItems(response: SettingItem[] | Record<string, unknown>) {
  if (Array.isArray(response)) return response;

  const record = toRecord(response);
  return Array.isArray(record.list) ? (record.list as SettingItem[]) : [];
}

function methodTheme(method: string): ThemeType {
  if (method === 'GET') return 'primary';
  if (method === 'POST') return 'success';
  if (method === 'PUT') return 'warning';
  if (method === 'DELETE') return 'danger';
  return 'default';
}

function accessTheme(access: string): ThemeType {
  if (access === 'permission') return 'warning';
  if (access === 'auth') return 'success';
  return 'default';
}

function stripHtml(value: string) {
  return value
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/gi, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function stringValue(value: unknown) {
  if (value === null || value === undefined) return '';
  return String(value);
}

function toBooleanValue(value: unknown) {
  if (value === true || value === 1) return true;
  return ['1', 'true', 'on', 'yes'].includes(String(value).trim().toLowerCase());
}

function fieldValue(value: unknown) {
  const normalized = stringValue(value);
  return normalized || '-';
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

watch(
  () => [route.path, route.query.tab, route.meta.notificationTab],
  () => {
    const audience = normalizeTemplateAudience(route.query.tab);
    if (audience) templateAudience.value = audience;
    activeTab.value = resolveRouteTab();
    if (isTemplateTab.value) loadTemplates();
  },
);

onMounted(() => {
  refreshCurrentTab();
});
</script>
