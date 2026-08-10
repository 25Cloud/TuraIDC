<template>
  <t-dialog v-model:visible="nameVisible" header="修改实例名称" width="min(28rem, calc(100vw - 2rem))" destroy-on-close>
    <t-input v-model="nameForm.name" :maxlength="120" placeholder="填写便于识别的实例名称" />
    <template #footer>
      <t-button variant="outline" @click="nameVisible = false">取消</t-button>
      <t-button theme="primary" :loading="nameSubmitting" @click="submitName">保存名称</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const { nameVisible, nameSubmitting, nameForm, submitName } = useServiceConsoleContext();
</script>
