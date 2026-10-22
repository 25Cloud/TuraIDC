<template>
  <t-dialog
    v-model:visible="remarkVisible"
    :header="$t('services.console.editRemark')"
    width="min(28rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <t-textarea
      v-model="remarkForm.remark"
      :autosize="{ minRows: 4, maxRows: 6 }"
      :maxlength="120"
      :placeholder="$t('services.console.remarkPlaceholder')"
    />
    <template #footer>
      <t-button variant="outline" @click="remarkVisible = false">{{ $t('services.console.cancel') }}</t-button>
      <t-button theme="primary" :loading="remarkSubmitting" @click="submitRemark">{{
        $t('services.console.saveRemark')
      }}</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const { remarkVisible, remarkSubmitting, remarkForm, submitRemark } = useServiceConsoleContext();
</script>
