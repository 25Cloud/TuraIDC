<template>
  <t-dialog
    v-model:visible="natVisible"
    header="添加端口转发"
    confirm-btn="创建"
    :confirm-loading="natState.submitting"
    @confirm="submitNatForwarding"
  >
    <t-form label-align="right" :label-width="96">
      <t-form-item label="名称" required-mark>
        <t-input v-model="natForm.name" placeholder="例如：Web 服务" :maxlength="255" />
      </t-form-item>
      <t-form-item label="公网端口">
        <t-input v-model="natForm.ext_port" placeholder="留空由上游分配" :maxlength="5" />
      </t-form-item>
      <t-form-item label="内网端口" required-mark>
        <t-input v-model="natForm.int_port" placeholder="例如：80" :maxlength="5" />
      </t-form-item>
      <t-form-item label="协议" required-mark>
        <t-select v-model="natForm.protocol" placeholder="请选择协议">
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
