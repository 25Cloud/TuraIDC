<template>
  <el-drawer
    :model-value="visibleValue"
    direction="btt"
    :size="size"
    :with-header="false"
    :close-on-press-modal="closeOnPressModal"
    class="ms-drawer"
    @update:model-value="handleVisibleChange"
    @opened="$emit('opened')"
  >
    <div class="ms">
      <div class="ms-head">
        <button
          v-if="cancelText"
          type="button"
          class="ms-action"
          @click="handleCancel"
        >{{ cancelText }}</button>
        <span v-else class="ms-action ms-action--placeholder" aria-hidden="true"></span>
        <strong v-if="title" class="ms-title">{{ title }}</strong>
        <span v-else class="ms-title" aria-hidden="true"></span>
        <button
          v-if="confirmText"
          type="button"
          class="ms-action ms-action--primary"
          @click="$emit('confirm')"
        >{{ confirmText }}</button>
        <span v-else class="ms-action ms-action--placeholder" aria-hidden="true"></span>
      </div>

      <div class="ms-body">
        <slot />
      </div>
    </div>
  </el-drawer>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false,
  },
  visible: {
    type: Boolean,
    default: undefined,
  },
  size: {
    type: String,
    default: '40%',
  },
  title: {
    type: String,
    default: '',
  },
  cancelText: {
    type: String,
    default: '',
  },
  confirmText: {
    type: String,
    default: '',
  },
  closeOnPressModal: {
    type: Boolean,
    default: true,
  },
})

const emit = defineEmits(['update:modelValue', 'update:visible', 'close', 'cancel', 'confirm', 'opened'])

const visibleValue = computed(() => props.visible ?? props.modelValue)

function updateVisible(value) {
  emit('update:modelValue', value)
  emit('update:visible', value)
  if (!value) {
    emit('close')
  }
}

function handleVisibleChange(value) {
  updateVisible(value)
}

function handleCancel() {
  emit('cancel')
  updateVisible(false)
}
</script>

<style scoped lang="scss">
.ms {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: #fff;
}

.ms-head {
  display: grid;
  grid-template-columns: 64px minmax(0, 1fr) 64px;
  align-items: center;
  gap: 12px;
  min-height: 46px;
  padding: 0 14px;
  border-bottom: 1px solid #eef1f5;
  flex-shrink: 0;
}

.ms-title {
  text-align: center;
  font-size: 14px;
  font-weight: 600;
  color: $text-color-primary;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ms-action {
  border: none;
  background: none;
  padding: 0;
  font-size: 14px;
  line-height: 46px;
  color: $text-color-primary;
  cursor: pointer;
  text-align: left;

  &--primary {
    color: $color-primary;
    font-weight: 600;
    text-align: right;
  }

  &--placeholder {
    pointer-events: none;
    visibility: hidden;
  }
}

.ms-body {
  flex: 1;
  overflow-y: auto;
}
</style>

<style lang="scss">
.ms-drawer .el-drawer {
  border-radius: 18px 18px 0 0;
  overflow: hidden;
}

.ms-drawer .el-drawer__body {
  padding: 0;
  overflow: hidden;
}
</style>
