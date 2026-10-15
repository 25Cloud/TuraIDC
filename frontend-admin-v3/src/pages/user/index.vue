<template>
  <div class="user-center-page">
    <t-card :bordered="false" :title="$t('user.profile.title')" class="profile-card">
      <t-loading :loading="loading" show-overlay>
        <t-form
          ref="formRef"
          :data="formData"
          :rules="formRules"
          label-width="100px"
          :disabled="!editing"
          @submit="handleSave"
        >
          <t-form-item :label="$t('user.profile.username')" name="username">
            <t-input v-model="formData.username" disabled />
            <template #help>{{ $t('user.profile.usernameReadonly') }}</template>
          </t-form-item>
          <t-form-item :label="$t('user.profile.nickname')" name="nickname">
            <t-input
              v-model="formData.nickname"
              :placeholder="$t('user.profile.nicknamePlaceholder')"
              :maxlength="30"
            />
          </t-form-item>
          <t-form-item :label="$t('user.profile.email')" name="email">
            <t-input v-model="formData.email" disabled />
          </t-form-item>
          <t-form-item :label="$t('user.profile.role')" name="role">
            <t-tag theme="primary" variant="light">{{ formData.role || '-' }}</t-tag>
          </t-form-item>
          <t-form-item v-if="!editing">
            <t-space>
              <t-button theme="primary" @click="startEdit">{{ $t('user.profile.editProfile') }}</t-button>
            </t-space>
          </t-form-item>
          <t-form-item v-if="editing">
            <t-space>
              <t-button theme="primary" type="submit" :loading="saving">
                {{ $t('user.profile.save') }}
              </t-button>
              <t-button theme="default" @click="cancelEdit">{{ $t('user.profile.cancel') }}</t-button>
            </t-space>
          </t-form-item>
        </t-form>
      </t-loading>
    </t-card>
  </div>
</template>
<script setup lang="ts">
import type { FormInstanceFunctions, FormRule } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { onMounted, reactive, ref } from 'vue';

import { adminAuthApi } from '@/api/auth';
import { t } from '@/locales';
import { useUserStore } from '@/store';
import { errorMessage } from '@/utils/userMessage';

const userStore = useUserStore();

const formRef = ref<FormInstanceFunctions>();
const loading = ref(false);
const saving = ref(false);
const editing = ref(false);

const formData = reactive({
  username: '',
  nickname: '',
  email: '',
  role: '',
});

const formRules: Record<string, FormRule[]> = {
  nickname: [
    { required: true, message: t('user.profile.nicknameRequired'), type: 'error' },
    { max: 30, message: t('user.profile.nicknameMaxLength'), type: 'warning' },
  ],
};

const fetchProfile = async () => {
  loading.value = true;
  try {
    const info = await userStore.getUserInfo();
    formData.username = ((info as Record<string, unknown>).username as string) || '';
    formData.nickname = ((info as Record<string, unknown>).nickname as string) || '';
    formData.email = ((info as Record<string, unknown>).email as string) || '';
    formData.role = ((info as Record<string, unknown>).role as string) || '';
  } catch {
    // getUserInfo 内部已处理错误
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  fetchProfile();
});

const startEdit = () => {
  editing.value = true;
};

const cancelEdit = () => {
  editing.value = false;
  fetchProfile();
};

const handleSave = async () => {
  const result = await formRef.value?.validate?.();
  if (result !== true) return;

  saving.value = true;
  try {
    await adminAuthApi.updateProfile({ nickname: formData.nickname });
    MessagePlugin.success(t('user.profile.updated'));
    editing.value = false;
    // 同步更新 store 中的用户信息
    userStore.userInfo = {
      ...userStore.userInfo,
      name: formData.nickname || userStore.userInfo.name,
      nickname: formData.nickname,
    };
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存失败'));
  } finally {
    saving.value = false;
  }
};
</script>
<style lang="less" scoped>
.profile-card {
  max-width: 600px;
}
</style>
