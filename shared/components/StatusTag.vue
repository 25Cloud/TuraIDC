<!--
  通用状态标签组件
  用法：
    <StatusTag :status-map="ORDER_STATUS_MAP" :status="row.status" />
    <StatusTag :status-map="INVOICE_STATUS_MAP" :status="row.status" size="small" />
    <StatusTag :status-map="SERVICE_STATUS_MAP" :status="row.status" :dot="true" />
-->
<template>
  <el-tag
    :type="resolveElTagType(config.tagType)"
    :size="size"
    :class="[
      resolveElTagClass(config.tagType),
      config.pulse ? 'status-tag--pulse' : '',
    ]"
    :effect="effect"
  >
    <span v-if="dot" class="status-dot" :style="{ backgroundColor: config.color }" />
    <slot>{{ config.label }}</slot>
  </el-tag>
</template>

<script setup>
import { computed } from 'vue'
import { getStatusConfig, resolveElTagClass, resolveElTagType } from '@shared/statusConfig'

const props = defineProps({
  statusMap: { type: Object, required: true },
  status:    { type: [Number, String], required: true },
  size:      { type: String, default: 'small' },
  effect:    { type: String, default: 'light' },
  dot:       { type: Boolean, default: false },
})

const config = computed(() => getStatusConfig(props.statusMap, props.status))
</script>

<style scoped>
.status-dot {
  display: inline-block;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  margin-right: 6px;
  vertical-align: middle;
}
</style>
