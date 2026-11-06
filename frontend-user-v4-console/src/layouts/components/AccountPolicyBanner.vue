<template>
  <t-alert
    v-if="visible"
    class="account-policy-banner"
    theme="warning"
    :message="message"
    :close-btn="true"
    @close="handleClose"
  >
    <template #operation>
      <div class="account-policy-banner__actions">
        <t-button
          v-if="showVerificationAction"
          class="account-policy-banner__action"
          size="small"
          theme="primary"
          variant="outline"
          @click="goVerification"
        >
          {{ $t('accountPolicy.goVerification') }}
        </t-button>
        <t-button
          v-if="showBindAction"
          class="account-policy-banner__action"
          size="small"
          theme="primary"
          variant="outline"
          @click="goBind"
        >
          {{ $t('accountPolicy.goBind') }}
        </t-button>
      </div>
    </template>
  </t-alert>
</template>
<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

import type { ClientAccountPolicyItem } from '@/types/client';
import { currentMissingItems, currentPolicyMessage, policyItemRoute } from '@/utils/accountPolicy';

/**
 * 账户策略提示条。
 *
 * 只在后端下发了 `account_policy.missing_items` 且非空时出现，文案直接用后端 `message`，
 * 前端不拼「请实名/请绑定手机」这类判断——判据只有一份，在后端 AccountPolicyService。
 * 用户补齐后下一次 `auth/info` 刷新把 missing_items 变成空数组，提示条自动消失。
 */
const router = useRouter();

const closed = ref(false);
const missingItems = computed<ClientAccountPolicyItem[]>(() => currentMissingItems());
const message = computed(() => currentPolicyMessage());

const visible = computed(() => !closed.value && missingItems.value.length > 0 && message.value !== '');
const showVerificationAction = computed(() => missingItems.value.includes('verification'));
const showBindAction = computed(() => missingItems.value.includes('phone') || missingItems.value.includes('email'));

// 缺失项发生变化（用户补齐了一项）就重新展开：关闭只是「这次先不看」，不该盖住新的要求
watch(missingItems, () => {
  closed.value = false;
});

function handleClose() {
  closed.value = true;
}

function goVerification() {
  void router.push(policyItemRoute('verification'));
}

function goBind() {
  const target = missingItems.value.includes('phone') ? 'phone' : 'email';
  void router.push(policyItemRoute(target));
}
</script>
<style scoped lang="less">
.account-policy-banner {
  margin: var(--td-comp-margin-s) var(--td-comp-margin-l) 0;
}

.account-policy-banner__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
  align-items: center;
}

@media (max-width: @screen-sm-rem) {
  .account-policy-banner {
    margin: var(--td-comp-margin-s) var(--td-comp-margin-s) 0;
  }

  .account-policy-banner__actions :deep(.t-button) {
    width: auto;
  }
}
</style>
