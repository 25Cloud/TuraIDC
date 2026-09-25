<template>
  <div class="service-detail-page">
    <div class="detail-action-bar">
      <t-button variant="text" theme="default" @click="goBack">
        <template #icon><chevron-left-icon /></template>
        返回
      </t-button>
      <div class="detail-title">
        <h3>实例详情</h3>
        <span>服务 #{{ serviceId }}</span>
      </div>
      <t-button v-if="hasUser" variant="outline" @click="goUserDetail">查看用户</t-button>
    </div>

    <t-empty v-if="!hasUser" description="缺少用户上下文，请从用户详情或服务列表进入该页面">
      <t-button theme="primary" @click="goBack">返回服务列表</t-button>
    </t-empty>
    <console-panel v-else :key="userId" :user-id="userId" />
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { ChevronLeftIcon } from 'tdesign-icons-vue-next';
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import ConsolePanel from './ConsolePanel.vue';

defineOptions({ name: 'AdminServiceDetail' });

const route = useRoute();
const router = useRouter();

const serviceId = computed(() => Number(route.params.id || 0));
const userId = computed(() => String(route.query.user || ''));
const hasUser = computed(() => Number(userId.value) > 0);

function goBack() {
  if (window.history.length > 1) {
    router.back();
  } else {
    router.push({ name: 'AdminServices' });
  }
}

function goUserDetail() {
  if (hasUser.value) {
    router.push({ name: 'AdminUserDetail', params: { id: userId.value } });
  }
}
</script>
