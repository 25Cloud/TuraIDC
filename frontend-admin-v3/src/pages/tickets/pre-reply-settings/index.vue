<template>
  <div class="ticket-pre-reply-page">
    <t-card :bordered="false" class="ticket-pre-reply-card">
      <div class="ticket-pre-reply-toolbar">
        <t-button v-if="canManage" theme="primary" :loading="saving" @click="save">保存设置</t-button>
      </div>

      <t-form
        ref="formRef"
        class="ticket-pre-reply-form"
        :data="form"
        :rules="formRules"
        :label-align="isMobile ? 'top' : 'right'"
        :label-width="isMobile ? undefined : '150px'"
      >
        <t-form-item label="启用预回复" name="enabled">
          <t-switch v-model="form.enabled" />
          <span class="ticket-pre-reply-hint">关闭时新建工单不自动回复</span>
        </t-form-item>
        <t-form-item label="预回复管理员" name="admin_user_id">
          <t-select
            v-model="form.admin_user_id"
            :disabled="!form.enabled"
            filterable
            clearable
            placeholder="选择以谁的名义回复"
          >
            <t-option v-for="admin in adminUsers" :key="admin.id" :label="adminLabel(admin)" :value="admin.id" />
          </t-select>
          <span class="ticket-pre-reply-hint">仅显示可回复工单的启用管理员</span>
        </t-form-item>
        <t-form-item label="回复内容" name="content">
          <t-textarea
            v-model="form.content"
            :disabled="!form.enabled"
            :autosize="{ minRows: 4, maxRows: 8 }"
            maxlength="5000"
            placeholder="例如：您的工单已收到，请耐心等待管理员回复。"
          />
        </t-form-item>
        <t-form-item label="上游工单预回复内容" name="upstream_content">
          <t-textarea
            v-model="form.upstream_content"
            :disabled="!form.enabled"
            :autosize="{ minRows: 4, maxRows: 8 }"
            maxlength="5000"
            placeholder="可选。命中工单传递规则、会推送到上游的工单使用该内容；留空则使用上方回复内容。"
          />
          <span class="ticket-pre-reply-hint">仅对会传递到上游的工单生效，留空则回退上方内容</span>
        </t-form-item>
      </t-form>
    </t-card>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { useWindowSize } from '@vueuse/core';
import type { FormInstanceFunctions, FormRule } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

import type { TicketAdminUser, TicketPreReplySettingsPayload } from '@/api/admin';
import { adminApi } from '@/api/admin';
import { AdminPermissions, hasPermissionInList } from '@/constants/permissions';
import { useUserStore } from '@/store';
import { errorMessage } from '@/utils/userMessage';

const userStore = useUserStore();
const { width } = useWindowSize();
const isMobile = computed(() => width.value < 768);
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
      message: '启用预回复时必须选择管理员账号',
      type: 'error',
    },
  ],
  content: [
    {
      validator: (value) => !form.enabled || String(value || '').trim() !== '',
      message: '启用预回复时必须填写回复内容',
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
    MessagePlugin.error(errorMessage(error, '加载工单预回复设置失败'));
  }
}

async function save() {
  // 无 ticket.pre_reply_manage 权限时直接拒绝调用（路由守卫之外的双保险）。
  if (!canManage.value) {
    MessagePlugin.error('无权限保存工单预回复设置');
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
    MessagePlugin.success('工单预回复设置已保存');
    // 保存成功后重新加载，确保表单与后端实际持久化的配置一致。
    await loadConfig();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存工单预回复设置失败'));
  } finally {
    saving.value = false;
  }
}

onMounted(loadConfig);
</script>
