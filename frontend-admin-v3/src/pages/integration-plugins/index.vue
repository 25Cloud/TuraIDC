<template>
  <div class="integration-plugins-page">
    <t-card v-if="activeDomain === 'upstream'" :bordered="false" class="upstream-origin-check-card">
      <div class="upstream-origin-check">
        <div class="upstream-origin-check__text">
          <strong>{{ $t('plugins.upstream.originCheckTitle') }}</strong>
          <span>{{ $t('plugins.upstream.originCheckHint') }}</span>
        </div>
        <t-switch
          v-model="originCheckEnabled"
          :loading="panelSettingsLoading || originCheckSaving"
          :disabled="!canManageSettings"
          @change="saveOriginCheck"
        />
      </div>
    </t-card>

    <t-card v-if="activeDomain === 'upstream'" :bordered="false" class="upstream-origin-check-card">
      <div class="upstream-origin-check">
        <div class="upstream-origin-check__text">
          <strong>{{ $t('plugins.upstream.panelPreloadTitle') }}</strong>
          <span>{{ $t('plugins.upstream.panelPreloadHint') }}</span>
        </div>
        <t-switch
          v-model="areaPreloadAllEnabled"
          :loading="panelSettingsLoading || areaPreloadAllSaving"
          :disabled="!canManageSettings"
          @change="saveAreaPreloadAll"
        />
      </div>
    </t-card>

    <t-card :bordered="false">
      <div class="plugins-toolbar">
        <t-space>
          <t-button v-if="canManagePlugins" theme="primary" :loading="scanning" @click="scanPlugins">
            {{ $t('plugins.index.scan') }}
          </t-button>
        </t-space>
      </div>
    </t-card>

    <div v-if="loading" class="plugins-state">
      <t-loading :text="$t('plugins.index.loading')" />
    </div>
    <t-empty v-else-if="plugins.length === 0" :description="$t('plugins.index.empty')" />
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
            {{ $t('plugins.index.install') }}
          </t-button>
          <template v-else>
            <t-button variant="outline" @click="openConfig(plugin)">{{
              canManagePlugins ? $t('plugins.index.manage') : $t('plugins.index.view')
            }}</t-button>
            <t-button
              v-if="plugin.is_enabled && canManagePlugins"
              theme="warning"
              variant="outline"
              :loading="actionLoading === actionKey(plugin, 'disable')"
              @click="disablePlugin(plugin)"
            >
              {{ $t('plugins.index.disable') }}
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
              {{ $t('plugins.index.enable') }}
            </t-button>
            <t-button
              v-if="canTestPlugins"
              variant="text"
              :loading="actionLoading === actionKey(plugin, 'health')"
              @click="healthCheck(plugin)"
            >
              {{ $t('plugins.index.healthCheck') }}
            </t-button>
            <t-button v-if="plugin.id && plugin.is_enabled" variant="text" @click="openRuntimeLogs(plugin)">
              {{ $t('plugins.index.logs') }}
            </t-button>
            <t-button
              v-if="canManagePlugins && !plugin.is_enabled"
              theme="danger"
              variant="text"
              :loading="actionLoading === actionKey(plugin, 'delete')"
              @click="deletePlugin(plugin)"
            >
              {{ $t('plugins.index.delete') }}
            </t-button>
          </template>
        </div>
      </article>
    </section>

    <t-drawer
      v-model:visible="configVisible"
      size="560px"
      :header="currentPlugin ? currentPlugin.name + $t('plugins.index.manageSuffix') : $t('plugins.index.manageTitle')"
      :confirm-btn="
        canManagePlugins
          ? { content: $t('plugins.index.saveConfig'), loading: savingConfig, disabled: configLoading }
          : null
      "
      :cancel-btn="$t('plugins.index.close')"
      @confirm="saveConfig"
    >
      <div v-if="configLoading" class="plugin-config-loading">
        <t-loading :text="$t('plugins.index.loadingConfig')" />
      </div>
      <template v-else-if="currentPlugin">
        <div class="plugin-detail">
          <dl>
            <div>
              <dt>{{ $t('plugins.detail.directory') }}</dt>
              <dd>{{ currentPlugin.slug }}</dd>
            </div>
            <div>
              <dt>{{ $t('plugins.detail.businessKey') }}</dt>
              <dd>{{ currentPlugin.key }}</dd>
            </div>
            <div>
              <dt>{{ $t('plugins.detail.entryClass') }}</dt>
              <dd>{{ currentPlugin.entry_class || '-' }}</dd>
            </div>
            <div>
              <dt>{{ $t('plugins.detail.installedAt') }}</dt>
              <dd>{{ currentPlugin.installed_at || '-' }}</dd>
            </div>
          </dl>
        </div>

        <t-form label-align="top" class="plugin-config-form">
          <template v-for="field in visibleSchema" :key="field.key">
            <!-- 可折叠分组标题：高级项默认收起，点击展开，避免管理员误改默认值 -->
            <button
              v-if="field.type === 'divider' && field.collapsible"
              type="button"
              class="plugin-config-section"
              :aria-expanded="!collapsedSections[field.key]"
              @click="toggleSection(field.key)"
            >
              <chevron-right-icon
                class="plugin-config-section__arrow"
                :class="{ 'is-expanded': !collapsedSections[field.key] }"
              />
              <span class="plugin-config-section__title">{{ fieldLabel(field) }}</span>
              <span class="plugin-config-section__hint">
                {{ collapsedSections[field.key] ? $t('plugins.index.expandConfig') : $t('plugins.index.collapse') }}
              </span>
            </button>
            <t-divider v-else-if="field.type === 'divider'" align="left">{{ fieldLabel(field) }}</t-divider>
            <t-alert
              v-else-if="field.type === 'notice'"
              v-show="!isSectionCollapsed(field)"
              class="plugin-config-notice"
              :theme="noticeTheme(field)"
              :message="field.content || field.description || fieldLabel(field)"
            />
            <t-form-item
              v-else
              v-show="!isSectionCollapsed(field)"
              :label="fieldLabel(field)"
              :class="fieldWidthClass(field)"
            >
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
                          {{ account.enabled === false ? $t('plugins.smtp.paused') : $t('plugins.smtp.enabled') }}
                        </t-tag>
                      </div>
                      <div class="smtp-account-item__meta">
                        <span>{{ account.username || '-' }}</span>
                        <span>{{ $t('plugins.smtp.port') }} {{ account.port || '-' }}</span>
                        <span>{{ account.encryption || $t('plugins.smtp.autoEncryption') }}</span>
                        <span>{{
                          account.password_configured || account.password
                            ? $t('plugins.smtp.passwordConfigured')
                            : $t('plugins.smtp.passwordNotConfigured')
                        }}</span>
                      </div>
                    </div>
                    <t-dropdown
                      v-if="smtpAccountActionOptions(account).length"
                      trigger="click"
                      placement="bottom-right"
                      :options="smtpAccountActionOptions(account)"
                      @click="handleSmtpAccountActionHandler(index)"
                    >
                      <t-button variant="text" shape="square">
                        <more-icon />
                      </t-button>
                    </t-dropdown>
                  </div>
                </div>
                <t-empty v-else :description="$t('plugins.smtp.empty')" />
                <t-button
                  v-if="canManagePlugins"
                  variant="outline"
                  class="smtp-account-add"
                  @click="openSmtpAccountDialog()"
                >
                  {{ $t('plugins.smtp.addAccount') }}
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
      </template>
    </t-drawer>

    <t-dialog
      v-model:visible="testVisible"
      width="560px"
      :header="currentPlugin ? currentPlugin.name + $t('plugins.index.testSuffix') : $t('plugins.index.testTitle')"
      :cancel-btn="$t('plugins.index.close')"
    >
      <template v-if="currentPlugin">
        <div v-if="currentPlugin.domain === 'sms'" class="plugin-test-section">
          <div class="plugin-test-section__header">
            <strong>{{ $t('plugins.test.sendTestSms') }}</strong>
            <span class="plugin-test-section__hint">{{ $t('plugins.test.sendTestSmsHint') }}</span>
          </div>
          <t-space direction="vertical" style="width: 100%">
            <t-input v-model="smsTestPhone" :placeholder="$t('plugins.test.phonePlaceholder')" maxlength="20" />
            <t-button block variant="outline" :loading="smsTesting" @click="sendTestSms">{{
              $t('plugins.test.sendTestSms')
            }}</t-button>
          </t-space>
        </div>

        <div v-else-if="currentPlugin.domain === 'mail'" class="plugin-test-section">
          <div class="plugin-test-section__header">
            <strong>{{ $t('plugins.test.sendTestEmail') }}</strong>
            <span class="plugin-test-section__hint">{{ $t('plugins.test.sendTestEmailHint') }}</span>
          </div>
          <t-space direction="vertical" style="width: 100%">
            <t-input v-model="systemEmailTestTo" :placeholder="$t('plugins.test.recipientPlaceholder')" />
            <t-button block variant="outline" :loading="emailTesting" @click="openSystemEmailTest">{{
              $t('plugins.test.sendTestEmail')
            }}</t-button>
          </t-space>
        </div>

        <div v-else-if="currentPlugin.domain === 'verification'" class="plugin-test-section">
          <div class="plugin-test-section__header">
            <strong>{{ $t('plugins.test.createVerificationTask') }}</strong>
            <span class="plugin-test-section__hint">{{ $t('plugins.test.createVerificationTaskHint') }}</span>
          </div>
          <t-space direction="vertical" style="width: 100%">
            <t-input
              v-model="verificationTestForm.real_name"
              :placeholder="$t('plugins.test.testNamePlaceholder')"
              maxlength="64"
            />
            <t-input
              v-model="verificationTestForm.card_no"
              :placeholder="$t('plugins.test.testIdCardPlaceholder')"
              maxlength="32"
            />
            <t-button block variant="outline" :loading="verificationTesting" @click="runVerificationTest">{{
              $t('plugins.test.createTestTask')
            }}</t-button>
          </t-space>
          <div v-if="verificationTestResult" class="plugin-test-result">
            <div class="plugin-test-result__item">
              <span class="plugin-test-result__label">{{ $t('plugins.test.verifyLink') }}</span>
              <t-input
                :model-value="String(verificationTestResult.verify_url || '')"
                readonly
                class="plugin-test-result__value"
              />
              <t-button variant="text" @click="copyText(String(verificationTestResult.verify_url || ''))">{{
                $t('plugins.test.copy')
              }}</t-button>
            </div>
            <div v-if="verificationTestResult.task_no" class="plugin-test-result__item">
              <span class="plugin-test-result__label">{{ $t('plugins.test.taskNo') }}</span>
              <span>{{ verificationTestResult.task_no }}</span>
            </div>
          </div>
        </div>

        <div v-else-if="currentPlugin.domain === 'payment'" class="plugin-test-section">
          <div class="plugin-test-section__header">
            <strong>{{ $t('plugins.test.createPaymentOrder') }}</strong>
            <span class="plugin-test-section__hint">{{ $t('plugins.test.createPaymentOrderHint') }}</span>
          </div>
          <t-button block variant="outline" :loading="paymentTesting" @click="runPaymentTest">{{
            $t('plugins.test.createPennyTestOrder')
          }}</t-button>
          <div v-if="paymentTestResult" class="plugin-test-result">
            <div class="plugin-test-result__item">
              <span class="plugin-test-result__label">{{ $t('plugins.test.payUrl') }}</span>
              <t-input
                :model-value="String(paymentTestResult.qr_code || '')"
                readonly
                class="plugin-test-result__value"
              />
              <t-button variant="text" @click="copyText(String(paymentTestResult.qr_code || ''))">{{
                $t('plugins.test.copy')
              }}</t-button>
            </div>
            <div v-if="paymentTestResult.out_trade_no" class="plugin-test-result__item">
              <span class="plugin-test-result__label">{{ $t('plugins.test.testOrderNo') }}</span>
              <span>{{ paymentTestResult.out_trade_no }}</span>
            </div>
          </div>
        </div>

        <div v-else-if="currentPlugin.domain === 'captcha'" class="plugin-test-section">
          <div class="plugin-test-section__header">
            <strong>{{ $t('plugins.test.captchaTest') }}</strong>
            <span class="plugin-test-section__hint">{{ $t('plugins.test.captchaTestHint') }}</span>
          </div>
          <t-button block variant="outline" :loading="captchaTesting" @click="runCaptchaTest">{{
            $t('plugins.test.popupCaptchaAndTest')
          }}</t-button>
          <div v-if="captchaTestResult" class="plugin-test-result">
            <div class="plugin-test-result__item">
              <span class="plugin-test-result__label">{{ $t('plugins.test.verifyResult') }}</span>
              <t-tag :theme="captchaTestResult.verified ? 'success' : 'danger'" variant="light">
                {{ captchaTestResult.verified ? $t('plugins.test.verifyPassed') : $t('plugins.test.verifyFailed') }}
              </t-tag>
            </div>
            <div v-if="captchaTestResult.message" class="plugin-test-result__item">
              <span>{{ captchaTestResult.message }}</span>
            </div>
            <div v-if="captchaTestResult.error_type" class="plugin-test-result__item">
              <span class="plugin-test-result__label">{{ $t('plugins.test.diagnostics') }}</span>
              <span>{{ captchaTestResult.error_type }}</span>
            </div>
          </div>
        </div>

        <div v-else-if="currentPlugin.domain === 'upstream'" class="plugin-test-section">
          <div class="plugin-test-section__header">
            <strong>{{ $t('plugins.test.connectionTest') }}</strong>
            <span class="plugin-test-section__hint">{{ $t('plugins.test.connectionTestHint') }}</span>
          </div>
          <t-button block variant="outline" :loading="connectionTesting" @click="runConnectionTest">{{
            $t('plugins.test.startConnectionTest')
          }}</t-button>
          <div v-if="connectionTestResult" class="plugin-test-result">
            <div class="plugin-test-result__item">
              <span class="plugin-test-result__label">{{ $t('plugins.test.connectionStatus') }}</span>
              <t-tag :theme="connectionTestResult.healthy ? 'success' : 'danger'" variant="light">
                {{
                  connectionTestResult.healthy ? $t('plugins.test.connectionOk') : $t('plugins.test.connectionError')
                }}
              </t-tag>
            </div>
            <div v-if="connectionTestResult.capability" class="plugin-test-result__item">
              <span class="plugin-test-result__label">{{ $t('plugins.test.capability') }}</span>
              <span>{{ connectionTestResult.capability }}</span>
            </div>
            <div v-if="connectionTestResult.message" class="plugin-test-result__item">
              <span>{{ connectionTestResult.message }}</span>
            </div>
          </div>
        </div>
      </template>
    </t-dialog>

    <t-dialog
      v-model:visible="smtpAccountDialogVisible"
      :header="editingSmtpAccountIndex >= 0 ? $t('plugins.smtp.editAccount') : $t('plugins.smtp.addAccountTitle')"
      width="520px"
      :confirm-btn="{ content: $t('plugins.smtp.saveAccount'), loading: savingConfig }"
      @confirm="confirmSmtpAccount"
    >
      <t-form label-align="top" class="smtp-account-form">
        <t-form-item :label="$t('plugins.smtp.host')">
          <t-input v-model="smtpAccountForm.host" :placeholder="$t('plugins.smtp.hostPlaceholder')" />
        </t-form-item>
        <t-form-item :label="$t('plugins.smtp.portLabel')">
          <t-input-number v-model="smtpAccountForm.port" :min="1" :max="65535" theme="column" />
        </t-form-item>
        <t-form-item :label="$t('plugins.smtp.account')">
          <t-input v-model="smtpAccountForm.username" :placeholder="$t('plugins.smtp.accountPlaceholder')" />
        </t-form-item>
        <t-form-item :label="$t('plugins.smtp.password')">
          <secret-input
            v-if="editingSmtpAccountIndex >= 0 && smtpAccountForm.password_configured"
            v-model="smtpAccountForm.password"
            :has-value="smtpAccountForm.password_configured"
            :placeholder="$t('plugins.smtp.configuredKeepBlank')"
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
            :placeholder="
              editingSmtpAccountIndex >= 0
                ? $t('plugins.smtp.configuredKeepBlank')
                : $t('plugins.smtp.passwordPlaceholder')
            "
          />
        </t-form-item>
        <t-form-item :label="$t('plugins.smtp.fromName')">
          <t-input v-model="smtpAccountForm.from_name" :placeholder="$t('plugins.smtp.fromNamePlaceholder')" />
        </t-form-item>
        <t-form-item :label="$t('plugins.smtp.encryption')">
          <t-select v-model="smtpAccountForm.encryption" clearable>
            <t-option :label="$t('plugins.smtp.encryptionAuto')" value="" />
            <t-option label="SSL" value="ssl" />
            <t-option label="TLS" value="tls" />
            <t-option :label="$t('plugins.smtp.encryptionNone')" value="none" />
          </t-select>
        </t-form-item>
        <t-form-item :label="$t('plugins.smtp.status')">
          <t-switch v-model="smtpAccountForm.enabled" />
        </t-form-item>
      </t-form>
    </t-dialog>
    <t-dialog
      v-model:visible="emailTestVisible"
      :header="$t('plugins.test.sendTestEmail')"
      width="480px"
      :confirm-btn="{ content: $t('plugins.test.send'), loading: emailTesting }"
      @confirm="sendTestEmail"
    >
      <t-form label-align="top" class="email-test-form">
        <t-form-item
          :label="$t('plugins.test.recipient')"
          :status="emailTestErrors.to ? 'error' : undefined"
          :help="emailTestErrors.to"
        >
          <t-input
            v-model="emailTestForm.to"
            :placeholder="$t('plugins.test.recipientPlaceholder')"
            @change="clearEmailTestError('to')"
          />
        </t-form-item>
      </t-form>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { BrowseIcon, BrowseOffIcon, ChevronRightIcon, MoreIcon } from 'tdesign-icons-vue-next';
import type { DropdownOption } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import type {
  IntegrationPluginConfigSchema,
  IntegrationPluginDomain,
  IntegrationPluginRecord,
  IntegrationPluginTestResult,
  IntegrationPluginTestResultData,
} from '@/api/admin/plugins';
import { pluginsApi } from '@/api/admin/plugins';
import { settingsApi } from '@/api/admin/settings';
import SecretInput from '@/components/secret-input/index.vue';
import { AdminPermissions } from '@/constants/permissions';
import { useGeeTestCaptcha } from '@/hooks/useGeeTestCaptcha';
import { hasAdminPermission } from '@/utils/permission';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminIntegrationPlugins' });

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
const configLoading = ref(false);
const testVisible = ref(false);
const healthCheckSequence = ref(0);
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

/** 可折叠分组的收起状态，键为该分组 divider 的 key */
const collapsedSections = ref<Record<string, boolean>>({});

/**
 * 给每个字段标注所属的可折叠分组。
 *
 * 规则：标了 collapsible 的 divider，其后直到下一个 divider 之前的字段都归入该分组；
 * 普通 divider 会把分组归属清空。divider 自身永远可见——它就是折叠开关。
 *
 * 这里返回带 __section 标记的副本而不是改变循环结构，模板中的字段渲染分支得以保持原样。
 */
const visibleSchema = computed(() => {
  let sectionKey: string | null = null;

  return currentSchema.value
    .filter((field) => isFieldVisible(field))
    .map((field) => {
      if (field.type === 'divider') {
        sectionKey = field.collapsible ? field.key : null;

        return { ...field, __section: null as string | null };
      }

      return { ...field, __section: sectionKey };
    });
});

function isSectionCollapsed(field: IntegrationPluginConfigSchema & { __section?: string | null }) {
  const section = field.__section;

  return Boolean(section) && collapsedSections.value[section as string] === true;
}

function toggleSection(key: string) {
  collapsedSections.value = {
    ...collapsedSections.value,
    [key]: !collapsedSections.value[key],
  };
}

/**
 * 按 schema 重建折叠状态。可折叠分组默认收起，除非显式声明 collapsed: false。
 *
 * 已有状态优先保留：openConfig 会先用列表里的插件数据渲染、随后再用 detail 覆盖，
 * 两次都会走到这里；不保留的话，用户在这个间隙展开的分组会被重新收起。
 * 切换到另一个插件时由调用方先清空状态。
 */
function initCollapsedSections() {
  const next: Record<string, boolean> = {};
  const current = collapsedSections.value;

  currentSchema.value.forEach((field) => {
    if (field.type === 'divider' && field.collapsible) {
      next[field.key] = field.key in current ? current[field.key] : field.collapsed !== false;
    }
  });

  collapsedSections.value = next;
}
const smtpAccounts = computed<SmtpAccountForm[]>(() => (Array.isArray(configForm.accounts) ? configForm.accounts : []));

const emailTestVisible = ref(false);
const emailTesting = ref(false);
const testingAccountIndex = ref(-1);
const emailTestForm = reactive({ to: '' });
const systemEmailTestTo = ref('');
const emailTestErrors = reactive<Record<'to', string>>({
  to: '',
});
const canManagePlugins = computed(() => hasAdminPermission(AdminPermissions.INTEGRATION_PLUGIN_MANAGE));
const canManageSettings = computed(() => hasAdminPermission(AdminPermissions.SETTINGS_MANAGE));
// 上游面板同源检测开关：设置项 system.console_module_origin_check_enabled
const originCheckEnabled = ref(true);
const originCheckSaving = ref(false);
// 面板区域首屏预热开关：设置项 system.console_area_preload_all_enabled，缺省开启
const areaPreloadAllEnabled = ref(true);
const areaPreloadAllSaving = ref(false);
// 两个开关都读 system 分组，一次请求一起取回，不必各打一遍
const panelSettingsLoading = ref(false);
const canTestPlugins = computed(() => hasAdminPermission(AdminPermissions.INTEGRATION_PLUGIN_TEST));
const canRevealPluginSecrets = computed(() => hasAdminPermission(AdminPermissions.INTEGRATION_PLUGIN_SECRET_REVEAL));
const EMAIL_PATTERN = /^[^\s@]+@[^\s@][^\s.@]*\.[^\s@]+$/;

const smsTestPhone = ref('');
const smsTesting = ref(false);
const smtpAccountPasswordEdited = ref(false);
const verificationTestForm = reactive({ real_name: '', card_no: '' });
const verificationTesting = ref(false);
const verificationTestResult = ref<IntegrationPluginTestResultData | null>(null);
const paymentTesting = ref(false);
const paymentTestResult = ref<IntegrationPluginTestResultData | null>(null);
const captchaTesting = ref(false);
const captchaTestResult = ref<IntegrationPluginTestResultData | null>(null);
const connectionTesting = ref(false);
const connectionTestResult = ref<IntegrationPluginTestResultData | null>(null);
const { verify: openCaptchaVerify } = useGeeTestCaptcha();
const MASKED_SECRET_VALUE = '********';
const visibleSecretKeys = reactive<Record<string, boolean>>({});
const loadedSecretValues = reactive<Record<string, string>>({});
const editedSecretKeys = reactive<Record<string, boolean>>({});
const loadingSecretKeys = reactive<Record<string, boolean>>({});

onMounted(() => {
  loadPlugins();
  loadUpstreamPanelSettings();
});

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
    loadUpstreamPanelSettings();
  },
);

watch(testVisible, (visible) => {
  if (!visible) {
    resetTestResults();
  }
});

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

/**
 * 「上游」域的两个面板开关一次取回：都在 system 分组里，
 * 分开请求会把同一个分组重复打一遍。
 */
async function loadUpstreamPanelSettings() {
  if (activeDomain.value !== 'upstream') return;

  panelSettingsLoading.value = true;
  try {
    const response = await settingsApi.list({ group: 'system', page_size: 100 });
    const list = response.list || [];
    // 空值等同「未配置」：返回 null 表示不覆盖，保持各自的默认值
    const read = (key: string): boolean | null => {
      const item = list.find((entry) => entry.key === key);
      const raw = item ? item.value : null;

      return raw === null || raw === '' ? null : isTruthySetting(raw);
    };

    const originCheck = read('console_module_origin_check_enabled');
    if (originCheck !== null) originCheckEnabled.value = originCheck;

    const preloadAll = read('console_area_preload_all_enabled');
    if (preloadAll !== null) areaPreloadAllEnabled.value = preloadAll;
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载上游面板设置失败'));
  } finally {
    panelSettingsLoading.value = false;
  }
}

function isTruthySetting(value: unknown): boolean {
  return [true, 1, '1', 'true', 'on'].includes(value as string | number | boolean);
}

async function saveOriginCheck(value: string | number | boolean) {
  if (!canManageSettings.value) {
    MessagePlugin.warning('当前账号无系统设置权限');
    originCheckEnabled.value = !value;
    return;
  }

  originCheckSaving.value = true;
  try {
    await settingsApi.save({
      group: 'system',
      settings: { console_module_origin_check_enabled: value ? 1 : 0 },
    });
    MessagePlugin.success(value ? '已开启上游面板同源检测' : '已关闭上游面板同源检测');
  } catch (error) {
    originCheckEnabled.value = !value;
    MessagePlugin.error(errorMessage(error, '保存同源检测设置失败'));
  } finally {
    originCheckSaving.value = false;
  }
}

async function saveAreaPreloadAll(value: string | number | boolean) {
  if (!canManageSettings.value) {
    MessagePlugin.warning('当前账号无系统设置权限');
    areaPreloadAllEnabled.value = !value;
    return;
  }

  areaPreloadAllSaving.value = true;
  try {
    await settingsApi.save({
      group: 'system',
      settings: { console_area_preload_all_enabled: value ? 1 : 0 },
    });
    MessagePlugin.success(value ? '已开启面板区域首屏预热' : '已关闭面板区域首屏预热');
  } catch (error) {
    areaPreloadAllEnabled.value = !value;
    MessagePlugin.error(errorMessage(error, '保存面板预热设置失败'));
  } finally {
    areaPreloadAllSaving.value = false;
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

const BINDING_TABLE_LABELS: Record<string, string> = {
  integration_plugin_bindings: '插件绑定',
  supplier_plugin_bindings: '供应商绑定',
  product_upstream_bindings: '商品上游绑定',
  service_upstream_bindings: '服务上游绑定',
};

function bindingSummary(plugin: IntegrationPluginRecord): string {
  return Object.entries(plugin.binding_counts || {})
    .filter(([, count]) => Number(count) > 0)
    .map(([table, count]) => `${BINDING_TABLE_LABELS[table] || table} ${count} 条`)
    .join('、');
}

function deletePlugin(plugin: IntegrationPluginRecord) {
  if (!canManagePlugins.value) return;
  if (!plugin.id) return;

  // 插件仍被业务绑定引用时，卸载会硬删这些绑定且无法恢复，必须让管理员看到影响范围再确认。
  const summary = bindingSummary(plugin);
  const force = Number(plugin.business_reference_count || 0) > 0 || summary !== '';

  const dialog = DialogPlugin.confirm({
    header: force ? '强制卸载插件' : '删除插件',
    body: force
      ? `插件「${plugin.name}」仍被业务数据引用${summary ? `（${summary}）` : ''}。卸载会一并删除这些绑定关系且无法恢复，确定继续吗？`
      : `确定删除插件「${plugin.name}」的安装记录和配置吗？插件目录文件不会被删除。`,
    confirmBtn: { content: force ? '强制卸载' : '确认删除', theme: 'danger' },
    async onConfirm() {
      dialog.destroy();
      await runAction(plugin, 'delete', async () => {
        await pluginsApi.remove(plugin.id as string | number, force);
        MessagePlugin.success('插件已删除');
        await loadPlugins();
        if (currentPlugin.value?.id === plugin.id) configVisible.value = false;
      });
    },
  });
}

async function healthCheck(plugin: IntegrationPluginRecord) {
  if (!canTestPlugins.value) return;
  if (!plugin.id) return;
  const sequence = ++healthCheckSequence.value;
  await runAction(plugin, 'health', async () => {
    const result = await pluginsApi.healthCheck(plugin.id as string | number);
    if (sequence !== healthCheckSequence.value) return;
    const healthy = result.detail?.result?.healthy !== false;
    if (!healthy) {
      MessagePlugin.error(String(result.message || '插件检测失败'));
      return;
    }

    MessagePlugin.success(String(result.message || '插件加载正常'));
    if (supportsSystemTest(plugin.domain)) {
      await openTest(plugin, sequence);
    }
  });
}

function supportsSystemTest(domain: IntegrationPluginDomain) {
  return ['mail', 'sms', 'verification', 'payment', 'captcha', 'upstream'].includes(domain);
}

async function openConfig(plugin: IntegrationPluginRecord) {
  if (!plugin.id) return;

  currentPlugin.value = plugin;
  // 切换插件时先清空折叠状态，避免沿用上一个插件的展开情况
  collapsedSections.value = {};
  fillConfigForm(plugin);
  initCollapsedSections();
  resetTestResults();
  configLoading.value = true;
  configVisible.value = true;

  try {
    const detail = await pluginsApi.detail(plugin.id);
    currentPlugin.value = detail;
    fillConfigForm(detail);
    initCollapsedSections();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载插件配置失败'));
  } finally {
    configLoading.value = false;
  }
}

async function openTest(plugin: IntegrationPluginRecord, sequence?: number) {
  if (!plugin.id) return;
  try {
    const detail = await pluginsApi.detail(plugin.id);
    if (sequence !== undefined && sequence !== healthCheckSequence.value) return;
    if (String(detail.id ?? '') !== String(plugin.id)) return;
    currentPlugin.value = detail;
    resetTestResults();
    testVisible.value = true;
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载插件测试失败'));
  }
}

function resetTestResults() {
  verificationTestResult.value = null;
  paymentTestResult.value = null;
  captchaTestResult.value = null;
  connectionTestResult.value = null;
  verificationTestForm.real_name = '';
  verificationTestForm.card_no = '';
  smsTestPhone.value = '';
  systemEmailTestTo.value = '';
}

async function openSystemEmailTest() {
  const to = systemEmailTestTo.value.trim();
  if (!to || !EMAIL_PATTERN.test(to)) {
    MessagePlugin.error('请输入正确的收件人邮箱');
    return;
  }

  if (!currentPlugin.value?.id) return;

  emailTesting.value = true;
  try {
    await pluginsApi.testEmail(currentPlugin.value.id, {
      account_index: 0,
      to,
    });
    MessagePlugin.success('测试邮件发送成功');
    systemEmailTestTo.value = '';
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '测试邮件发送失败'));
  } finally {
    emailTesting.value = false;
  }
}

async function saveConfig() {
  if (!canManagePlugins.value) {
    MessagePlugin.warning('当前账号无插件管理权限');
    return;
  }

  if (configLoading.value) {
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

function handleSmtpAccountActionHandler(index: number) {
  return (data: DropdownOption) => handleSmtpAccountAction(String(data.value), index);
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
    const dialog = DialogPlugin.confirm({
      header: '删除 SMTP 账号',
      body: '确定删除这个 SMTP 账号吗？',
      confirmBtn: { content: '确认删除', theme: 'danger' },
      async onConfirm() {
        dialog.destroy();
        smtpAccounts.value.splice(index, 1);
        await saveConfig();
      },
    });
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

interface FieldErrorPayload {
  response?: { data?: { data?: { errors?: Record<string, string[]> } } };
}

function extractFieldErrors(error: unknown) {
  const errors = (error as FieldErrorPayload)?.response?.data?.data?.errors;
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

function readTestResult(response: IntegrationPluginTestResult) {
  const raw = response?.detail?.result;
  if (!raw) return null;

  const data = raw.data && typeof raw.data === 'object' ? raw.data : {};
  const source = { ...data, ...raw };
  const rawData = source.raw && typeof source.raw === 'object' ? source.raw : {};
  const certifyId = String(source.certify_id || '');
  const taskNo = String(source.task_no || rawData.task_no || certifyId || '');

  return {
    ...source,
    message: String(source.message || data.message || response.message || ''),
    task_no: taskNo || undefined,
    verify_url: String(source.verify_url || rawData.verify_url || '') || undefined,
  } as IntegrationPluginTestResultData;
}

async function runVerificationTest() {
  if (!canTestPlugins.value) return;

  const realName = verificationTestForm.real_name.trim();
  const cardNo = verificationTestForm.card_no.trim();
  if (!realName || !cardNo) {
    MessagePlugin.error('请填写测试姓名和身份证号');
    return;
  }
  if (!currentPlugin.value?.id) return;

  verificationTesting.value = true;
  verificationTestResult.value = null;
  try {
    const response = await pluginsApi.testVerification(currentPlugin.value.id, {
      real_name: realName,
      card_no: cardNo,
    });
    const result = readTestResult(response);
    verificationTestResult.value = result;
    if (result?.success) {
      MessagePlugin.success(result.message || '测试任务创建成功');
      verificationTestForm.card_no = '';
    } else {
      MessagePlugin.error(result?.message || '测试任务创建失败');
    }
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '创建测试任务失败'));
  } finally {
    verificationTesting.value = false;
  }
}

async function runPaymentTest() {
  if (!canTestPlugins.value) return;
  if (!currentPlugin.value?.id) return;

  paymentTesting.value = true;
  paymentTestResult.value = null;
  try {
    const response = await pluginsApi.testPayment(currentPlugin.value.id);
    const result = readTestResult(response);
    paymentTestResult.value = result;
    if (result?.success) {
      MessagePlugin.success(result.message || '测试支付单创建成功');
    } else {
      MessagePlugin.error(result?.message || '测试支付单创建失败');
    }
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '创建测试支付单失败'));
  } finally {
    paymentTesting.value = false;
  }
}

async function runCaptchaTest() {
  if (!canTestPlugins.value) return;
  if (!currentPlugin.value?.id) return;

  captchaTesting.value = true;
  captchaTestResult.value = null;
  try {
    const captcha = (await openCaptchaVerify()) as Record<string, unknown> | null;
    if (!captcha) {
      MessagePlugin.error('未获取到验证结果');
      return;
    }

    const response = await pluginsApi.testCaptcha(currentPlugin.value.id, {
      lot_number: String(captcha.lot_number || ''),
      captcha_output: String(captcha.captcha_output || ''),
      pass_token: String(captcha.pass_token || ''),
      gen_time: String(captcha.gen_time || ''),
    });
    const result = readTestResult(response);
    captchaTestResult.value = result;
    if (result?.success) {
      MessagePlugin.success(result.message || '验证通过');
    } else {
      MessagePlugin.error(result?.message || '验证未通过');
    }
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '验证测试失败'));
  } finally {
    captchaTesting.value = false;
  }
}

async function runConnectionTest() {
  if (!canTestPlugins.value) return;
  if (!currentPlugin.value?.id) return;

  connectionTesting.value = true;
  connectionTestResult.value = null;
  try {
    const response = await pluginsApi.testConnection(currentPlugin.value.id);
    const result = readTestResult(response);
    connectionTestResult.value = result;
    if (result?.success) {
      MessagePlugin.success(result.message || '连接正常');
    } else {
      MessagePlugin.error(result?.message || '连接测试失败');
    }
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '连接测试失败'));
  } finally {
    connectionTesting.value = false;
  }
}

async function copyText(value: string) {
  if (!value) return;
  try {
    await navigator.clipboard.writeText(value);
    MessagePlugin.success('已复制');
  } catch {
    MessagePlugin.error('复制失败，请手动复制');
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
