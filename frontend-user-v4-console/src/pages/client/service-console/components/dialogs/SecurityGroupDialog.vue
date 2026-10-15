<template>
  <t-dialog v-model:visible="groupVisible" :header="$t('serviceConsole.securityGroup.title')" width="min(30rem, calc(100vw - 2rem))" destroy-on-close>
    <div class="dialog-form">
      <label>
        <span>{{ $t('serviceConsole.securityGroup.name') }}</span>
        <t-input v-model="groupForm.name" :maxlength="64" :placeholder="$t('serviceConsole.securityGroup.namePlaceholder')" />
      </label>
      <label>
        <span>{{ $t('serviceConsole.securityGroup.description') }}</span>
        <t-textarea
          v-model="groupForm.description"
          :autosize="{ minRows: 3, maxRows: 5 }"
          :maxlength="200"
          :placeholder="$t('serviceConsole.securityGroup.descriptionPlaceholder')"
        />
      </label>
    </div>
    <template #footer>
      <t-button variant="outline" @click="groupVisible = false">{{ $t('serviceConsole.securityGroup.cancel') }}</t-button>
      <t-button theme="primary" :loading="securityState.submitting" @click="submitSecurityGroup">{{ $t('serviceConsole.securityGroup.create') }}</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const { groupVisible, groupForm, securityState, submitSecurityGroup } = useServiceConsoleContext();
</script>
