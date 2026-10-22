<template>
  <t-dialog
    v-model:visible="nameVisible"
    :header="$t('services.console.renameInstanceTitle')"
    width="min(28rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <t-input v-model="nameForm.name" :maxlength="120" :placeholder="$t('services.console.instanceNamePlaceholder')" />
    <template #footer>
      <t-button variant="outline" @click="nameVisible = false">{{ $t('services.console.cancel') }}</t-button>
      <t-button theme="primary" :loading="nameSubmitting" @click="submitName">{{
        $t('services.console.saveName')
      }}</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const { nameVisible, nameSubmitting, nameForm, submitName } = useServiceConsoleContext();
</script>
