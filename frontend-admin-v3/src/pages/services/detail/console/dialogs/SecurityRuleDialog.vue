<template>
  <t-dialog
    v-model:visible="ruleVisible"
    :header="$t('services.console.securityRuleDialogTitle')"
    width="min(34rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <div class="dialog-form">
      <label>
        <span>{{ $t('services.console.direction') }}</span>
        <t-select v-model="ruleForm.direction" :placeholder="$t('services.console.selectDirection')">
          <t-option
            v-for="item in securityState.directions"
            :key="item.value"
            :label="item.label"
            :value="item.value"
          />
        </t-select>
      </label>
      <label>
        <span>{{ $t('services.console.protocol') }}</span>
        <t-select v-model="ruleForm.protocol" :placeholder="$t('services.console.selectProtocol')" @change="onProtocolChange">
          <t-option v-for="item in securityState.protocols" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
      </label>
      <label>
        <span>{{ $t('services.console.port') }}</span>
        <t-input
          v-model="ruleForm.port"
          :placeholder="
            isPortDisabled
              ? isAllPortProtocol
                ? $t('services.console.allPortsAutoFill')
                : $t('services.console.noPortsForProtocol')
              : $t('services.console.portPlaceholder')
          "
          :disabled="isPortDisabled"
        />
      </label>
      <label>
        <span>{{ $t('services.console.ipRange') }}</span>
        <t-input v-model="ruleForm.ip" :placeholder="$t('services.console.ipRangePlaceholder')" />
      </label>
      <label>
        <span>{{ $t('services.console.remark') }}</span>
        <t-textarea
          v-model="ruleForm.description"
          :autosize="{ minRows: 3, maxRows: 5 }"
          :maxlength="200"
          :placeholder="$t('services.console.optional')"
        />
      </label>
    </div>
    <template #footer>
      <t-button variant="outline" @click="ruleVisible = false">{{ $t('services.console.cancel') }}</t-button>
      <t-button theme="primary" :loading="securityState.submitting" @click="submitSecurityRule">{{ $t('services.console.createRule') }}</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const {
  ruleVisible,
  ruleForm,
  securityState,
  isPortDisabled,
  isAllPortProtocol,
  onProtocolChange,
  submitSecurityRule,
} = useServiceConsoleContext();
</script>
