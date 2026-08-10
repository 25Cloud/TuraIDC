<template>
  <t-dialog
    v-model:visible="passwordVisible"
    header="重置实例密码"
    width="min(30rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <div class="dialog-form">
      <label>
        <span>新密码</span>
        <div class="password-generate-row">
          <t-input v-model="passwordForm.password" type="password" placeholder="至少 8 位" />
          <t-tooltip content="随机生成强密码">
            <button type="button" class="dice-icon-button" aria-label="随机生成强密码" @click="generateStrongPassword">
              <dice-icon />
            </button>
          </t-tooltip>
        </div>
      </label>
      <label>
        <span>确认密码</span>
        <t-input v-model="passwordForm.password_confirmation" type="password" placeholder="再次输入新密码" />
      </label>
    </div>
    <template #footer>
      <t-button variant="outline" @click="passwordVisible = false">取消</t-button>
      <t-button theme="primary" :loading="actionLoading" @click="submitResetPassword">提交</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';
import { DiceIcon } from '../DiceIcon';

const { passwordVisible, passwordForm, actionLoading, generateStrongPassword, submitResetPassword } =
  useServiceConsoleContext();
</script>
