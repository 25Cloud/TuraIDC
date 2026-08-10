<template>
  <t-dialog v-model:visible="groupVisible" header="新增安全组" width="min(30rem, calc(100vw - 2rem))" destroy-on-close>
    <div class="dialog-form">
      <label>
        <span>名称</span>
        <t-input v-model="groupForm.name" :maxlength="64" placeholder="请输入安全组名称" />
      </label>
      <label>
        <span>描述</span>
        <t-textarea
          v-model="groupForm.description"
          :autosize="{ minRows: 3, maxRows: 5 }"
          :maxlength="200"
          placeholder="选填"
        />
      </label>
    </div>
    <template #footer>
      <t-button variant="outline" @click="groupVisible = false">取消</t-button>
      <t-button theme="primary" :loading="securityState.submitting" @click="submitSecurityGroup">创建</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const { groupVisible, groupForm, securityState, submitSecurityGroup } = useServiceConsoleContext();
</script>
