<template>
  <div class="ai-settings-page">
    <t-card :bordered="false">
      <div class="ai-settings-toolbar">
        <strong class="ai-settings-toolbar__title">{{ $t('ai.settings.title') }}</strong>
      </div>

      <t-alert
        v-if="testResult"
        class="ai-settings-alert"
        :theme="testResult.ok ? 'success' : 'error'"
        :message="testMessage"
        close
        @close="testResult = null"
      />

      <t-loading :loading="loading" size="small" show-overlay>
        <t-form class="ai-settings-form" :data="form" label-align="top">
          <div class="ai-settings-section">
            <h2 class="ai-settings-section__title">{{ $t('ai.settings.sectionBasic') }}</h2>
            <div class="ai-settings-grid">
              <t-form-item :label="$t('ai.settings.enabled')" name="enabled">
                <t-switch v-model="form.enabled" />
                <span class="ai-settings-field-hint">{{ $t('ai.settings.enabledTip') }}</span>
              </t-form-item>

              <t-form-item :label="$t('ai.settings.protocol')" name="protocol">
                <t-select v-model="form.protocol">
                  <t-option
                    v-for="option in protocolOptions"
                    :key="option.value"
                    :label="option.label"
                    :value="option.value"
                  />
                </t-select>
                <span class="ai-settings-field-hint">
                  {{ $t('ai.settings.protocolEndpoint', { endpoint: protocolEndpoint }) }}
                </span>
              </t-form-item>

              <t-form-item :label="$t('ai.settings.baseUrl')" name="base_url">
                <t-input v-model="form.base_url" clearable :placeholder="$t('ai.settings.baseUrlPlaceholder')" />
              </t-form-item>

              <t-form-item :label="$t('ai.settings.model')" name="model">
                <t-input v-model="form.model" clearable :placeholder="$t('ai.settings.modelPlaceholder')" />
              </t-form-item>

              <t-form-item :label="$t('ai.settings.apiKey')" name="apiKey">
                <t-input
                  v-model="apiKeyInput"
                  type="password"
                  clearable
                  :placeholder="$t('ai.settings.apiKeyPlaceholder')"
                />
                <span class="ai-settings-field-hint">
                  {{
                    form.api_key_has_value
                      ? $t('ai.settings.apiKeyConfigured', { masked: form.api_key_masked || '******' })
                      : $t('ai.settings.apiKeyEmpty')
                  }}
                </span>
              </t-form-item>

              <t-form-item :label="$t('ai.settings.temperature')" name="temperature">
                <t-input-number v-model="form.temperature" theme="normal" :min="0" :max="2" :step="0.1" />
              </t-form-item>

              <t-form-item :label="$t('ai.settings.maxTokens')" name="max_tokens">
                <t-input-number v-model="form.max_tokens" theme="normal" :min="1" :max="200000" :step="128" />
              </t-form-item>
            </div>
          </div>

          <div class="ai-settings-section">
            <h2 class="ai-settings-section__title">{{ $t('ai.settings.sectionChannels') }}</h2>
            <div class="ai-settings-grid">
              <t-form-item :label="$t('ai.settings.consoleEnabled')" name="console_enabled">
                <t-switch v-model="form.console_enabled" />
              </t-form-item>

              <t-form-item :label="$t('ai.settings.siteEnabled')" name="site_enabled">
                <t-switch v-model="form.site_enabled" />
              </t-form-item>

              <t-form-item :label="$t('ai.settings.adminEnabled')" name="admin_enabled">
                <t-switch v-model="form.admin_enabled" />
              </t-form-item>

              <t-form-item :label="$t('ai.settings.allowBalancePay')" name="allow_balance_pay">
                <t-switch v-model="form.allow_balance_pay" />
                <span class="ai-settings-field-hint">{{ $t('ai.settings.allowBalancePayTip') }}</span>
              </t-form-item>
            </div>
          </div>

          <div class="ai-settings-section">
            <h2 class="ai-settings-section__title">{{ $t('ai.settings.sectionLimits') }}</h2>
            <div class="ai-settings-grid">
              <t-form-item :label="$t('ai.settings.userDailyMessages')" name="user_daily_messages">
                <t-input-number v-model="form.user_daily_messages" theme="normal" :min="0" :step="10" />
                <span class="ai-settings-field-hint">{{ $t('ai.settings.unlimitedTip') }}</span>
              </t-form-item>

              <t-form-item :label="$t('ai.settings.userDailyTokens')" name="user_daily_tokens">
                <t-input-number v-model="form.user_daily_tokens" theme="normal" :min="0" :step="1000" />
                <span class="ai-settings-field-hint">{{ $t('ai.settings.unlimitedTip') }}</span>
              </t-form-item>

              <t-form-item :label="$t('ai.settings.globalDailyTokens')" name="global_daily_tokens">
                <t-input-number v-model="form.global_daily_tokens" theme="normal" :min="0" :step="10000" />
                <span class="ai-settings-field-hint">{{ $t('ai.settings.unlimitedTip') }}</span>
              </t-form-item>

              <t-form-item :label="$t('ai.settings.globalDailyCostCents')" name="global_daily_cost_cents">
                <t-input-number v-model="form.global_daily_cost_cents" theme="normal" :min="0" :step="100" />
                <span class="ai-settings-field-hint">{{ $t('ai.settings.unlimitedTip') }}</span>
              </t-form-item>

              <t-form-item :label="$t('ai.settings.maxToolRounds')" name="max_tool_rounds">
                <t-input-number v-model="form.max_tool_rounds" theme="normal" :min="1" :max="20" />
              </t-form-item>

              <t-form-item :label="$t('ai.settings.requestTimeoutSeconds')" name="request_timeout_seconds">
                <t-input-number v-model="form.request_timeout_seconds" theme="normal" :min="5" :max="300" :step="5" />
              </t-form-item>

              <t-form-item :label="$t('ai.settings.historyMessageLimit')" name="history_message_limit">
                <t-input-number v-model="form.history_message_limit" theme="normal" :min="0" :max="100" />
              </t-form-item>

              <t-form-item :label="$t('ai.settings.retentionDays')" name="retention_days">
                <t-input-number v-model="form.retention_days" theme="normal" :min="0" :max="3650" :step="1" />
              </t-form-item>
            </div>
          </div>

          <div class="ai-settings-section">
            <h2 class="ai-settings-section__title">{{ $t('ai.settings.sectionSafety') }}</h2>
            <div class="ai-settings-grid">
              <t-form-item :label="$t('ai.settings.redactPii')" name="redact_pii">
                <t-switch v-model="form.redact_pii" />
                <span class="ai-settings-field-hint">{{ $t('ai.settings.redactPiiTip') }}</span>
              </t-form-item>

              <t-form-item :label="$t('ai.settings.defaultPermissionMode')" name="default_permission_mode">
                <t-select v-model="form.default_permission_mode">
                  <t-option
                    v-for="option in permissionModeOptions"
                    :key="option.value"
                    :label="option.label"
                    :value="option.value"
                  />
                </t-select>
              </t-form-item>

              <t-form-item :label="$t('ai.settings.maxPermissionMode')" name="max_permission_mode">
                <t-select v-model="form.max_permission_mode">
                  <t-option
                    v-for="option in permissionModeOptions"
                    :key="option.value"
                    :label="option.label"
                    :value="option.value"
                  />
                </t-select>
              </t-form-item>
            </div>
          </div>
        </t-form>
      </t-loading>

      <div class="ai-settings-footer">
        <span class="ai-settings-footer__hint">{{ $t('ai.settings.saveFirst') }}</span>
        <t-button variant="outline" :loading="testing" @click="handleTest">
          {{ testing ? $t('ai.settings.testing') : $t('ai.settings.test') }}
        </t-button>
        <t-button theme="primary" :loading="saving" @click="handleSave">
          {{ $t('ai.settings.save') }}
        </t-button>
      </div>
    </t-card>
  </div>
</template>
<script setup lang="ts">
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

import type { AiAdminSettings, AiAdminSettingsPayload } from '@/api/ai';
import { AI_PERMISSION_MODES, AI_PROTOCOL_OPTIONS, aiAdminApi } from '@/api/ai';
import { t } from '@/locales';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminAiSettings' });

const loading = ref(false);
const saving = ref(false);
const testing = ref(false);
const testResult = ref<{ ok: boolean; message: string } | null>(null);

// 密钥输入框永远是空的：后端只回 has_value / masked_value，明文不回前端
const apiKeyInput = ref('');

const form = reactive<AiAdminSettings>({
  enabled: false,
  protocol: 'chat_completions',
  base_url: '',
  model: '',
  temperature: 0.7,
  max_tokens: 2048,
  console_enabled: true,
  site_enabled: false,
  admin_enabled: false,
  allow_balance_pay: false,
  user_daily_messages: 0,
  user_daily_tokens: 0,
  global_daily_tokens: 0,
  global_daily_cost_cents: 0,
  max_tool_rounds: 6,
  request_timeout_seconds: 60,
  history_message_limit: 12,
  redact_pii: true,
  retention_days: 30,
  default_permission_mode: 'confirm',
  max_permission_mode: 'confirm',
  api_key_has_value: false,
  api_key_masked: '',
});

const MODE_LABEL_KEYS: Record<string, string> = {
  read_only: 'ai.mode.readOnly',
  confirm: 'ai.mode.confirm',
  auto_approve: 'ai.mode.autoApprove',
};

const protocolOptions = computed(() =>
  AI_PROTOCOL_OPTIONS.map((option) => ({ value: option.value, label: t(option.labelKey) })),
);

// 选中协议时把对应端点直接展示出来，避免站长填错地址后才发现打不通
const protocolEndpoint = computed(() => {
  return AI_PROTOCOL_OPTIONS.find((option) => option.value === form.protocol)?.endpoint || '';
});

const permissionModeOptions = computed(() =>
  AI_PERMISSION_MODES.map((mode) => ({ value: mode, label: t(MODE_LABEL_KEYS[mode] || 'ai.mode.confirm') })),
);

// 结论直接取后端返回的中文说明；掩码只在密钥字段下展示，不在这里再拼一次
const testMessage = computed(() => testResult.value?.message || '');

function applySettings(settings: AiAdminSettings) {
  Object.assign(form, settings);
  apiKeyInput.value = '';
}

async function loadSettings() {
  loading.value = true;
  try {
    applySettings(await aiAdminApi.getSettings());
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
  const payload: AiAdminSettingsPayload = {
    enabled: form.enabled,
    protocol: form.protocol,
    base_url: form.base_url.trim(),
    model: form.model.trim(),
    temperature: toDecimal(form.temperature, 0.7),
    max_tokens: toInteger(form.max_tokens, 2048),
    console_enabled: form.console_enabled,
    site_enabled: form.site_enabled,
    admin_enabled: form.admin_enabled,
    allow_balance_pay: form.allow_balance_pay,
    user_daily_messages: toInteger(form.user_daily_messages, 0),
    user_daily_tokens: toInteger(form.user_daily_tokens, 0),
    global_daily_tokens: toInteger(form.global_daily_tokens, 0),
    global_daily_cost_cents: toInteger(form.global_daily_cost_cents, 0),
    max_tool_rounds: toInteger(form.max_tool_rounds, 6),
    request_timeout_seconds: toInteger(form.request_timeout_seconds, 60),
    history_message_limit: toInteger(form.history_message_limit, 12),
    redact_pii: form.redact_pii,
    retention_days: toInteger(form.retention_days, 30),
    default_permission_mode: form.default_permission_mode,
    max_permission_mode: form.max_permission_mode,
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
    const message = result.message || t(result.ok ? 'ai.settings.testSuccess' : 'ai.settings.testFailed');
    testResult.value = { ok: result.ok, message };
    if (result.ok) {
      MessagePlugin.success(message);
    } else {
      MessagePlugin.error(message);
    }
  } catch (error) {
    // 连通性测试失败会被后端抛成业务异常，此时错误文案本身就是中文结论
    const message = errorMessage(error, t('ai.settings.testRequestFailed'));
    testResult.value = { ok: false, message };
    MessagePlugin.error(message);
  } finally {
    testing.value = false;
  }
}

onMounted(loadSettings);
</script>
<style scoped lang="less">
.ai-settings-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}

.ai-settings-toolbar__title {
  font-size: 15px;
  font-weight: 600;
  color: var(--td-text-color-primary, #1f2937);
}

.ai-settings-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid var(--td-component-stroke, #e7e7e7);
}

.ai-settings-footer__hint {
  flex: 1;
  min-width: 0;
  font-size: 12px;
  color: var(--td-text-color-placeholder, #a9aeb8);
}

.ai-settings-alert {
  margin-bottom: 16px;
}

.ai-settings-section {
  margin-bottom: 8px;
}

.ai-settings-section__title {
  margin: 0 0 8px;
  padding-left: 8px;
  border-left: 3px solid var(--td-brand-color);
  font-size: 13px;
  font-weight: 600;
  color: var(--td-text-color-primary, #1f2937);
}

.ai-settings-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 24px;
}

.ai-settings-field-hint {
  display: block;
  margin-top: 4px;
  font-size: 12px;
  line-height: 1.5;
  color: var(--td-text-color-placeholder, #a9aeb8);
}

@media (max-width: 768px) {
  .ai-settings-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .ai-settings-toolbar {
    align-items: flex-start;
  }
}
</style>
