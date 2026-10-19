<template>
  <t-dialog
    v-model:visible="natVisible"
    :header="$t('serviceConsole.nat.addForwardHeader')"
    :confirm-btn="$t('serviceConsole.nat.createBtn')"
    :confirm-loading="natState.submitting"
    @confirm="submitNatForwarding"
  >
    <t-form label-align="right" :label-width="96">
      <t-form-item :label="$t('serviceConsole.nat.nameLabel')" required-mark>
        <t-input v-model="natForm.name" :placeholder="$t('serviceConsole.nat.namePlaceholder')" :maxlength="255" />
      </t-form-item>
      <t-form-item :label="$t('serviceConsole.nat.publicPortLabel')">
        <t-input
          v-model="natForm.ext_port"
          :placeholder="$t('serviceConsole.nat.internalPlaceholder')"
          :maxlength="5"
        />
      </t-form-item>
      <t-form-item :label="$t('serviceConsole.nat.internalPortLabel')" required-mark>
        <t-input v-model="natForm.int_port" :placeholder="$t('serviceConsole.nat.portPlaceholder')" :maxlength="5" />
      </t-form-item>
      <t-form-item :label="$t('serviceConsole.nat.protocolLabel')" required-mark>
        <t-select v-model="natForm.protocol" :placeholder="$t('serviceConsole.nat.protocolPlaceholder')">
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
