<template>
  <div v-if="multiline" class="secret-input secret-input--textarea">
    <t-textarea
      :model-value="displayValue"
      :autosize="autosize"
      :placeholder="placeholder"
      :disabled="disabled"
      @update:model-value="handleInput"
    />
    <span
      v-if="hasValue && canReveal"
      class="secret-input__toggle secret-input__toggle--textarea"
      @click.stop="toggleVisible"
    >
      <browse-off-icon v-if="visible" />
      <browse-icon v-else />
    </span>
  </div>
  <t-input
    v-else
    :model-value="displayValue"
    :type="inputType"
    :placeholder="placeholder"
    :disabled="disabled"
    :clearable="clearable"
    @update:model-value="handleInput"
  >
    <template v-if="hasValue && canReveal" #suffix-icon>
      <span class="secret-input__toggle" @click.stop="toggleVisible">
        <browse-off-icon v-if="visible" />
        <browse-icon v-else />
      </span>
    </template>
  </t-input>
</template>
<script setup lang="ts">
import { BrowseIcon, BrowseOffIcon } from 'tdesign-icons-vue-next';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
  defineProps<{
    modelValue?: string | number | boolean | null;
    hasValue?: boolean;
    placeholder?: string;
    disabled?: boolean;
    clearable?: boolean;
    mask?: string;
    type?: 'text' | 'password';
    resetKey?: string | number;
    multiline?: boolean;
    autosize?: unknown;
    canReveal?: boolean;
    reveal?: () => Promise<unknown>;
  }>(),
  {
    modelValue: '',
    hasValue: false,
    placeholder: '',
    disabled: false,
    clearable: false,
    mask: '********',
    type: 'password',
    resetKey: '',
    multiline: false,
    autosize: undefined,
    canReveal: true,
    reveal: undefined,
  },
);

const emit = defineEmits<{
  'update:modelValue': [value: string];
  'edited-change': [value: boolean];
  'reveal-error': [error: unknown];
}>();

const visible = ref(false);
const edited = ref(false);
const loadedValue = ref<string | null>(null);
const loading = ref(false);

const displayValue = computed(() => {
  if (!props.hasValue)
    return props.modelValue === null || props.modelValue === undefined ? '' : String(props.modelValue);
  if (visible.value) {
    return props.modelValue === null || props.modelValue === undefined || props.modelValue === ''
      ? loadedValue.value || ''
      : String(props.modelValue);
  }
  if (edited.value) return props.modelValue === null || props.modelValue === undefined ? '' : String(props.modelValue);
  return props.mask;
});

const inputType = computed(() => {
  if (props.hasValue && !visible.value && !edited.value) return 'text';
  if (visible.value) return 'text';
  return props.type;
});

watch(
  () => [props.resetKey, props.hasValue],
  () => resetState(),
);

function handleInput(value: string) {
  let nextValue = value;
  if (props.hasValue && !visible.value && !edited.value && nextValue.startsWith(props.mask)) {
    nextValue = nextValue.slice(props.mask.length);
  }

  emit('update:modelValue', nextValue);
  if (props.hasValue && !edited.value) {
    edited.value = true;
    emit('edited-change', true);
  }
}

async function toggleVisible() {
  if (!props.hasValue || !props.canReveal || loading.value) return;

  if (visible.value) {
    visible.value = false;
    return;
  }

  if (edited.value) {
    visible.value = true;
    return;
  }

  if (loadedValue.value !== null) {
    emit('update:modelValue', loadedValue.value);
    visible.value = true;
    emit('edited-change', false);
    return;
  }

  if (!props.reveal) return;

  loading.value = true;
  try {
    const value = await props.reveal();
    loadedValue.value = typeof value === 'string' ? value : JSON.stringify(value ?? '');
    emit('update:modelValue', loadedValue.value);
    visible.value = true;
    emit('edited-change', false);
  } catch (error) {
    emit('reveal-error', error);
  } finally {
    loading.value = false;
  }
}

function resetState() {
  visible.value = false;
  edited.value = false;
  loadedValue.value = null;
  emit('edited-change', false);
}
</script>
<style scoped lang="less">
.secret-input {
  position: relative;
  width: 100%;
}

.secret-input :deep(.t-textarea) {
  width: 100%;
}

.secret-input__toggle {
  display: inline-flex;
  align-items: center;
  color: var(--td-text-color-secondary);
  cursor: pointer;
}

.secret-input__toggle:hover {
  color: var(--td-brand-color);
}

.secret-input__toggle--textarea {
  position: absolute;
  top: 8px;
  right: 10px;
  z-index: 1;
  background: var(--td-bg-color-container);
}
</style>
