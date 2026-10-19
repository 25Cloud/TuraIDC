<template>
  <t-dialog
    v-model:visible="remarkVisible"
    :header="$t('serviceConsole.remark.dialogHeader')"
    width="min(28rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <t-textarea
      v-model="remarkForm.remark"
      :autosize="{ minRows: 4, maxRows: 6 }"
      :maxlength="120"
      :placeholder="$t('serviceConsole.remark.placeholder')"
    />
    <template #footer>
      <t-button variant="outline" @click="remarkVisible = false">{{ $t('common.cancel') }}</t-button>
      <t-button theme="primary" :loading="remarkSubmitting" @click="submitRemark">{{
        $t('serviceConsole.remark.save')
      }}</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const { remarkVisible, remarkSubmitting, remarkForm, submitRemark } = useServiceConsoleContext();
</script>
