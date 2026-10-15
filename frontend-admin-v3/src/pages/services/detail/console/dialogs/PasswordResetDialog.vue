<template>
  <t-dialog
    v-model:visible="passwordVisible"
    :header="$t('services.console.resetPasswordTitle')"
    width="min(30rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <div class="dialog-form">
      <label>
        <span>{{ $t('services.console.newPassword') }}</span>
        <div class="password-generate-row">
          <t-input v-model="passwordForm.password" type="password" :placeholder="$t('services.console.passwordMinLength')" />
          <t-tooltip :content="$t('services.console.generateStrongPassword')">
            <button type="button" class="dice-icon-button" :aria-label="$t('services.console.generateStrongPassword')" @click="generateStrongPassword">
              <dice-icon />
            </button>
          </t-tooltip>
        </div>
      </label>
      <label>
        <span>{{ $t('services.console.confirmPassword') }}</span>
        <t-input v-model="passwordForm.password_confirmation" type="password" :placeholder="$t('services.console.confirmPasswordPlaceholder')" />
      </label>
    </div>
    <template #footer>
      <t-button variant="outline" @click="passwordVisible = false">{{ $t('services.console.cancel') }}</t-button>
      <t-button theme="primary" :loading="actionLoading" @click="submitResetPassword">{{ $t('services.console.submit') }}</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';
import { DiceIcon } from '../DiceIcon';

const { passwordVisible, passwordForm, actionLoading, generateStrongPassword, submitResetPassword } =
  useServiceConsoleContext();
</script>
