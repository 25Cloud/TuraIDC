<template>
  <t-dialog
    v-model:visible="ruleVisible"
    header="新增安全组规则"
    width="min(34rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <div class="dialog-form">
      <label>
        <span>方向</span>
        <t-select v-model="ruleForm.direction" placeholder="请选择方向">
          <t-option
            v-for="item in securityState.directions"
            :key="item.value"
            :label="item.label"
            :value="item.value"
          />
        </t-select>
      </label>
      <label>
        <span>协议</span>
        <t-select v-model="ruleForm.protocol" placeholder="请选择协议" @change="onProtocolChange">
          <t-option v-for="item in securityState.protocols" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
      </label>
      <label>
        <span>端口</span>
        <t-input
          v-model="ruleForm.port"
          :placeholder="
            isPortDisabled
              ? isAllPortProtocol
                ? '全部端口（自动填充）'
                : '该协议无端口（自动处理）'
              : '例如 22 或 80-90'
          "
          :disabled="isPortDisabled"
        />
      </label>
      <label>
        <span>IP 范围</span>
        <t-input v-model="ruleForm.ip" placeholder="例如 0.0.0.0/0" />
      </label>
      <label>
        <span>备注</span>
        <t-textarea
          v-model="ruleForm.description"
          :autosize="{ minRows: 3, maxRows: 5 }"
          :maxlength="200"
          placeholder="选填"
        />
      </label>
    </div>
    <template #footer>
      <t-button variant="outline" @click="ruleVisible = false">取消</t-button>
      <t-button theme="primary" :loading="securityState.submitting" @click="submitSecurityRule">创建规则</t-button>
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
