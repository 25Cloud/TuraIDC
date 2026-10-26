<template>
  <div class="ticket-pre-reply-page">
    <t-card :bordered="false" class="ticket-pre-reply-card">
      <div class="ticket-pre-reply-toolbar">
        <div class="ticket-pre-reply-summary">
          <strong>{{ $t('tickets.preReplySettings.title') }}</strong>
          <span>{{ $t('tickets.preReplySettings.titleTip') }}</span>
        </div>
      </div>

      <!-- 每个字段都是「标签一行 → 说明一行 → 控件一行」，
           说明不跟控件抢同一行，也不会被挤成贴着开关的一条窄字。 -->
      <t-form ref="formRef" class="ticket-pre-reply-form" :data="form" :rules="formRules">
        <div class="admin-field admin-field--switch">
          <span class="admin-field__label">{{ $t('tickets.preReplySettings.enablePreReply') }}</span>
          <div class="admin-field__control">
            <t-switch v-model="form.enabled" />
          </div>
          <p class="admin-field__desc">{{ $t('tickets.preReplySettings.enablePreReplyTip') }}</p>
        </div>
        <!-- 校验仍交给 t-form-item：两个必填字段在启用预回复后才生效，错误文案紧跟在控件下方 -->
        <t-form-item name="admin_user_id">
          <div class="admin-field">
            <span class="admin-field__label" :class="{ 'admin-field__label--required': form.enabled }">{{
              $t('tickets.preReplySettings.replyAdmin')
            }}</span>
            <p class="admin-field__desc">{{ $t('tickets.preReplySettings.replyAdminTip') }}</p>
            <div class="admin-field__control admin-field__control--medium">
              <t-select
                v-model="form.admin_user_id"
                :disabled="!form.enabled"
                filterable
                clearable
                :placeholder="$t('tickets.preReplySettings.replyAsPlaceholder')"
              >
                <t-option v-for="admin in adminUsers" :key="admin.id" :label="adminLabel(admin)" :value="admin.id" />
              </t-select>
            </div>
          </div>
        </t-form-item>
        <t-form-item name="content">
          <div class="admin-field">
            <span class="admin-field__label" :class="{ 'admin-field__label--required': form.enabled }">{{
              $t('tickets.preReplySettings.replyContent')
            }}</span>
            <div class="admin-field__control">
              <t-textarea
                v-model="form.content"
                :disabled="!form.enabled"
                :autosize="{ minRows: 4, maxRows: 8 }"
                maxlength="5000"
                :placeholder="$t('tickets.preReplySettings.replyContentPlaceholder')"
              />
            </div>
          </div>
        </t-form-item>
        <div class="admin-field">
          <span class="admin-field__label">{{ $t('tickets.preReplySettings.upstreamReplyContent') }}</span>
          <p class="admin-field__desc">{{ $t('tickets.preReplySettings.upstreamContentTip') }}</p>
          <div class="admin-field__control">
            <t-textarea
              v-model="form.upstream_content"
              :disabled="!form.enabled"
              :autosize="{ minRows: 4, maxRows: 8 }"
              maxlength="5000"
              :placeholder="$t('tickets.preReplySettings.upstreamContentPlaceholder')"
            />
          </div>
        </div>
      </t-form>
    </t-card>

    <!-- 保存操作条与设置页一致：吸在视口底部右对齐，滚到哪都能保存 -->
    <div v-if="canManage" class="ticket-pre-reply-actions">
      <t-button theme="primary" :loading="saving" @click="save">{{
        $t('tickets.preReplySettings.saveSettings')
      }}</t-button>
    </div>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import type { FormInstanceFunctions, FormRule } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

import type { TicketAdminUser, TicketPreReplySettingsPayload } from '@/api/admin';
import { adminApi } from '@/api/admin';
import { AdminPermissions, hasPermissionInList } from '@/constants/permissions';
import { t } from '@/locales';
import { useUserStore } from '@/store';
import { errorMessage } from '@/utils/userMessage';

const userStore = useUserStore();
const saving = ref(false);
const formRef = ref<FormInstanceFunctions>();
const adminUsers = ref<TicketAdminUser[]>([]);

function hasPermission(permission: string) {
  const permissions = userStore.userInfo?.permissions || [];
  return hasPermissionInList(permissions, permission);
}

// 路由层已挂 ticket.pre_reply_manage，这里再做按钮显示与保存入口双保险，
// 防止权限收紧后入口仍可用。
const canManage = computed(() => hasPermission(AdminPermissions.TICKET_PRE_REPLY_MANAGE));

const form = reactive({
  enabled: false,
  admin_user_id: '' as number | string,
  content: '',
  upstream_content: '',
});

const formRules: Record<string, FormRule[]> = {
  admin_user_id: [
    {
      // 后端未配置时返回 0，同样视为未选择。
      validator: (value) =>
        !form.enabled || (value !== '' && value !== null && value !== undefined && Number(value) > 0),
      message: t('tickets.preReplySettings.replyAdminRequired'),
      type: 'error',
    },
  ],
  content: [
    {
      validator: (value) => !form.enabled || String(value || '').trim() !== '',
      message: t('tickets.preReplySettings.replyContentRequired'),
      type: 'error',
    },
  ],
};

function adminLabel(admin: TicketAdminUser): string {
  return admin.nickname || admin.username || String(admin.id);
}

async function loadConfig() {
  try {
    const response = await adminApi.tickets.preReply.config();
    adminUsers.value = response.admin_users || [];
    const settings = response.settings || {};
    form.enabled = Boolean(settings.enabled);
    form.admin_user_id = settings.admin_user_id ?? '';
    form.content = settings.content || '';
    form.upstream_content = settings.upstream_content || '';
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('tickets.preReplySettings.loadFailed')));
  }
}

async function save() {
  // 无 ticket.pre_reply_manage 权限时直接拒绝调用（路由守卫之外的双保险）。
  if (!canManage.value) {
    MessagePlugin.error(t('tickets.preReplySettings.noPermission'));
    return;
  }

  // 启用预回复但未选择管理员或未填写内容时，展示字段级提示并阻止提交。
  const valid = await formRef.value?.validate?.();
  if (valid !== true) return;

  saving.value = true;
  try {
    const payload: TicketPreReplySettingsPayload = { enabled: form.enabled };
    if (form.enabled) {
      // 仅启用时提交管理员与内容；停用时只提交开关，后端保留已保存的配置。
      payload.admin_user_id = form.admin_user_id;
      payload.content = form.content;
      payload.upstream_content = form.upstream_content;
    }
    await adminApi.tickets.preReply.save(payload);
    MessagePlugin.success(t('tickets.preReplySettings.saveSuccess'));
    // 保存成功后重新加载，确保表单与后端实际持久化的配置一致。
    await loadConfig();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('tickets.preReplySettings.saveFailed')));
  } finally {
    saving.value = false;
  }
}

onMounted(loadConfig);
</script>
