<template>
  <div class="ai-settings-page">
    <t-loading :loading="loading" size="small" show-overlay class="ai-settings-loading">
      <t-form ref="formRef" class="ai-settings-form" :data="form" :rules="formRules">
        <!-- 每个字段都是「标签一行 → 说明一行 → 控件一行」：
             说明不再跟控件抢同一行，长说明和开关也不会互相挤。 -->
        <!-- ① 总开关与上游协议：先决定这套助手怎么接、能不能用 -->
        <t-card :bordered="false" class="ai-settings-card">
          <h2 class="ai-settings-card__title">{{ $t('ai.settings.sectionSwitch') }}</h2>

          <div class="admin-field-grid">
            <div class="admin-field admin-field--switch">
              <span class="admin-field__label">{{ $t('ai.settings.enabled') }}</span>
              <div class="admin-field__control">
                <t-switch v-model="form.enabled" />
              </div>
              <p class="admin-field__desc">{{ $t('ai.settings.enabledTip') }}</p>
            </div>

            <div class="admin-field">
              <span class="admin-field__label">{{ $t('ai.settings.protocol') }}</span>
              <p class="admin-field__desc">{{ protocolHelp }}</p>
              <div class="admin-field__control admin-field__control--medium">
                <t-select v-model="form.protocol">
                  <t-option
                    v-for="option in protocolOptions"
                    :key="option.value"
                    :label="option.label"
                    :value="option.value"
                  />
                </t-select>
              </div>
            </div>
          </div>
        </t-card>

        <!-- ② 网关与模型：真正影响能不能连通的一整组参数 -->
        <t-card :bordered="false" class="ai-settings-card">
          <h2 class="ai-settings-card__title">{{ $t('ai.settings.sectionBasic') }}</h2>

          <div class="admin-field-grid">
            <div class="admin-field admin-field--full">
              <span class="admin-field__label">{{ $t('ai.settings.baseUrl') }}</span>
              <p class="admin-field__desc">{{ $t('ai.settings.helpBaseUrl') }}</p>
              <div class="admin-field__control">
                <t-input v-model="form.base_url" clearable :placeholder="$t('ai.settings.baseUrlPlaceholder')" />
              </div>
            </div>

            <div class="admin-field">
              <span class="admin-field__label">{{ $t('ai.settings.model') }}</span>
              <p class="admin-field__desc">{{ $t('ai.settings.helpModel') }}</p>
              <div class="admin-field__control admin-field__control--medium">
                <t-input v-model="form.model" clearable :placeholder="$t('ai.settings.modelPlaceholder')" />
              </div>
            </div>

            <div class="admin-field admin-field--full">
              <span class="admin-field__label">{{ $t('ai.settings.apiKey') }}</span>
              <p class="admin-field__desc">{{ apiKeyHelp }}</p>
              <div class="admin-field__control">
                <t-input
                  v-model="apiKeyInput"
                  type="password"
                  clearable
                  :placeholder="$t('ai.settings.apiKeyPlaceholder')"
                />
              </div>
            </div>

            <div class="admin-field">
              <span class="admin-field__label">{{ $t('ai.settings.temperature') }}</span>
              <p class="admin-field__desc">{{ $t('ai.settings.helpTemperature') }}</p>
              <div class="admin-field__control admin-field__control--narrow">
                <t-input-number v-model="form.temperature" theme="normal" :min="0" :max="2" :step="0.1" />
              </div>
            </div>

            <div class="admin-field">
              <span class="admin-field__label">{{ $t('ai.settings.maxTokens') }}</span>
              <p class="admin-field__desc">{{ $t('ai.settings.helpMaxTokens') }}</p>
              <div class="admin-field__control admin-field__control--narrow">
                <t-input-number v-model="form.max_tokens" theme="normal" :min="128" :max="8192" :step="128" />
              </div>
            </div>
          </div>
        </t-card>

        <!-- ③ 入口开关：三个端各自能不能看到浮球 -->
        <t-card :bordered="false" class="ai-settings-card">
          <h2 class="ai-settings-card__title">{{ $t('ai.settings.sectionChannels') }}</h2>

          <div class="admin-field-grid">
            <div class="admin-field admin-field--switch">
              <span class="admin-field__label">{{ $t('ai.settings.consoleEnabled') }}</span>
              <div class="admin-field__control">
                <t-switch v-model="form.console_enabled" />
              </div>
              <p class="admin-field__desc">{{ $t('ai.settings.helpConsoleEnabled') }}</p>
            </div>

            <div class="admin-field admin-field--switch">
              <span class="admin-field__label">{{ $t('ai.settings.siteEnabled') }}</span>
              <div class="admin-field__control">
                <t-switch v-model="form.site_enabled" />
              </div>
              <p class="admin-field__desc">{{ $t('ai.settings.helpSiteEnabled') }}</p>
            </div>

            <div class="admin-field admin-field--switch">
              <span class="admin-field__label">{{ $t('ai.settings.adminEnabled') }}</span>
              <div class="admin-field__control">
                <t-switch v-model="form.admin_enabled" />
              </div>
              <p class="admin-field__desc">{{ $t('ai.settings.helpAdminEnabled') }}</p>
            </div>

            <div class="admin-field admin-field--switch">
              <span class="admin-field__label">{{ $t('ai.settings.allowBalancePay') }}</span>
              <div class="admin-field__control">
                <t-switch v-model="form.allow_balance_pay" />
              </div>
              <p class="admin-field__desc">{{ $t('ai.settings.allowBalancePayTip') }}</p>
            </div>
          </div>
        </t-card>

        <!-- ④ 额度与熔断：0 一律表示不限量，默认值已经是可直接投产的数 -->
        <t-card :bordered="false" class="ai-settings-card">
          <h2 class="ai-settings-card__title">{{ $t('ai.settings.sectionLimits') }}</h2>

          <div class="admin-field-grid">
            <div class="admin-field">
              <span class="admin-field__label">{{ $t('ai.settings.userDailyMessages') }}</span>
              <p class="admin-field__desc">{{ $t('ai.settings.unlimitedTip') }}</p>
              <div class="admin-field__control admin-field__control--narrow">
                <t-input-number v-model="form.user_daily_messages" theme="normal" :min="0" :max="10000" :step="10" />
              </div>
            </div>

            <div class="admin-field">
              <span class="admin-field__label">{{ $t('ai.settings.userDailyTokens') }}</span>
              <p class="admin-field__desc">{{ $t('ai.settings.unlimitedTip') }}</p>
              <div class="admin-field__control admin-field__control--narrow">
                <t-input-number
                  v-model="form.user_daily_tokens"
                  theme="normal"
                  :min="0"
                  :max="100000000"
                  :step="1000"
                />
              </div>
            </div>

            <div class="admin-field">
              <span class="admin-field__label">{{ $t('ai.settings.globalDailyTokens') }}</span>
              <p class="admin-field__desc">{{ $t('ai.settings.unlimitedTip') }}</p>
              <div class="admin-field__control admin-field__control--narrow">
                <t-input-number
                  v-model="form.global_daily_tokens"
                  theme="normal"
                  :min="0"
                  :max="100000000"
                  :step="10000"
                />
              </div>
            </div>

            <div class="admin-field">
              <span class="admin-field__label">{{ $t('ai.settings.globalDailyCostCents') }}</span>
              <p class="admin-field__desc">{{ $t('ai.settings.unlimitedTip') }}</p>
              <div class="admin-field__control admin-field__control--narrow">
                <t-input-number
                  v-model="form.global_daily_cost_cents"
                  theme="normal"
                  :min="0"
                  :max="100000000"
                  :step="100"
                />
              </div>
            </div>

            <div class="admin-field">
              <span class="admin-field__label">{{ $t('ai.settings.costPerThousandTokens') }}</span>
              <p class="admin-field__desc">{{ $t('ai.settings.helpCostPerThousandTokens') }}</p>
              <div class="admin-field__control admin-field__control--narrow">
                <t-input-number
                  v-model="form.cost_per_1k_tokens_cents"
                  theme="normal"
                  :min="0"
                  :max="100000"
                  :step="1"
                />
              </div>
            </div>
          </div>
        </t-card>

        <!-- ⑤ 工具与轮次：决定模型能碰哪些数据、一轮里能连续调几次 -->
        <t-card :bordered="false" class="ai-settings-card">
          <h2 class="ai-settings-card__title">{{ $t('ai.settings.sectionTools') }}</h2>

          <div class="admin-field-grid">
            <div class="admin-field admin-field--full">
              <span class="admin-field__label">{{ $t('ai.settings.enabledTools') }}</span>
              <p class="admin-field__desc">{{ $t('ai.settings.helpEnabledTools') }}</p>
              <div class="admin-field__control">
                <t-select
                  multiple
                  clearable
                  filterable
                  :model-value="form.enabled_tools"
                  :placeholder="$t('ai.settings.toolsPlaceholder')"
                  @change="handleEnabledToolsChange"
                >
                  <t-option v-for="tool in toolOptions" :key="tool.name" :value="tool.name" :label="tool.name">
                    <div class="ai-settings-tool">
                      <span class="ai-settings-tool__name">{{ tool.name }}</span>
                      <span class="ai-settings-tool__risk">{{ riskLabel(tool.risk) }}</span>
                      <span class="ai-settings-tool__desc">{{ tool.description }}</span>
                    </div>
                  </t-option>
                </t-select>
              </div>
            </div>

            <div class="admin-field">
              <span class="admin-field__label">{{ $t('ai.settings.maxToolRounds') }}</span>
              <p class="admin-field__desc">{{ $t('ai.settings.helpMaxToolRounds') }}</p>
              <div class="admin-field__control admin-field__control--narrow">
                <t-input-number v-model="form.max_tool_rounds" theme="normal" :min="1" :max="12" :step="1" />
              </div>
            </div>

            <div class="admin-field">
              <span class="admin-field__label">{{ $t('ai.settings.requestTimeoutSeconds') }}</span>
              <p class="admin-field__desc">{{ $t('ai.settings.helpRequestTimeout') }}</p>
              <div class="admin-field__control admin-field__control--narrow">
                <t-input-number v-model="form.request_timeout_seconds" theme="normal" :min="15" :max="180" :step="5" />
              </div>
            </div>
          </div>
        </t-card>

        <!-- ⑥ 会话与留存：上下文带多少、日志留多久、出站要不要脱敏 -->
        <t-card :bordered="false" class="ai-settings-card">
          <h2 class="ai-settings-card__title">{{ $t('ai.settings.sectionSafety') }}</h2>

          <div class="admin-field-grid">
            <div class="admin-field">
              <span class="admin-field__label">{{ $t('ai.settings.historyMessageLimit') }}</span>
              <p class="admin-field__desc">{{ $t('ai.settings.helpHistoryMessageLimit') }}</p>
              <div class="admin-field__control admin-field__control--narrow">
                <t-input-number v-model="form.history_message_limit" theme="normal" :min="2" :max="40" :step="1" />
              </div>
            </div>

            <div class="admin-field">
              <span class="admin-field__label">{{ $t('ai.settings.retentionDays') }}</span>
              <p class="admin-field__desc">{{ $t('ai.settings.helpRetentionDays') }}</p>
              <div class="admin-field__control admin-field__control--narrow">
                <t-input-number v-model="form.retention_days" theme="normal" :min="1" :max="365" :step="1" />
              </div>
            </div>

            <div class="admin-field admin-field--switch">
              <span class="admin-field__label">{{ $t('ai.settings.redactPii') }}</span>
              <div class="admin-field__control">
                <t-switch v-model="form.redact_pii" />
              </div>
              <p class="admin-field__desc">{{ $t('ai.settings.redactPiiTip') }}</p>
            </div>
          </div>
        </t-card>

        <!-- ⑦ 权限模式：默认档位不能高于站点最高档位，这里是全页唯一的校验项 -->
        <t-card :bordered="false" class="ai-settings-card">
          <h2 class="ai-settings-card__title">{{ $t('ai.settings.sectionPermission') }}</h2>

          <div class="admin-field-grid">
            <div class="admin-field">
              <span class="admin-field__label">{{ $t('ai.settings.defaultPermissionMode') }}</span>
              <p class="admin-field__desc">{{ $t('ai.settings.helpDefaultPermissionMode') }}</p>
              <div class="admin-field__control admin-field__control--medium">
                <t-select v-model="form.default_permission_mode">
                  <t-option
                    v-for="option in permissionModeOptions"
                    :key="option.value"
                    :label="option.label"
                    :value="option.value"
                  />
                </t-select>
              </div>
            </div>

            <!-- t-form-item 只为了校验提示：字段本身仍是 .admin-field 结构 -->
            <t-form-item name="max_permission_mode">
              <div class="admin-field">
                <span class="admin-field__label">{{ $t('ai.settings.maxPermissionMode') }}</span>
                <p class="admin-field__desc">{{ $t('ai.settings.helpMaxPermissionMode') }}</p>
                <div class="admin-field__control admin-field__control--medium">
                  <t-select v-model="form.max_permission_mode">
                    <t-option
                      v-for="option in permissionModeOptions"
                      :key="option.value"
                      :label="option.label"
                      :value="option.value"
                    />
                  </t-select>
                </div>
              </div>
            </t-form-item>
          </div>
        </t-card>
      </t-form>
    </t-loading>

    <!-- 底部固定操作区：保存与连通性测试常驻，测试结论就地显示 -->
    <div class="ai-settings-actions">
      <p
        v-if="testResult"
        class="ai-settings-actions__result"
        :class="testResult.ok ? 'is-ok' : 'is-error'"
        role="status"
      >
        <t-icon :name="testResult.ok ? 'check-circle-filled' : 'error-circle-filled'" />
        <span>{{ testResultText }}</span>
      </p>
      <span v-else class="ai-settings-actions__hint">{{ $t('ai.settings.saveFirst') }}</span>

      <t-button variant="outline" :loading="testing" :disabled="saving" @click="handleTest">
        {{ testing ? $t('ai.settings.testing') : $t('ai.settings.test') }}
      </t-button>
      <t-button theme="primary" :loading="saving" :disabled="testing" @click="handleSave">
        {{ $t('ai.settings.save') }}
      </t-button>
    </div>
  </div>
</template>
<script setup lang="ts">
import type { FormInstanceFunctions, FormRule } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

import type { AiAdminSettings, AiAdminSettingsCatalog, AiAdminSettingsPayload, AiSettingsTestResult } from '@/api/ai';
import { AI_PERMISSION_MODES, AI_PROTOCOL_OPTIONS, AI_SETTINGS_DEFAULTS, aiAdminApi } from '@/api/ai';
import { t } from '@/locales';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminAiSettings' });

const loading = ref(false);
const saving = ref(false);
const testing = ref(false);
const testResult = ref<AiSettingsTestResult | null>(null);
const formRef = ref<FormInstanceFunctions>();

// 密钥输入框永远是空的：后端只回 has_value / masked_value，明文不回前端
const apiKeyInput = ref('');

const form = reactive<AiAdminSettings>({ ...AI_SETTINGS_DEFAULTS });

// 协议、权限档位、工具目录都由后端回显，前端不硬编码枚举文案
const catalog = ref<AiAdminSettingsCatalog>({
  protocols: [],
  permission_modes: [],
  tools: [],
  protocol_label: '',
});

const MODE_LABEL_KEYS: Record<string, string> = {
  read_only: 'ai.mode.readOnly',
  confirm: 'ai.mode.confirm',
  auto_approve: 'ai.mode.autoApprove',
};

const RISK_LABEL_KEYS: Record<string, string> = {
  read: 'ai.risk.read',
  write: 'ai.risk.write',
  dangerous: 'ai.risk.dangerous',
};

const protocolOptions = computed(() => {
  if (catalog.value.protocols.length) {
    return catalog.value.protocols.map((option) => ({ value: option.key, label: option.label }));
  }

  // 后端没回协议清单时退回本地三条，至少不让下拉空着
  return AI_PROTOCOL_OPTIONS.map((option) => ({ value: option.value, label: t(option.labelKey) }));
});

const permissionModeOptions = computed(() => {
  if (catalog.value.permission_modes.length) {
    return catalog.value.permission_modes.map((option) => ({ value: option.key, label: option.label }));
  }

  return AI_PERMISSION_MODES.map((mode) => ({ value: mode, label: t(MODE_LABEL_KEYS[mode] || 'ai.mode.confirm') }));
});

const toolOptions = computed(() => catalog.value.tools);

// 端点跟着协议变，写在说明里比塞进 placeholder 更容易看见
const protocolHelp = computed(() => {
  const endpoint = AI_PROTOCOL_OPTIONS.find((option) => option.value === form.protocol)?.endpoint || '-';
  return t('ai.settings.protocolEndpoint', { endpoint });
});

// 密钥：已配置时只回显掩码，输入框留空即保留旧值
const apiKeyHelp = computed(() => {
  const state = form.api_key_has_value
    ? t('ai.settings.apiKeyConfigured', { masked: form.api_key_masked || '******' })
    : t('ai.settings.apiKeyEmpty');

  return `${state} · ${t('ai.settings.apiKeyPlaceholder')}`;
});

// 最高档位低于默认档位时，用户的默认选择会被后端夹回，属于自相矛盾的配置
const permissionRank = (mode: string) => AI_PERMISSION_MODES.indexOf(mode as (typeof AI_PERMISSION_MODES)[number]);

const formRules: Record<string, FormRule[]> = {
  max_permission_mode: [
    {
      validator: (value: unknown) => permissionRank(String(value)) >= permissionRank(form.default_permission_mode),
      message: t('ai.settings.permissionOrderInvalid'),
      trigger: 'change',
    },
  ],
};

const testResultText = computed(() => {
  const result = testResult.value;
  if (!result) return '';

  const message = result.message || t(result.ok ? 'ai.settings.testSuccess' : 'ai.settings.testFailed');
  const parts = [message];
  if (result.model) parts.push(t('ai.settings.testModel', { model: result.model }));
  if (typeof result.latency_ms === 'number') parts.push(t('ai.settings.testLatency', { latency: result.latency_ms }));
  if (result.reply) parts.push(t('ai.settings.testReply', { reply: result.reply }));

  return parts.join(' · ');
});

function riskLabel(risk: string) {
  const key = RISK_LABEL_KEYS[String(risk || '')];
  return key ? t(key) : String(risk || '');
}

function handleEnabledToolsChange(value: unknown) {
  form.enabled_tools = Array.isArray(value) ? value.map((item) => String(item)) : [];
}

async function loadSettings() {
  loading.value = true;
  try {
    const view = await aiAdminApi.getSettings();
    Object.assign(form, view.settings);
    catalog.value = view.catalog;
    apiKeyInput.value = '';
    testResult.value = null;
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('ai.settings.loadFailed')));
  } finally {
    loading.value = false;
  }
}

function toInteger(value: unknown, fallback: number): number {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? Math.round(parsed) : fallback;
}

function toDecimal(value: unknown, fallback: number): number {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

async function handleSave() {
  const valid = await formRef.value?.validate();
  if (valid !== true) return;

  const payload: AiAdminSettingsPayload = {
    enabled: form.enabled,
    protocol: form.protocol,
    base_url: form.base_url.trim(),
    model: form.model.trim(),
    temperature: toDecimal(form.temperature, AI_SETTINGS_DEFAULTS.temperature),
    max_tokens: toInteger(form.max_tokens, AI_SETTINGS_DEFAULTS.max_tokens),
    console_enabled: form.console_enabled,
    site_enabled: form.site_enabled,
    admin_enabled: form.admin_enabled,
    allow_balance_pay: form.allow_balance_pay,
    user_daily_messages: toInteger(form.user_daily_messages, AI_SETTINGS_DEFAULTS.user_daily_messages),
    user_daily_tokens: toInteger(form.user_daily_tokens, AI_SETTINGS_DEFAULTS.user_daily_tokens),
    global_daily_tokens: toInteger(form.global_daily_tokens, AI_SETTINGS_DEFAULTS.global_daily_tokens),
    global_daily_cost_cents: toInteger(form.global_daily_cost_cents, AI_SETTINGS_DEFAULTS.global_daily_cost_cents),
    cost_per_1k_tokens_cents: toInteger(form.cost_per_1k_tokens_cents, AI_SETTINGS_DEFAULTS.cost_per_1k_tokens_cents),
    max_tool_rounds: toInteger(form.max_tool_rounds, AI_SETTINGS_DEFAULTS.max_tool_rounds),
    request_timeout_seconds: toInteger(form.request_timeout_seconds, AI_SETTINGS_DEFAULTS.request_timeout_seconds),
    history_message_limit: toInteger(form.history_message_limit, AI_SETTINGS_DEFAULTS.history_message_limit),
    redact_pii: form.redact_pii,
    retention_days: toInteger(form.retention_days, AI_SETTINGS_DEFAULTS.retention_days),
    default_permission_mode: form.default_permission_mode,
    max_permission_mode: form.max_permission_mode,
    // 空数组=放开全部工具，后端按这个语义存空串
    enabled_tools: [...form.enabled_tools],
  };

  // 留空即保留旧值：只有真的填了新的密钥才把 api_key 发上去
  const nextApiKey = apiKeyInput.value.trim();
  if (nextApiKey) {
    payload.api_key = nextApiKey;
  }

  saving.value = true;
  try {
    await aiAdminApi.saveSettings(payload);
    MessagePlugin.success(t('ai.settings.saveSuccess'));
    await loadSettings();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('ai.settings.saveFailed')));
  } finally {
    saving.value = false;
  }
}

async function handleTest() {
  testing.value = true;
  testResult.value = null;
  try {
    const result = await aiAdminApi.testSettings();
    testResult.value = result;
    if (result.ok) {
      MessagePlugin.success(testResultText.value);
    } else {
      MessagePlugin.error(testResultText.value);
    }
  } catch (error) {
    // 连通性测试失败会被后端抛成业务异常，此时错误文案本身就是中文结论
    const message = errorMessage(error, t('ai.settings.testRequestFailed'));
    testResult.value = { ok: false, message, model: '', protocol: '', reply: '', latency_ms: null };
    MessagePlugin.error(message);
  } finally {
    testing.value = false;
  }
}

onMounted(loadSettings);
</script>
<style scoped lang="less">
.ai-settings-page {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xl);
  padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
}

.ai-settings-loading {
  display: block;
}

/* 七个功能分区各占一张卡片，卡片之间统一用 xl 间距隔开 */
.ai-settings-form {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xl);
}

.ai-settings-card {
  :deep(.t-card__body) {
    padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
  }
}

.ai-settings-card__title {
  margin: 0 0 var(--td-comp-margin-l);
  font: var(--td-font-title-small);
  color: var(--td-text-color-primary);
}

/*
 * t-form 只承担校验：标签与说明都在 .admin-field 里，
 * 留着 t-form-item 自带的标签行与下边距会在栅格里多出一层间距。
 */
.ai-settings-form :deep(.t-form__item) {
  display: block;
  min-width: 0;
  margin-bottom: 0;
}

.ai-settings-form :deep(.t-form__controls) {
  margin-left: 0;
}

.ai-settings-form :deep(.t-form__controls-content) {
  display: block;
  width: 100%;
}

/* 控件铺满自己的格子；需要收窄的由 --narrow / --medium 修饰符决定 */
.ai-settings-form :deep(.admin-field__control > .t-input),
.ai-settings-form :deep(.admin-field__control > .t-select),
.ai-settings-form :deep(.admin-field__control > .t-input-number) {
  width: 100%;
}

.ai-settings-tool {
  display: flex;
  align-items: baseline;
  gap: var(--td-comp-margin-s);
  min-width: 0;
}

.ai-settings-tool__name {
  flex: none;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  color: var(--td-text-color-primary);
}

.ai-settings-tool__risk {
  flex: none;
  padding: 0 var(--td-comp-paddingLR-xs);
  border-radius: var(--td-radius-round);
  background: var(--td-bg-color-container-hover);
  font-size: var(--td-font-size-body-small);
  color: var(--td-text-color-secondary);
}

.ai-settings-tool__desc {
  overflow: hidden;
  font-size: var(--td-font-size-body-small);
  color: var(--td-text-color-placeholder);
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* 操作区吸在视口底部：改完最后一项不用滚回顶部才能保存 */
.ai-settings-actions {
  position: sticky;
  bottom: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--td-comp-margin-s);
  /* 右下角的 AI 浮球是 fixed 定位，右侧多留一个浮球宽度，别让浮球压住主按钮 */
  padding: var(--td-comp-paddingTB-s) calc(var(--td-comp-paddingLR-l) + 64px) var(--td-comp-paddingTB-s)
    var(--td-comp-paddingLR-l);
  border-top: 1px solid var(--td-component-stroke);
  border-radius: var(--td-radius-medium);
  background: var(--td-bg-color-container);
  box-shadow: var(--td-shadow-1);
}

.ai-settings-actions__hint,
.ai-settings-actions__result {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-size: var(--td-font-size-body-small);
  line-height: var(--td-line-height-body-small);
  color: var(--td-text-color-placeholder);
}

/* 状态用浅底标签，不用高饱和纯色文字 */
.ai-settings-actions__result {
  display: flex;
  align-items: center;
  gap: var(--td-comp-margin-xs);
  padding: var(--td-comp-paddingTB-xxs) var(--td-comp-paddingLR-xs);
  border-radius: var(--td-radius-default);
  background: var(--td-bg-color-container-hover);
  color: var(--td-text-color-secondary);
  word-break: break-word;

  &.is-ok {
    background: var(--td-success-color-light);
    color: var(--td-success-color);
  }

  &.is-error {
    background: var(--td-error-color-light);
    color: var(--td-error-color);
  }
}

@media (width <= 768px) {
  .ai-settings-page {
    padding: 12px;
  }

  /* 窄屏控件铺满整行，收窄的修饰符在这里失效，加减号也不会被挤成小点 */
  .ai-settings-form :deep(.admin-field__control--narrow),
  .ai-settings-form :deep(.admin-field__control--medium) {
    max-width: none;
  }

  .ai-settings-actions {
    flex-wrap: wrap;
    padding-right: calc(12px + 56px);
  }

  .ai-settings-actions__hint,
  .ai-settings-actions__result {
    flex: 1 0 100%;
  }
}
</style>
