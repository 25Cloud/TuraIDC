<template>
  <t-dialog
    v-model:visible="groupVisible"
    :header="$t('services.console.securityGroup.title')"
    width="min(30rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <div class="dialog-form">
      <label>
        <span>{{ $t('services.console.securityGroup.name') }}</span>
        <t-input
          v-model="groupForm.name"
          :maxlength="64"
          :placeholder="$t('services.console.securityGroup.namePlaceholder')"
        />
      </label>
      <label>
        <span>{{ $t('services.console.securityGroup.description') }}</span>
        <t-textarea
          v-model="groupForm.description"
          :autosize="{ minRows: 3, maxRows: 5 }"
          :maxlength="200"
          :placeholder="$t('services.console.securityGroup.optional')"
        />
      </label>
    </div>
    <template #footer>
      <t-button variant="outline" @click="groupVisible = false">
        {{ $t('services.console.securityGroup.cancel') }}
      </t-button>
      <t-button theme="primary" :loading="securityState.submitting" @click="submitSecurityGroup">
        {{ $t('services.console.securityGroup.create') }}
      </t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const { groupVisible, groupForm, securityState, submitSecurityGroup } = useServiceConsoleContext();
</script>
