<template>
  <t-dialog
    v-model:visible="natVisible"
    :header="$t('services.console.natDialog.title')"
    :confirm-btn="$t('services.console.natDialog.create')"
    :confirm-loading="natState.submitting"
    @confirm="submitNatForwarding"
  >
    <t-form label-align="right" :label-width="96">
      <t-form-item :label="$t('services.console.natDialog.name')" required-mark>
        <t-input
          v-model="natForm.name"
          :placeholder="$t('services.console.natDialog.namePlaceholder')"
          :maxlength="255"
        />
      </t-form-item>
      <t-form-item :label="$t('services.console.natDialog.publicPort')">
        <t-input
          v-model="natForm.ext_port"
          :placeholder="$t('services.console.natDialog.publicPortPlaceholder')"
          :maxlength="5"
        />
      </t-form-item>
      <t-form-item :label="$t('services.console.natDialog.internalPort')" required-mark>
        <t-input
          v-model="natForm.int_port"
          :placeholder="$t('services.console.natDialog.internalPortPlaceholder')"
          :maxlength="5"
        />
      </t-form-item>
      <t-form-item :label="$t('services.console.natDialog.protocol')" required-mark>
        <t-select v-model="natForm.protocol" :placeholder="$t('services.console.natDialog.protocolPlaceholder')">
          <t-option
            v-for="protocol in natState.protocols"
            :key="String(protocol.value)"
            :value="String(protocol.value)"
            :label="protocol.label"
          />
        </t-select>
      </t-form-item>
    </t-form>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const { natVisible, natForm, natState, submitNatForwarding } = useServiceConsoleContext();
</script>
