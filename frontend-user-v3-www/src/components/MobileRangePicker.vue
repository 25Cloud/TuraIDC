<template>
  <div class="mrange">
    <div class="mrange-head">
      <span class="mrange-label">{{ label }}</span>
      <div class="mrange-stepper">
        <button type="button" class="mrange-btn" :disabled="isFixed || modelValue <= min" @click="step(-1)">
          <svg viewBox="0 0 12 12" fill="none" width="12" height="12"><path d="M2.5 6h7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" /></svg>
        </button>
        <span class="mrange-value">{{ modelValue }}</span>
        <button type="button" class="mrange-btn" :disabled="isFixed || modelValue >= max" @click="step(1)">
          <svg viewBox="0 0 12 12" fill="none" width="12" height="12"><path d="M6 2.5v7M2.5 6h7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" /></svg>
        </button>
      </div>
    </div>
    <div class="mrange-track-wrap">
      <input
        type="range"
        class="mrange-slider"
        :class="{ 'is-disabled': isFixed }"
        :min="sliderMin"
        :max="sliderMax"
        :step="stepSize"
        :value="modelValue"
        :disabled="isFixed"
        @input="$emit('update:modelValue', Number($event.target.value))"
      />
      <div class="mrange-labels">
        <span>{{ min }}</span>
        <span>{{ max }}</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  modelValue: { type: Number, default: 1 },
  label: { type: String, default: '' },
  min: { type: Number, default: 1 },
  max: { type: Number, default: 9999 },
  stepSize: { type: Number, default: 1 },
})

const emit = defineEmits(['update:modelValue'])

const isFixed = computed(() => props.min === props.max)
const sliderMin = computed(() => props.min)
const sliderMax = computed(() => isFixed.value ? props.min + 1 : props.max)

function step(delta) {
  const next = props.modelValue + delta * props.stepSize
  const clamped = Math.max(props.min, Math.min(props.max, next))
  emit('update:modelValue', clamped)
}
</script>

<style scoped lang="scss">
.mrange {
  display: flex;
  flex-direction: column;
  gap: 8px;
  width: 100%;
}

.mrange-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.mrange-label {
  font-size: 13px;
  color: $text-color-primary;
  font-weight: 500;
}

.mrange-stepper {
  display: flex;
  align-items: center;
  border: 1px solid $color-primary-border;
  border-radius: 8px;
  overflow: hidden;
  background: #fff;
}

.mrange-btn {
  width: 32px;
  height: 32px;
  border: none;
  background: transparent;
  color: $text-color-placeholder;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;

  &:hover:not(:disabled) {
    background: $color-primary-soft;
    color: $color-primary;
  }

  &:disabled {
    opacity: 0.3;
    cursor: not-allowed;
  }
}

.mrange-value {
  min-width: 36px;
  text-align: center;
  font-size: 14px;
  font-weight: 600;
  color: $text-color-primary;
  border-left: 1px solid $color-primary-border;
  border-right: 1px solid $color-primary-border;
  padding: 0 4px;
}

.mrange-track-wrap {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.mrange-slider {
  -webkit-appearance: none;
  appearance: none;
  width: 100%;
  height: 4px;
  background: #e5eaf3;
  border-radius: 2px;
  outline: none;
  cursor: pointer;

  &::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: $color-primary;
    border: 2px solid #fff;
    box-shadow: 0 1px 4px rgba(22, 93, 255, 0.3);
    cursor: pointer;
    transition: transform 0.15s;

    &:hover {
      transform: scale(1.15);
    }
  }

  &::-moz-range-thumb {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: $color-primary;
    border: 2px solid #fff;
    box-shadow: 0 1px 4px rgba(22, 93, 255, 0.3);
    cursor: pointer;
  }

  &.is-disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }
}

.mrange-labels {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
  color: $text-color-placeholder;
}
</style>
