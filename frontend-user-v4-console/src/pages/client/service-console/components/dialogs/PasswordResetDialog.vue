<template>
  <t-dialog
    v-model:visible="passwordVisible"
    :header="$t('serviceConsole.passwordReset.title')"
    width="min(30rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <div class="dialog-form">
      <label>
        <span>{{ $t('serviceConsole.passwordReset.newPassword') }}</span>
        <div class="password-generate-row">
          <t-input v-model="passwordForm.password" type="password" :placeholder="$t('serviceConsole.passwordReset.passwordPlaceholder')" />
          <t-tooltip :content="$t('serviceConsole.passwordReset.generateTip')">
            <button type="button" class="dice-icon-button" :aria-label="$t('serviceConsole.passwordReset.generateAriaLabel')" @click="generateStrongPassword">
              <dice-icon />
            </button>
          </t-tooltip>
        </div>
      </label>
      <label>
        <span>{{ $t('serviceConsole.passwordReset.confirmPassword') }}</span>
        <t-input v-model="passwordForm.password_confirmation" type="password" :placeholder="$t('serviceConsole.passwordReset.confirmPasswordPlaceholder')" />
      </label>
    </div>
    <template #footer>
      <t-button variant="outline" @click="passwordVisible = false">{{ $t('serviceConsole.passwordReset.cancel') }}</t-button>
      <t-button theme="primary" :loading="actionLoading" @click="submitResetPassword">{{
          $t('serviceConsole.passwordReset.submit')
        }}</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';
import { DiceIcon } from '../DiceIcon';

const { passwordVisible, passwordForm, actionLoading, generateStrongPassword, submitResetPassword } =
  useServiceConsoleContext();
</script>
