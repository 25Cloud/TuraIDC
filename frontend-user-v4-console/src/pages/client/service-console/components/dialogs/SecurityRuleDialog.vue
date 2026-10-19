<template>
  <t-dialog
    v-model:visible="ruleVisible"
    :header="$t('serviceConsole.security.addRuleHeader')"
    width="min(34rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <div class="dialog-form">
      <label>
        <span>{{ $t('serviceConsole.securityGroup.direction') }}</span>
        <t-select v-model="ruleForm.direction" :placeholder="$t('serviceConsole.securityGroup.directionPlaceholder')">
          <t-option
            v-for="item in securityState.directions"
            :key="item.value"
            :label="item.label"
            :value="item.value"
          />
        </t-select>
      </label>
      <label>
        <span>{{ $t('serviceConsole.securityGroup.protocol') }}</span>
        <t-select
          v-model="ruleForm.protocol"
          :placeholder="$t('serviceConsole.securityGroup.protocolPlaceholder')"
          @change="onProtocolChange"
        >
          <t-option v-for="item in securityState.protocols" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
      </label>
      <label>
        <span>{{ $t('serviceConsole.securityGroup.port') }}</span>
        <t-input
          v-model="ruleForm.port"
          :placeholder="
            isPortDisabled
              ? isAllPortProtocol
                ? $t('serviceConsole.security.portPlaceholderAll')
                : $t('serviceConsole.security.portPlaceholderNone')
              : $t('serviceConsole.security.portPlaceholderExample')
          "
          :disabled="isPortDisabled"
        />
      </label>
      <label>
        <span>{{ $t('serviceConsole.securityGroup.ipRange') }}</span>
        <t-input v-model="ruleForm.ip" :placeholder="$t('serviceConsole.securityGroup.ipPlaceholder')" />
      </label>
      <label>
        <span>{{ $t('serviceConsole.securityGroup.remark') }}</span>
        <t-textarea
          v-model="ruleForm.description"
          :autosize="{ minRows: 3, maxRows: 5 }"
          :maxlength="200"
          :placeholder="$t('serviceConsole.securityGroup.remarkPlaceholder')"
        />
      </label>
    </div>
    <template #footer>
      <t-button variant="outline" @click="ruleVisible = false">{{ $t('common.cancel') }}</t-button>
      <t-button theme="primary" :loading="securityState.submitting" @click="submitSecurityRule">{{
        $t('serviceConsole.securityGroup.create')
      }}</t-button>
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
