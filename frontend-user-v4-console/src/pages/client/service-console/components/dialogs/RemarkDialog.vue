<template>
  <t-dialog v-model:visible="remarkVisible" header="编辑备注" width="min(28rem, calc(100vw - 2rem))" destroy-on-close>
    <t-textarea
      v-model="remarkForm.remark"
      :autosize="{ minRows: 4, maxRows: 6 }"
      :maxlength="120"
      placeholder="填写实例备注，便于识别"
    />
    <template #footer>
      <t-button variant="outline" @click="remarkVisible = false">取消</t-button>
      <t-button theme="primary" :loading="remarkSubmitting" @click="submitRemark">保存备注</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const { remarkVisible, remarkSubmitting, remarkForm, submitRemark } = useServiceConsoleContext();
</script>
