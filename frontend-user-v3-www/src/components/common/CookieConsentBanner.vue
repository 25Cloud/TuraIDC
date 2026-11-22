<template>
  <Transition name="cookie-consent" appear>
    <div
      v-if="visible"
      class="cookie-consent"
      role="dialog"
      aria-live="polite"
      :aria-label="$t('cookieConsent.title')"
    >
      <div class="cookie-consent__body">
        <p class="cookie-consent__title">{{ $t("cookieConsent.title") }}</p>
        <p class="cookie-consent__text">
          {{
            $t("cookieConsent.message", {
              site: appStore.siteName,
            })
          }}
          <router-link to="/terms">{{
            $t("cookieConsent.termsLink")
          }}</router-link>
          <span class="cookie-consent__divider">·</span>
          <router-link to="/privacy">{{
            $t("cookieConsent.privacyLink")
          }}</router-link>
        </p>
      </div>
      <div class="cookie-consent__actions">
        <el-button size="small" @click="reject">{{
          $t("cookieConsent.reject")
        }}</el-button>
        <el-button size="small" type="primary" @click="accept">
          {{ $t("cookieConsent.accept") }}
        </el-button>
      </div>
    </div>
  </Transition>
</template>

<script setup lang="ts">
/**
 * Cookie 使用告知横幅。
 *
 * 口径：本站只使用维持登录态与界面偏好的必要 Cookie / 本地存储，不做跨站跟踪，
 * 因此不弹第三方同意管理面板，只做一次性告知；用户点「知道了」或「仅必要」后
 * 以 localStorage 记忆，不再打扰（隐私政策第四条有对应说明）。
 */
import { onMounted, ref } from "vue";
import { useAppStore } from "@/stores/app";

const STORAGE_KEY = "www_cookie_consent";

const appStore = useAppStore();
const visible = ref(false);

onMounted(() => {
  let stored = "";
  try {
    stored = window.localStorage.getItem(STORAGE_KEY) || "";
  } catch {
    // 隐私模式等 localStorage 不可用的场景：本次会话不再显示，避免每次刷新都弹
    return;
  }
  if (stored === "") {
    visible.value = true;
  }
});

function remember(value: "accepted" | "necessary") {
  try {
    window.localStorage.setItem(STORAGE_KEY, value);
  } catch {
    // 写不进去就只影响本次
  }
  visible.value = false;
}

function accept() {
  remember("accepted");
}

function reject() {
  remember("necessary");
}
</script>

<style scoped lang="scss">
.cookie-consent {
  position: fixed;
  left: 16px;
  right: 16px;
  bottom: 16px;
  z-index: 2100;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  max-width: 960px;
  margin: 0 auto;
  padding: 14px 20px;
  border: 1px solid #e5eaf3;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.96);
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.16);
  backdrop-filter: blur(6px);
}

.cookie-consent__body {
  min-width: 0;
}

.cookie-consent__title {
  margin: 0;
  color: #0f172a;
  font-weight: 600;
  font-size: 14px;
}

.cookie-consent__text {
  margin: 4px 0 0;
  color: #64748b;
  font-size: 13px;
  line-height: 1.6;
}

.cookie-consent__text a {
  color: var(--el-color-primary, #165dff);
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }
}

.cookie-consent__divider {
  margin: 0 6px;
  color: #cbd5e1;
}

.cookie-consent__actions {
  flex-shrink: 0;
  display: flex;
  gap: 8px;
}

.cookie-consent-enter-active,
.cookie-consent-leave-active {
  transition:
    opacity 0.35s ease,
    transform 0.35s cubic-bezier(0.22, 1, 0.36, 1);
}

.cookie-consent-enter-from,
.cookie-consent-leave-to {
  opacity: 0;
  transform: translateY(16px);
}

@media (max-width: 768px) {
  .cookie-consent {
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
  }

  .cookie-consent__actions {
    justify-content: flex-end;
  }
}
</style>
