<template>
  <t-space :size="size === 'small' ? 'small' : 'medium'" break-line>
    <t-button
      v-if="!plugin.is_installed && canManage"
      theme="primary"
      :size="size"
      :loading="actionLoading === actionKey('install')"
      @click="emit('install')"
    >
      {{ $t('plugins.index.install') }}
    </t-button>
    <template v-else>
      <t-button variant="outline" :size="size" @click="emit('config')">
        {{ canManage ? $t('plugins.index.manage') : $t('plugins.index.view') }}
      </t-button>
      <t-button
        v-if="plugin.is_enabled && canManage"
        theme="warning"
        variant="outline"
        :size="size"
        :loading="actionLoading === actionKey('disable')"
        @click="emit('disable')"
      >
        {{ $t('plugins.index.disable') }}
      </t-button>
      <t-button
        v-else-if="canManage"
        theme="success"
        variant="outline"
        :size="size"
        :loading="actionLoading === actionKey('enable')"
        :disabled="Boolean(enableReason)"
        :title="enableReason"
        @click="emit('enable')"
      >
        {{ $t('plugins.index.enable') }}
      </t-button>
      <t-button
        v-if="canTest"
        variant="text"
        :size="size"
        :loading="actionLoading === actionKey('health')"
        @click="emit('health')"
      >
        {{ $t('plugins.index.healthCheck') }}
      </t-button>
      <t-button v-if="plugin.id && plugin.is_enabled" variant="text" :size="size" @click="emit('logs')">
        {{ $t('plugins.index.logs') }}
      </t-button>
      <t-button
        v-if="canManage && !plugin.is_enabled"
        theme="danger"
        variant="text"
        :size="size"
        :loading="actionLoading === actionKey('delete')"
        @click="emit('delete')"
      >
        {{ $t('plugins.index.delete') }}
      </t-button>
    </template>
  </t-space>
</template>
<script setup lang="ts">
/**
 * 插件操作按钮组。
 *
 * 卡片视图与列表视图共用同一套按钮与权限判断：此前只有卡片视图，
 * 本次补列表视图时若各写一份，启用/停用条件很快会分叉（已装的能停用、
 * 未装的不能启用之类）。这里只负责渲染与派发事件，业务动作留在页面里。
 */
import type { IntegrationPluginRecord } from '@/api/admin/plugins';

const props = withDefaults(
  defineProps<{
    plugin: IntegrationPluginRecord;
    actionLoading: string;
    canManage: boolean;
    canTest: boolean;
    /** 「启用」被禁用的原因；空串表示可启用。判断逻辑留在页面，两个视图共用 */
    enableReason?: string;
    size?: 'small' | 'medium' | 'large';
  }>(),
  { enableReason: '', size: 'medium' },
);

const emit = defineEmits<{
  install: [];
  config: [];
  disable: [];
  enable: [];
  health: [];
  logs: [];
  delete: [];
}>();

/** 与父页面 actionKey 保持一致：domain:slug:action */
function actionKey(action: string): string {
  return `${props.plugin.domain}:${props.plugin.slug}:${action}`;
}
</script>
