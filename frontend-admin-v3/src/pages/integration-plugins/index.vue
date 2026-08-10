<template>
  <div class="integration-plugins-page">
    <t-card :bordered="false">
      <div class="plugins-toolbar">
        <t-space>
          <t-button v-if="canManagePlugins" theme="primary" :loading="scanning" @click="scanPlugins">
            扫描插件
          </t-button>
        </t-space>
      </div>
    </t-card>

    <div v-if="loading" class="plugins-state">
      <t-loading text="正在加载插件" />
    </div>
    <t-empty v-else-if="plugins.length === 0" description="当前目录没有可安装插件" />
    <section v-else class="plugin-grid">
      <article v-for="plugin in plugins" :key="`${plugin.domain}:${plugin.slug}`" class="plugin-card">
        <div class="plugin-card__main">
          <div class="plugin-card__icon">
            {{ domainLabel(plugin.domain).slice(0, 1) }}
          </div>
          <div class="plugin-card__body">
            <div class="plugin-card__title">
              <strong>{{ plugin.name }}</strong>
              <t-tag
                :theme="plugin.is_enabled ? 'success' : plugin.is_installed ? 'warning' : 'default'"
                variant="light"
              >
                {{ pluginStatusText(plugin) }}
              </t-tag>
            </div>
            <p>{{ plugin.slug }} / {{ plugin.key }}</p>
            <div class="plugin-meta">
              <span>{{ domainLabel(plugin.domain) }}</span>
              <span>v{{ plugin.version || '-' }}</span>
            </div>
            <div class="plugin-observability">
              <t-tag v-if="plugin.latest_runtime_log" size="small" variant="light" :theme="runtimeStatusTheme(plugin)">
                {{ latestRuntimeText(plugin) }}
              </t-tag>
            </div>
          </div>
        </div>

        <div class="plugin-actions">
          <t-button
            v-if="!plugin.is_installed && canManagePlugins"
            theme="primary"
            :loading="actionLoading === actionKey(plugin, 'install')"
            @click="installPlugin(plugin)"
          >
            安装
          </t-button>
          <template v-else>
            <t-button variant="outline" @click="openConfig(plugin)">{{ canManagePlugins ? '管理' : '查看' }}</t-button>
            <t-button
              v-if="plugin.is_enabled && canManagePlugins"
              theme="warning"
              variant="outline"
              :loading="actionLoading === actionKey(plugin, 'disable')"
              @click="disablePlugin(plugin)"
            >
              停用
            </t-button>
            <t-button
              v-else-if="canManagePlugins"
              theme="success"
              variant="outline"
              :loading="actionLoading === actionKey(plugin, 'enable')"
              :disabled="isEnableDisabled(plugin)"
              :title="enableDisabledReason(plugin)"
              @click="enablePlugin(plugin)"
            >
              启用
            </t-button>
            <t-button
              v-if="canTestPlugins"
              variant="text"
              :loading="actionLoading === actionKey(plugin, 'health')"
              @click="healthCheck(plugin)"
            >
              检测
            </t-button>
            <t-button v-if="plugin.id && plugin.is_enabled" variant="text" @click="openRuntimeLogs(plugin)">
              日志
            </t-button>
            <t-button
              v-if="canManagePlugins && !plugin.is_enabled"
              theme="danger"
              variant="text"
              :loading="actionLoading === actionKey(plugin, 'delete')"
              @click="deletePlugin(plugin)"
            >
              删除
            </t-button>
          </template>
        </div>
      </article>
    </section>

    <t-drawer
      v-model:visible="configVisible"
      size="560px"
      :header="currentPlugin ? `${currentPlugin.name} 管理` : '插件管理'"
      :confirm-btn="canManagePlugins ? { content: '保存配置', loading: savingConfig } : null"
      cancel-btn="关闭"
      @confirm="saveConfig"
    >
      <template v-if="currentPlugin">
        <div class="plugin-detail">
          <dl>
            <div>
              <dt>插件目录</dt>
              <dd>{{ currentPlugin.slug }}</dd>
            </div>
            <div>
              <dt>业务标识</dt>
              <dd>{{ currentPlugin.key }}</dd>
            </div>
            <div>
              <dt>入口类</dt>
              <dd>{{ currentPlugin.entry_class || '-' }}</dd>
            </div>
            <div>
              <dt>安装时间</dt>
              <dd>{{ currentPlugin.installed_at || '-' }}</dd>
            </div>
          </dl>
        </div>

        <t-form label-align="top" class="plugin-config-form">
          <template v-for="field in visibleSchema" :key="field.key">
            <t-divider v-if="field.type === 'divider'" align="left">{{ fieldLabel(field) }}</t-divider>
            <t-alert
              v-else-if="field.type === 'notice'"
              class="plugin-config-notice"
              :theme="noticeTheme(field)"
              :message="field.content || field.description || fieldLabel(field)"
            />
            <t-form-item v-else :label="fieldLabel(field)" :class="fieldWidthClass(field)">
              <t-switch
                v-if="field.type === 'switch'"
                v-model="configForm[field.key]"
                :disabled="!canManagePlugins || field.disabled"
              />
              <t-select
                v-else-if="field.type === 'select' || field.type === 'multi_select'"
                v-model="configForm[field.key]"
                :multiple="field.type === 'multi_select'"
                :placeholder="fieldPlaceholder(field)"
                :disabled="!canManagePlugins || field.disabled"
                clearable
              >
                <t-option
                  v-for="option in fieldOptions(field)"
                  :key="String(option.value)"
                  :label="option.label"
                  :value="option.value"
                />
              </t-select>
              <t-radio-group
                v-else-if="field.type === 'radio'"
                v-model="configForm[field.key]"
                :disabled="!canManagePlugins || field.disabled"
              >
                <t-radio-button
                  v-for="option in fieldOptions(field)"
                  :key="String(option.value)"
                  :value="option.value"
                  :label="option.label"
                />
              </t-radio-group>
              <t-checkbox-group
                v-else-if="field.type === 'checkbox'"
                v-model="configForm[field.key]"
                :disabled="!canManagePlugins || field.disabled"
              >
                <t-checkbox v-for="option in fieldOptions(field)" :key="String(option.value)" :value="option.value">
                  {{ option.label }}
                </t-checkbox>
              </t-checkbox-group>
              <t-input-number
                v-else-if="field.type === 'number'"
                v-model="configForm[field.key]"
                :min="field.min ?? 0"
                :max="field.max"
                :step="field.step"
                :placeholder="fieldPlaceholder(field)"
                :disabled="!canManagePlugins || field.disabled"
                theme="column"
              />
              <t-input v-else-if="field.type === 'readonly'" :model-value="readonlyValue(field)" readonly />
              <div v-else-if="isSmtpAccountsField(field)" class="smtp-account-manager">
                <div v-if="smtpAccounts.length" class="smtp-account-list">
                  <div
                    v-for="(account, index) in smtpAccounts"
                    :key="`${account.__index ?? 'new'}-${index}`"
                    class="smtp-account-item"
                  >
                    <div class="smtp-account-item__main">
                      <div class="smtp-account-item__title">
                        <strong>{{ account.host || '-' }}</strong>
                        <t-tag :theme="account.enabled === false ? 'default' : 'success'" variant="light">
                          {{ account.enabled === false ? '已暂停' : '已开启' }}
                        </t-tag>
                      </div>
                      <div class="smtp-account-item__meta">
                        <span>{{ account.username || '-' }}</span>
                        <span>端口 {{ account.port || '-' }}</span>
                        <span>{{ account.encryption || '自动加密' }}</span>
                        <span>{{ account.password_configured || account.password ? '密码已配置' : '密码未配置' }}</span>
                      </div>
                    </div>
                    <t-dropdown
                      v-if="smtpAccountActionOptions(account).length"
                      trigger="click"
                      placement="bottom-right"
                      :options="smtpAccountActionOptions(account)"
                      @click="(data: { value: unknown }) => handleSmtpAccountAction(String(data.value), index)"
                    >
                      <t-button variant="text" shape="square">
                        <more-icon />
                      </t-button>
                    </t-dropdown>
                  </div>
                </div>
                <t-empty v-else description="暂无 SMTP 账号" />
                <t-button
                  v-if="canManagePlugins"
                  variant="outline"
                  class="smtp-account-add"
                  @click="openSmtpAccountDialog()"
                >
                  添加账号
                </t-button>
              </div>
              <secret-input
                v-else-if="isSecretTextareaField(field)"
                v-model="configForm[field.key]"
                multiline
                :autosize="{ minRows: textareaMinRows(field), maxRows: 10 }"
                :has-value="fieldHasSecretValue(field)"
                :placeholder="fieldPlaceholder(field)"
                :disabled="!canManagePlugins || field.disabled"
                :reset-key="`plugin:${currentPlugin?.id || 'new'}:${field.key}`"
                :can-reveal="canRevealPluginSecrets"
                :reveal="() => revealPluginSecret(field)"
                @edited-change="(value: boolean) => (editedSecretKeys[field.key] = value)"
                @reveal-error="(error: unknown) => MessagePlugin.error(errorMessage(error, '读取密钥失败'))"
              />
              <t-textarea
                v-else-if="field.type === 'textarea' || field.type === 'json'"
                v-model="configForm[field.key]"
                :autosize="{ minRows: textareaMinRows(field), maxRows: 10 }"
                :placeholder="fieldPlaceholder(field)"
                :disabled="!canManagePlugins || field.disabled"
              />
              <t-input
                v-else-if="isSecretInputField(field)"
                :model-value="secretInputDisplayValue(field)"
                :type="secretInputType(field)"
                :placeholder="fieldPlaceholder(field)"
                :disabled="!canManagePlugins || field.disabled"
                @focus="handleSecretFocus(field)"
                @update:model-value="(value: string) => handleSecretInput(field, value)"
              >
                <template v-if="fieldHasSecretValue(field) && canRevealPluginSecrets" #suffix-icon>
                  <span class="plugin-secret-toggle" @click.stop="toggleSecretVisibility(field)">
                    <browse-off-icon v-if="isSecretVisible(field)" />
                    <browse-icon v-else />
                  </span>
                </template>
              </t-input>
              <t-input
                v-else
                v-model="configForm[field.key]"
                :type="inputType(field)"
                :placeholder="fieldPlaceholder(field)"
                :disabled="!canManagePlugins || field.disabled"
              />
            </t-form-item>
          </template>
        </t-form>

        <div v-if="canTestPlugins && currentPlugin.domain === 'sms'" class="plugin-test-section">
          <t-divider />
          <div class="plugin-test-section__header">
            <strong>发送测试短信</strong>
            <span class="plugin-test-section__hint">向指定手机号发送一条验证码测试短信</span>
          </div>
          <t-space direction="vertical" style="width: 100%">
            <t-input v-model="smsTestPhone" placeholder="请输入手机号码" maxlength="20" />
            <t-button block variant="outline" :loading="smsTesting" @click="sendTestSms"> 发送测试短信 </t-button>
          </t-space>
        </div>
      </template>
    </t-drawer>

    <t-dialog
      v-model:visible="smtpAccountDialogVisible"
      :header="editingSmtpAccountIndex >= 0 ? '编辑 SMTP 账号' : '添加 SMTP 账号'"
      width="520px"
      :confirm-btn="{ content: '保存账号', loading: savingConfig }"
      @confirm="confirmSmtpAccount"
    >
      <t-form label-align="top" class="smtp-account-form">
        <t-form-item label="SMTP 主机">
          <t-input v-model="smtpAccountForm.host" placeholder="请输入 SMTP 主机" />
        </t-form-item>
        <t-form-item label="SMTP 端口">
          <t-input-number v-model="smtpAccountForm.port" :min="1" :max="65535" theme="column" />
        </t-form-item>
        <t-form-item label="账号">
          <t-input v-model="smtpAccountForm.username" placeholder="请输入账号" />
        </t-form-item>
        <t-form-item label="密码">
          <secret-input
            v-if="editingSmtpAccountIndex >= 0 && smtpAccountForm.password_configured"
            v-model="smtpAccountForm.password"
            :has-value="smtpAccountForm.password_configured"
            placeholder="已配置，留空表示不修改"
            :reset-key="`smtp:${currentPlugin?.id || 'new'}:${smtpAccountForm.__index ?? editingSmtpAccountIndex}`"
            :can-reveal="canRevealPluginSecrets"
            :reveal="revealSmtpAccountPassword"
            @edited-change="(value: boolean) => (smtpAccountPasswordEdited = value)"
            @reveal-error="(error: unknown) => MessagePlugin.error(errorMessage(error, '读取 SMTP 密码失败'))"
          />
          <t-input
            v-else
            v-model="smtpAccountForm.password"
            type="password"
            :placeholder="editingSmtpAccountIndex >= 0 ? '已配置，留空表示不修改' : '请输入密码'"
          />
        </t-form-item>
        <t-form-item label="发件名称">
          <t-input v-model="smtpAccountForm.from_name" placeholder="请输入发件名称" />
        </t-form-item>
        <t-form-item label="加密方式">
          <t-select v-model="smtpAccountForm.encryption" clearable>
            <t-option label="自动" value="" />
            <t-option label="SSL" value="ssl" />
            <t-option label="TLS" value="tls" />
            <t-option label="无" value="none" />
          </t-select>
        </t-form-item>
        <t-form-item label="状态">
          <t-switch v-model="smtpAccountForm.enabled" />
        </t-form-item>
      </t-form>
    </t-dialog>
    <t-dialog
      v-model:visible="emailTestVisible"
      header="发送测试邮件"
      width="480px"
      :confirm-btn="{ content: '发送测试', loading: emailTesting }"
      @confirm="sendTestEmail"
    >
      <t-form label-align="top" class="email-test-form">
        <t-form-item label="收件人邮箱" :status="emailTestErrors.to ? 'error' : undefined" :help="emailTestErrors.to">
          <t-input v-model="emailTestForm.to" placeholder="请输入收件人邮箱" @change="clearEmailTestError('to')" />
        </t-form-item>
      </t-form>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { BrowseIcon, BrowseOffIcon, MoreIcon } from 'tdesign-icons-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import type {
  IntegrationPluginConfigSchema,
  IntegrationPluginDomain,
  IntegrationPluginRecord,
} from '@/api/admin/plugins';
import { pluginsApi } from '@/api/admin/plugins';
import SecretInput from '@/components/secret-input/index.vue';
import { AdminPermissions } from '@/constants/permissions';
import { hasAdminPermission } from '@/utils/permission';
import { errorMessage } from '@/utils/userMessage';

const router = useRouter();
const route = useRoute();

interface SmtpAccountForm {
  __index: number | null;
  host: string;
  port: number;
  username: string;
  password: string;
  from_name: string;
  encryption: string;
  enabled: boolean;
  password_configured: boolean;
}

const domainTabs: Array<{ value: IntegrationPluginDomain; label: string }> = [
  { value: 'captcha', label: '人机验证' },
  { value: 'verification', label: '实名认证' },
  { value: 'payment', label: '支付渠道' },
  { value: 'mail', label: '邮件发送' },
  { value: 'sms', label: '短信发送' },
  { value: 'upstream', label: '上游开通' },
  { value: 'addons', label: '功能扩展' },
];

const singleEnabledDomains = new Set<IntegrationPluginDomain>(['captcha', 'verification', 'mail', 'sms']);
const activeDomain = ref<IntegrationPluginDomain>(resolveRouteDomain());
const plugins = ref<IntegrationPluginRecord[]>([]);
const loading = ref(false);
const scanning = ref(false);
const savingConfig = ref(false);
const actionLoading = ref('');
const configVisible = ref(false);
const currentPlugin = ref<IntegrationPluginRecord | null>(null);
const configForm = reactive<Record<string, any>>({});
const smtpAccountDialogVisible = ref(false);
const editingSmtpAccountIndex = ref(-1);
const smtpAccountForm = reactive<SmtpAccountForm>({
  __index: null,
  host: '',
  port: 465,
  username: '',
  password: '',
  from_name: '',
  encryption: '',
  enabled: true,
  password_configured: false,
});

const currentSchema = computed(() => currentPlugin.value?.config_schema || []);
const visibleSchema = computed(() => currentSchema.value.filter((field) => isFieldVisible(field)));
const smtpAccounts = computed<SmtpAccountForm[]>(() => (Array.isArray(configForm.accounts) ? configForm.accounts : []));

const emailTestVisible = ref(false);
const emailTesting = ref(false);
const testingAccountIndex = ref(-1);
const emailTestForm = reactive({ to: '' });
const emailTestErrors = reactive<Record<'to', string>>({
  to: '',
});
const canManagePlugins = computed(() => hasAdminPermission(AdminPermissions.INTEGRATION_PLUGIN_MANAGE));
const canTestPlugins = computed(() => hasAdminPermission(AdminPermissions.INTEGRATION_PLUGIN_TEST));
const canRevealPluginSecrets = computed(() => hasAdminPermission(AdminPermissions.INTEGRATION_PLUGIN_SECRET_REVEAL));
const EMAIL_PATTERN = /^[^\s@]+@[^\s@][^\s.@]*\.[^\s@]+$/;

const smsTestPhone = ref('');
const smsTesting = ref(false);
const smtpAccountPasswordEdited = ref(false);
const MASKED_SECRET_VALUE = '********';
const visibleSecretKeys = reactive<Record<string, boolean>>({});
const loadedSecretValues = reactive<Record<string, string>>({});
const editedSecretKeys = reactive<Record<string, boolean>>({});
const loadingSecretKeys = reactive<Record<string, boolean>>({});

onMounted(loadPlugins);

function normalizeDomain(value: unknown): IntegrationPluginDomain | null {
  const domain = Array.isArray(value) ? value[0] : value;
  return domainTabs.some((item) => item.value === domain) ? (domain as IntegrationPluginDomain) : null;
}

function resolveRouteDomain(): IntegrationPluginDomain {
  return normalizeDomain(route.query.domain) || normalizeDomain(route.meta.pluginDomain) || 'captcha';
}

watch(
  () => [route.path, route.query.domain, route.meta.pluginDomain],
  () => {
    const nextDomain = resolveRouteDomain();
    if (nextDomain === activeDomain.value) return;
    activeDomain.value = nextDomain;
    loadPlugins();
  },
);

async function loadPlugins() {
  loading.value = true;
  try {
    const response = await pluginsApi.list({ domain: activeDomain.value });
    plugins.value = response.list || [];
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载插件列表失败'));
  } finally {
    loading.value = false;
  }
}

async function scanPlugins() {
  if (!canManagePlugins.value) {
    MessagePlugin.warning('当前账号无插件管理权限');
    return;
  }

  scanning.value = true;
  try {
    const response = await pluginsApi.scan({ domain: activeDomain.value });
    plugins.value = response.list || [];
    MessagePlugin.success('插件目录扫描完成');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '扫描插件失败'));
  } finally {
    scanning.value = false;
  }
}

async function installPlugin(plugin: IntegrationPluginRecord) {
  if (!canManagePlugins.value) return;

  await runAction(plugin, 'install', async () => {
    const installed = await pluginsApi.install({ domain: plugin.domain, slug: plugin.slug });
    MessagePlugin.success('插件安装成功');
    await loadPlugins();
    if (installed.id) openConfig(installed);
  });
}

async function enablePlugin(plugin: IntegrationPluginRecord) {
  if (!canManagePlugins.value) return;
  if (!plugin.id) return;
  const disabledReason = enableDisabledReason(plugin);
  if (disabledReason) {
    MessagePlugin.warning(disabledReason);
    return;
  }

  await runAction(plugin, 'enable', async () => {
    await pluginsApi.enable(plugin.id as string | number);
    MessagePlugin.success('插件已启用');
    await loadPlugins();
  });
}

async function disablePlugin(plugin: IntegrationPluginRecord) {
  if (!canManagePlugins.value) return;
  if (!plugin.id) return;
  await runAction(plugin, 'disable', async () => {
    await pluginsApi.disable(plugin.id as string | number);
    MessagePlugin.success('插件已停用');
    await loadPlugins();
  });
}

async function deletePlugin(plugin: IntegrationPluginRecord) {
  if (!canManagePlugins.value) return;
  if (!plugin.id) return;
  // eslint-disable-next-line no-alert
  if (!window.confirm(`确定删除插件「${plugin.name}」的安装记录和配置吗？插件目录文件不会被删除。`)) return;

  await runAction(plugin, 'delete', async () => {
    await pluginsApi.remove(plugin.id as string | number);
    MessagePlugin.success('插件已删除');
    await loadPlugins();
    if (currentPlugin.value?.id === plugin.id) configVisible.value = false;
  });
}

async function healthCheck(plugin: IntegrationPluginRecord) {
  if (!canTestPlugins.value) return;
  if (!plugin.id) return;
  await runAction(plugin, 'health', async () => {
    const result = await pluginsApi.healthCheck(plugin.id as string | number);
    MessagePlugin.success(String(result.message || '插件加载正常'));
  });
}

async function openConfig(plugin: IntegrationPluginRecord) {
  if (!plugin.id) return;
  try {
    const detail = await pluginsApi.detail(plugin.id);
    currentPlugin.value = detail;
    fillConfigForm(detail);
    configVisible.value = true;
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载插件配置失败'));
  }
}

async function saveConfig() {
  if (!canManagePlugins.value) {
    MessagePlugin.warning('当前账号无插件管理权限');
    return;
  }

  if (!currentPlugin.value?.id) return;
  savingConfig.value = true;
  try {
    const payload = buildConfigPayload();
    const detail = await pluginsApi.updateConfig(currentPlugin.value.id, payload);
    currentPlugin.value = detail;
    fillConfigForm(detail);
    MessagePlugin.success('插件配置已保存');
    await loadPlugins();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存插件配置失败'));
  } finally {
    savingConfig.value = false;
  }
}

async function runAction(plugin: IntegrationPluginRecord, action: string, callback: () => Promise<void>) {
  actionLoading.value = actionKey(plugin, action);
  try {
    await callback();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '操作失败'));
  } finally {
    actionLoading.value = '';
  }
}

function fillConfigForm(plugin: IntegrationPluginRecord) {
  Object.keys(configForm).forEach((key) => delete configForm[key]);
  resetSecretState();
  const config = plugin.config || {};
  (plugin.config_schema || []).forEach((field) => {
    if (isDisplayOnlyField(field) && field.type !== 'readonly') {
      return;
    }

    if (isSmtpAccountsField(field)) {
      configForm[field.key] = smtpAccountPreview(field, plugin).map((account) => ({
        ...account,
        password: '',
      }));
      return;
    }

    if (fieldHasSecretValue(field, plugin)) {
      configForm[field.key] = '';
      return;
    }

    const value = config[field.key] ?? field.default ?? field.value ?? defaultFieldValue(field);
    if (field.type === 'json') {
      configForm[field.key] = typeof value === 'string' ? value : JSON.stringify(value || [], null, 2);
    } else if (field.type === 'multi_select' || field.type === 'checkbox') {
      configForm[field.key] = Array.isArray(value) ? value : [];
    } else if (field.type === 'switch') {
      configForm[field.key] = Boolean(value);
    } else {
      configForm[field.key] = value;
    }
  });
}

function buildConfigPayload() {
  const payload: Record<string, unknown> = {};
  currentSchema.value.forEach((field) => {
    if (isDisplayOnlyField(field)) {
      return;
    }

    const value = configForm[field.key];
    if (isSmtpAccountsField(field)) {
      payload[field.key] = smtpAccounts.value.map((account) => ({
        __index: account.__index,
        host: account.host,
        port: Number(account.port || 0),
        username: account.username,
        password: account.password,
        from_name: account.from_name,
        encryption: account.encryption,
        enabled: account.enabled !== false,
      }));
      return;
    }

    if (fieldHasSecretValue(field)) {
      if (!editedSecretKeys[field.key] || isBlankSecretValue(value)) return;
    }

    if (field.type === 'json') {
      payload[field.key] = parseJsonField(value, fieldLabel(field));
    } else {
      payload[field.key] = value;
    }
  });
  return payload;
}

function parseJsonField(value: unknown, label: string) {
  if (Array.isArray(value) || (value && typeof value === 'object')) return value;
  const raw = String(value || '').trim();
  if (!raw) return [];
  try {
    return JSON.parse(raw);
  } catch {
    throw new Error(`${label} 必须是合法 JSON`);
  }
}

function fieldLabel(field: IntegrationPluginConfigSchema) {
  return field.label || field.title || field.key;
}

function fieldPlaceholder(field: IntegrationPluginConfigSchema) {
  if (field.placeholder) return field.placeholder;
  if (fieldHasSecretValue(field)) return '已配置，留空表示不修改';
  if (field.type === 'json') return '请输入 JSON 数组或对象';
  return `请输入${fieldLabel(field)}`;
}

function inputType(field: IntegrationPluginConfigSchema) {
  if (field.secret || field.type === 'password') return 'password';
  if (field.type === 'email') return 'email';
  if (field.type === 'url') return 'url';
  if (field.type === 'phone') return 'tel';
  return 'text';
}

function isSecretInputField(field: IntegrationPluginConfigSchema) {
  return !isSmtpAccountsField(field) && Boolean(field.secret || field.type === 'password');
}

function isSecretTextareaField(field: IntegrationPluginConfigSchema) {
  return !isSmtpAccountsField(field) && Boolean(field.secret) && (field.type === 'textarea' || field.type === 'json');
}

function secretInputDisplayValue(field: IntegrationPluginConfigSchema) {
  if (!fieldHasSecretValue(field)) return configForm[field.key] ?? '';
  if (isSecretVisible(field)) return configForm[field.key] ?? loadedSecretValues[field.key] ?? '';
  if (editedSecretKeys[field.key]) return configForm[field.key] ?? '';
  return MASKED_SECRET_VALUE;
}

function secretInputType(field: IntegrationPluginConfigSchema) {
  if (fieldHasSecretValue(field) && !isSecretVisible(field) && !editedSecretKeys[field.key]) return 'text';
  if (fieldHasSecretValue(field) && isSecretVisible(field)) return 'text';
  return inputType(field);
}

function handleSecretFocus(field: IntegrationPluginConfigSchema) {
  if (!fieldHasSecretValue(field) || isSecretVisible(field) || editedSecretKeys[field.key]) return;
  configForm[field.key] = '';
  editedSecretKeys[field.key] = true;
}

function handleSecretInput(field: IntegrationPluginConfigSchema, value: string) {
  if (
    fieldHasSecretValue(field) &&
    !isSecretVisible(field) &&
    !editedSecretKeys[field.key] &&
    value === MASKED_SECRET_VALUE
  ) {
    return;
  }

  configForm[field.key] = value;
  if (fieldHasSecretValue(field)) {
    editedSecretKeys[field.key] = true;
  }
}

function isSecretVisible(field: IntegrationPluginConfigSchema) {
  return Boolean(visibleSecretKeys[field.key]);
}

async function toggleSecretVisibility(field: IntegrationPluginConfigSchema) {
  if (!canRevealPluginSecrets.value) return;
  if (!currentPlugin.value?.id || loadingSecretKeys[field.key]) return;

  if (isSecretVisible(field)) {
    visibleSecretKeys[field.key] = false;
    editedSecretKeys[field.key] = false;
    return;
  }

  if (loadedSecretValues[field.key] !== undefined) {
    configForm[field.key] = loadedSecretValues[field.key];
    visibleSecretKeys[field.key] = true;
    editedSecretKeys[field.key] = false;
    return;
  }

  loadingSecretKeys[field.key] = true;
  try {
    const response = await pluginsApi.revealSecret(currentPlugin.value.id, field.key);
    const value = response.value;
    loadedSecretValues[field.key] = typeof value === 'string' ? value : JSON.stringify(value ?? '');
    configForm[field.key] = loadedSecretValues[field.key];
    visibleSecretKeys[field.key] = true;
    editedSecretKeys[field.key] = false;
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '读取密钥失败'));
  } finally {
    loadingSecretKeys[field.key] = false;
  }
}

async function revealPluginSecret(field: IntegrationPluginConfigSchema) {
  if (!canRevealPluginSecrets.value) return '';
  if (!currentPlugin.value?.id) return '';
  const response = await pluginsApi.revealSecret(currentPlugin.value.id, field.key);
  return response.value;
}

function resetSecretState() {
  [visibleSecretKeys, loadedSecretValues, editedSecretKeys, loadingSecretKeys].forEach((store) => {
    Object.keys(store).forEach((key) => delete store[key]);
  });
}

function textareaMinRows(field: IntegrationPluginConfigSchema) {
  if (field.rows && field.rows > 0) return field.rows;
  return field.type === 'json' ? 6 : 4;
}

function readonlyValue(field: IntegrationPluginConfigSchema) {
  const value = configForm[field.key] ?? field.value ?? field.default ?? '';
  if (Array.isArray(value) || (value && typeof value === 'object')) return JSON.stringify(value);
  return String(value);
}

function noticeTheme(field: IntegrationPluginConfigSchema) {
  if (field.theme === 'success' || field.theme === 'warning' || field.theme === 'error') return field.theme;
  return 'info';
}

function fieldWidthClass(field: IntegrationPluginConfigSchema) {
  return field.width === 'half' ? 'plugin-config-form__item--half' : '';
}

function isDisplayOnlyField(field: IntegrationPluginConfigSchema) {
  return field.type === 'notice' || field.type === 'divider' || field.type === 'readonly';
}

function defaultFieldValue(field: IntegrationPluginConfigSchema): boolean | unknown[] | string {
  if (field.type === 'switch') return false;
  if (field.type === 'multi_select' || field.type === 'checkbox') return [];
  return '';
}

function isFieldVisible(field: IntegrationPluginConfigSchema) {
  if (field.visible === false) return false;
  const condition = field.visible_when;
  if (!condition?.field) return true;

  const currentValue = configForm[condition.field];
  const expectedValue = condition.value;
  if (condition.operator === 'neq') return currentValue !== expectedValue;
  if (condition.operator === 'in') return Array.isArray(expectedValue) && expectedValue.includes(currentValue);
  if (condition.operator === 'not_in') return Array.isArray(expectedValue) && !expectedValue.includes(currentValue);
  return currentValue === expectedValue;
}

function fieldHasSecretValue(field: IntegrationPluginConfigSchema, plugin = currentPlugin.value) {
  return Boolean(field.secret && plugin?.has_secret_values?.[field.key]);
}

function isBlankSecretValue(value: unknown) {
  if (value === null || value === undefined) return true;
  if (typeof value === 'string') return value.trim() === '';
  return false;
}

function isSmtpAccountsField(field: IntegrationPluginConfigSchema) {
  return field.key === 'accounts' && field.secret && field.type === 'json';
}

function smtpAccountPreview(field: IntegrationPluginConfigSchema, plugin = currentPlugin.value): SmtpAccountForm[] {
  const preview = plugin?.secret_previews?.[field.key];
  if (!preview || preview.type !== 'smtp_accounts' || !Array.isArray(preview.items)) return [];

  return preview.items.map((item) => ({
    __index: Number.isFinite(Number(item.index)) ? Number(item.index) : null,
    host: String(item.host || ''),
    username: String(item.username || ''),
    port: Number(item.port || 465),
    encryption: String(item.encryption || ''),
    from_name: String(item.from_name || ''),
    enabled: item.enabled !== false,
    password: '',
    password_configured: Boolean(item.password_configured),
  }));
}

function smtpAccountActionOptions(account: SmtpAccountForm) {
  const actions = [];
  if (canManagePlugins.value) {
    actions.push({ content: account.enabled === false ? '开启' : '暂停', value: 'toggle' });
    actions.push({ content: '编辑', value: 'edit' });
  }
  if (canTestPlugins.value) {
    actions.push({ content: '发送测试邮件', value: 'test' });
  }
  if (canManagePlugins.value) {
    actions.push({ content: '删除', value: 'delete' });
  }
  return actions;
}

async function handleSmtpAccountAction(action: string, index: number) {
  if (action === 'edit') {
    openSmtpAccountDialog(index);
    return;
  }

  if (action === 'test') {
    if (!canTestPlugins.value) return;
    testingAccountIndex.value = index;
    emailTestForm.to = '';
    clearEmailTestErrors();
    emailTestVisible.value = true;
    return;
  }

  if (action === 'toggle') {
    if (!canManagePlugins.value) return;
    const account = smtpAccounts.value[index];
    if (!account) return;
    account.enabled = account.enabled === false;
    await saveConfig();
    return;
  }

  if (action === 'delete') {
    if (!canManagePlugins.value) return;
    // eslint-disable-next-line no-alert
    if (!window.confirm('确定删除这个 SMTP 账号吗？')) return;
    smtpAccounts.value.splice(index, 1);
    await saveConfig();
  }
}

function openSmtpAccountDialog(index = -1) {
  if (!canManagePlugins.value) return;

  editingSmtpAccountIndex.value = index;
  smtpAccountPasswordEdited.value = false;
  const account = index >= 0 ? smtpAccounts.value[index] : null;
  Object.assign(smtpAccountForm, {
    __index: account?.__index ?? null,
    host: account?.host ?? '',
    port: Number(account?.port || 465),
    username: account?.username ?? '',
    password: '',
    from_name: account?.from_name ?? '',
    encryption: account?.encryption ?? '',
    enabled: account?.enabled !== false,
    password_configured: Boolean(account?.password_configured),
  });
  smtpAccountDialogVisible.value = true;
}

async function confirmSmtpAccount() {
  if (!canManagePlugins.value) return;

  if (smtpAccountForm.host.trim() === '' || smtpAccountForm.username.trim() === '') {
    MessagePlugin.error('请填写 SMTP 主机和账号');
    return;
  }

  if (editingSmtpAccountIndex.value < 0 && smtpAccountForm.password.trim() === '') {
    MessagePlugin.error('新增 SMTP 账号需要填写密码');
    return;
  }

  const payload = {
    __index: smtpAccountForm.__index,
    host: smtpAccountForm.host.trim(),
    port: Number(smtpAccountForm.port || 465),
    username: smtpAccountForm.username.trim(),
    password: editingSmtpAccountIndex.value >= 0 && !smtpAccountPasswordEdited.value ? '' : smtpAccountForm.password,
    from_name: smtpAccountForm.from_name.trim(),
    encryption: smtpAccountForm.encryption,
    enabled: smtpAccountForm.enabled,
    password_configured: smtpAccountForm.password.trim() !== '' || smtpAccountForm.password_configured,
  };

  if (editingSmtpAccountIndex.value >= 0) {
    smtpAccounts.value.splice(editingSmtpAccountIndex.value, 1, payload);
  } else {
    smtpAccounts.value.push(payload);
  }

  smtpAccountDialogVisible.value = false;
  await saveConfig();
}

async function revealSmtpAccountPassword() {
  if (!canRevealPluginSecrets.value) return '';
  if (!currentPlugin.value?.id) return '';
  const response = await pluginsApi.revealSecret(currentPlugin.value.id, 'accounts');
  const accounts = Array.isArray(response.value) ? response.value : [];
  const index = smtpAccountForm.__index ?? editingSmtpAccountIndex.value;
  const account = accounts[Number(index)];
  return account && typeof account === 'object' ? String((account as Record<string, unknown>).password || '') : '';
}

async function sendTestEmail() {
  if (!canTestPlugins.value) return;

  clearEmailTestErrors();

  const to = emailTestForm.to.trim();

  if (!to) {
    emailTestErrors.to = '请输入收件人邮箱';
    return;
  }

  if (!EMAIL_PATTERN.test(to)) {
    emailTestErrors.to = '请输入正确的邮箱地址';
    return;
  }

  if (!currentPlugin.value?.id) return;

  emailTesting.value = true;
  try {
    await pluginsApi.testEmail(currentPlugin.value.id, {
      account_index: testingAccountIndex.value,
      to,
    });
    MessagePlugin.success('测试邮件发送成功');
    emailTestVisible.value = false;
  } catch (error) {
    const fieldErrors = extractFieldErrors(error);
    if (fieldErrors.to) {
      Object.assign(emailTestErrors, fieldErrors);
    }
    MessagePlugin.error(errorMessage(error, '测试邮件发送失败'));
  } finally {
    emailTesting.value = false;
  }
}

function clearEmailTestError(field: keyof typeof emailTestErrors) {
  emailTestErrors[field] = '';
}

function clearEmailTestErrors() {
  Object.keys(emailTestErrors).forEach((field) => {
    emailTestErrors[field as keyof typeof emailTestErrors] = '';
  });
}

function extractFieldErrors(error: unknown) {
  const responseData = error as { response?: { data?: { data?: { errors?: Record<string, string[]> } } } };
  const errors = responseData?.response?.data?.data?.errors;
  return {
    to: firstError(errors?.to),
  };
}

function firstError(messages: unknown): string {
  if (Array.isArray(messages)) return String(messages[0] || '');
  return '';
}

async function sendTestSms() {
  if (!canTestPlugins.value) return;

  const phone = smsTestPhone.value.trim();
  if (!phone) {
    MessagePlugin.error('请输入手机号码');
    return;
  }
  if (!currentPlugin.value?.id) return;

  smsTesting.value = true;
  try {
    await pluginsApi.testSms(currentPlugin.value.id, { phone });
    MessagePlugin.success('测试短信发送成功');
    smsTestPhone.value = '';
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '测试短信发送失败'));
  } finally {
    smsTesting.value = false;
  }
}

function fieldOptions(field: IntegrationPluginConfigSchema) {
  const options = field.options;
  if (Array.isArray(options)) {
    return options.map((item) => ({
      label: String(item.label ?? item.value ?? ''),
      value: item.value ?? item.label ?? '',
    }));
  }
  return Object.entries(options || {}).map(([value, label]) => ({ label: String(label), value }));
}

function domainLabel(domain: string) {
  return domainTabs.find((item) => item.value === domain)?.label || domain;
}

function isEnableDisabled(plugin: IntegrationPluginRecord) {
  return enableDisabledReason(plugin) !== '';
}

function enableDisabledReason(plugin: IntegrationPluginRecord) {
  const apiReason = typeof plugin.enable_disabled_reason === 'string' ? plugin.enable_disabled_reason.trim() : '';
  if (apiReason) return apiReason;
  if (plugin.is_enabled || !singleEnabledDomains.has(plugin.domain)) return '';

  const currentPluginId = String(plugin.id ?? '');
  const enabledPlugin = plugins.value.find(
    (item) => item.domain === plugin.domain && item.is_enabled && String(item.id ?? '') !== currentPluginId,
  );
  if (!enabledPlugin) return '';

  return `当前功能域已启用「${enabledPlugin.name}」，请先停用后再启用其他插件`;
}

function pluginStatusText(plugin: IntegrationPluginRecord) {
  if (plugin.is_enabled) return '已启用';
  if (plugin.is_installed) return '已安装';
  return '未安装';
}

function latestRuntimeText(plugin: IntegrationPluginRecord) {
  const log = plugin.latest_runtime_log;
  if (!log) return '暂无运行记录';
  const status = String(log.status || '').toLowerCase();
  const statusText = status === 'failed' ? '失败' : status === 'success' ? '成功' : status || '未知';
  return `最近运行 ${statusText}`;
}

function runtimeStatusTheme(plugin: IntegrationPluginRecord) {
  const status = String(plugin.latest_runtime_log?.status || '').toLowerCase();
  if (status === 'failed') return 'danger';
  if (status === 'success') return 'success';
  return 'default';
}

function openRuntimeLogs(plugin: IntegrationPluginRecord) {
  if (!plugin.id) return;
  router.push({
    path: '/admin/logs/runtime',
    query: {
      plugin_id: String(plugin.id),
    },
  });
}

function actionKey(plugin: IntegrationPluginRecord, action: string) {
  return `${plugin.domain}:${plugin.slug}:${action}`;
}
</script>
