<template>
  <t-drawer
    v-model:visible="showSettingPanel"
    size="408px"
    :footer="false"
    :header="t('layout.setting.title')"
    :close-btn="true"
    class="setting-drawer-container"
    @close-btn-click="handleCloseDrawer"
  >
    <div class="setting-container">
      <t-form :data="formData" label-align="left">
        <div class="setting-group-title">{{ t('layout.setting.theme.mode') }}</div>
        <t-radio-group
          v-model="formData.mode"
          class="setting-mode-radio"
          variant="default-filled"
          @change="handleModeChange"
        >
          <t-radio-button value="light">{{ t('layout.setting.theme.options.light') }}</t-radio-button>
          <t-radio-button value="dark">{{ t('layout.setting.theme.options.dark') }}</t-radio-button>
          <t-radio-button value="auto">{{ t('layout.setting.theme.options.auto') }}</t-radio-button>
        </t-radio-group>

        <div class="setting-group-title">{{ t('layout.setting.navigationLayout') }}</div>
        <t-radio-group v-model="formData.layout" :disabled="isMobile">
          <div v-for="(item, index) in LAYOUT_OPTION" :key="index" class="setting-layout-drawer">
            <t-radio-button :key="index" :value="item" :disabled="isMobile">
              <thumbnail :src="getThumbnailUrl(item)" />
            </t-radio-button>
          </div>
        </t-radio-group>

        <t-form-item
          v-show="formData.layout !== 'side'"
          :label="t('layout.setting.hideHeaderLogo')"
          name="hideHeaderLogo"
        >
          <t-switch v-model="formData.hideHeaderLogo" />
        </t-form-item>

        <t-form-item
          v-show="formData.layout === 'side'"
          :label="t('layout.setting.element.showHeader')"
          name="showHeader"
        >
          <t-switch v-model="formData.showHeader" />
        </t-form-item>
        <t-form-item :label="t('layout.setting.element.showBreadcrumb')" name="showBreadcrumb">
          <t-switch v-model="formData.showBreadcrumb" />
        </t-form-item>
        <t-form-item :label="t('layout.setting.element.showFooter')" name="showFooter">
          <t-switch v-model="formData.showFooter" />
        </t-form-item>
        <t-form-item :label="t('layout.setting.element.useTagTabs')" name="isUseTabsRouter">
          <t-switch v-model="formData.isUseTabsRouter"></t-switch>
        </t-form-item>
        <t-form-item :label="t('layout.setting.element.menuAutoCollapsed')" name="menuAutoCollapsed">
          <t-switch v-model="formData.menuAutoCollapsed"></t-switch>
        </t-form-item>
      </t-form>
    </div>
  </t-drawer>
</template>
<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watchEffect } from 'vue';

import Thumbnail from '@/components/thumbnail/index.vue';
import STYLE_CONFIG from '@/config/style';
import { t } from '@/locales';
import { useSettingStore } from '@/store';
import type { ModeType } from '@/types/interface';

const settingStore = useSettingStore();

const LAYOUT_OPTION = ['side', 'top'];
const LAYOUT_THUMBNAILS = Object.fromEntries(LAYOUT_OPTION.map((item) => [item, createLayoutThumbnail(item)]));

const MOBILE_POINT = 768;
const isMobile = ref(false);

const updateIsMobile = () => {
  isMobile.value = window.innerWidth <= MOBILE_POINT;
};

onMounted(() => {
  updateIsMobile();
  window.addEventListener('resize', updateIsMobile);
});

onUnmounted(() => {
  window.removeEventListener('resize', updateIsMobile);
});

const initStyleConfig = () => {
  const styleConfig = STYLE_CONFIG;
  for (const key in styleConfig) {
    if (Object.hasOwn(styleConfig, key)) {
      (styleConfig[key as keyof typeof STYLE_CONFIG] as any) = settingStore[key as keyof typeof STYLE_CONFIG];
    }
  }

  return styleConfig;
};

const formData = ref({ ...initStyleConfig() });

// 手机端强制锁定为第一个布局（side），并禁用切换
watchEffect(() => {
  if (isMobile.value && formData.value.layout !== LAYOUT_OPTION[0]) {
    formData.value.layout = LAYOUT_OPTION[0];
    settingStore.updateConfig({ layout: LAYOUT_OPTION[0] });
  }
});

const showSettingPanel = computed({
  get() {
    return settingStore.showSettingPanel;
  },
  set(newVal: boolean) {
    settingStore.updateConfig({
      showSettingPanel: newVal,
    });
  },
});

const handleCloseDrawer = () => {
  settingStore.updateConfig({
    showSettingPanel: false,
  });
};

const handleModeChange = (mode: unknown) => {
  settingStore.updateConfig({
    mode: (mode as ModeType | 'auto') || 'light',
  });
};

function createLayoutThumbnail(type: string): string {
  const hasSide = type !== 'top';
  const hasTop = type !== 'side';
  const contentX = hasSide ? 32 : 8;
  const contentY = hasTop ? 21 : 8;
  const contentWidth = hasSide ? 48 : 72;
  const contentHeight = hasTop ? 19 : 32;
  const side = hasSide ? '<rect x="8" y="8" width="20" height="32" rx="3" fill="#165DFF" fill-opacity="0.88"/>' : '';
  const top = hasTop ? '<rect x="8" y="8" width="72" height="9" rx="3" fill="#165DFF" fill-opacity="0.18"/>' : '';
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="88" height="48" viewBox="0 0 88 48" fill="none"><rect x="1" y="1" width="86" height="46" rx="8" fill="#F8FAFC" stroke="#DCE6F5"/><rect x="${contentX}" y="${contentY}" width="${contentWidth}" height="${contentHeight}" rx="4" fill="#FFFFFF" stroke="#C8D7EE"/><rect x="${contentX + 7}" y="${contentY + 6}" width="${Math.max(16, contentWidth - 14)}" height="3" rx="1.5" fill="#A8B8D2"/><rect x="${contentX + 7}" y="${contentY + 12}" width="${Math.max(10, contentWidth - 28)}" height="3" rx="1.5" fill="#D2DCEE"/>${side}${top}</svg>`;

  return `data:image/svg+xml,${encodeURIComponent(svg)}`;
}

const getThumbnailUrl = (name: string): string => {
  return LAYOUT_THUMBNAILS[name] || LAYOUT_THUMBNAILS.side;
};

watchEffect(() => {
  settingStore.updateConfig(formData.value);
});
</script>
<!-- teleport导致drawer 内 scoped样式问题无法生效 先规避下 -->
<!-- eslint-disable-next-line vue-scoped-css/enforce-style-type -->
<style lang="less">
.tdesign-setting {
  z-index: 100;
  position: fixed;
  bottom: 200px;
  right: 0;
  height: 40px;
  width: 40px;
  border-radius: 20px 0 0 20px;
  transition: all 0.3s;

  .t-icon {
    margin-left: 8px;
  }

  .tdesign-setting-text {
    font-size: var(--td-font-size-size-1, 12px);
    display: none;
  }

  &:hover {
    width: 96px;

    .tdesign-setting-text {
      display: inline-block;
    }
  }
}

.setting-layout-color-group {
  display: inline-flex;
  justify-content: center;
  align-items: center;
  border-radius: 50% !important;
  padding: 6px !important;
  border: 2px solid transparent !important;

  > .t-radio-button__label {
    display: inline-flex;
  }
}

.tdesign-setting-close {
  position: fixed;
  bottom: 200px;
  right: 300px;
}

.setting-group-title {
  font-size: var(--td-font-size-size-3, 14px);
  line-height: 22px;
  margin: 32px 0 24px;
  text-align: left;
  font-family: 'PingFang SC', var(--td-font-family);
  font-style: normal;
  font-weight: 500;
  color: var(--td-text-color-primary);
}

.setting-link {
  cursor: pointer;
  color: var(--td-brand-color);
  margin-bottom: 8px;
}

.setting-info {
  position: absolute;
  padding: 24px;
  bottom: 0;
  left: 0;
  line-height: 20px;
  font-size: var(--td-font-size-size-1, 12px);
  text-align: center;
  color: var(--td-text-color-placeholder);
  width: 100%;
  background: var(--td-bg-color-container);
}

.setting-drawer-container {
  .setting-container {
    padding-bottom: 100px;
  }

  .t-radio-group.t-size-m {
    min-height: 32px;
    width: 100%;
    justify-content: flex-start;
    align-items: center;
    gap: var(--td-comp-margin-l);

    &.side-mode-radio {
      justify-content: end;
    }
  }

  .t-radio-group.t-size-m .t-radio-button {
    height: auto;
  }

  /* 主题模式切换：三个选项等宽均分、文字居中，紧凑整齐 */
  .setting-mode-radio {
    display: flex;
    width: 100%;
    gap: var(--td-comp-margin-s);

    .t-radio-button {
      flex: 1 1 0;
      justify-content: center;
      min-width: 0;
      padding: 0;
      text-align: center;
    }

    .t-radio-button__label {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 100%;
    }
  }

  .setting-layout-drawer {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-bottom: 16px;

    .t-radio-button {
      display: inline-flex;
      max-height: 78px;
      padding: 8px;
      border-radius: var(--td-radius-default);
      border: 2px solid var(--td-component-border);

      > .t-radio-button__label {
        display: inline-flex;
      }
    }

    .t-is-checked {
      border: 2px solid var(--td-brand-color) !important;
    }

    .t-form__controls-content {
      justify-content: end;
    }
  }

  .t-form__controls-content {
    justify-content: end;
  }
}

.setting-route-theme {
  .t-form__label {
    min-width: 310px !important;
    color: var(--td-text-color-secondary);
  }
}

.setting-color-theme {
  .setting-layout-drawer {
    .t-radio-button {
      height: 32px;
    }

    &:last-child {
      margin-right: 0;
    }
  }
}

/* 手机端不提供导航布局切换，显示灰色不可用 */
@media (width <= 768px) {
  .setting-drawer-container .setting-container .t-form {
    > .setting-group-title {
      color: var(--td-text-color-disabled);
    }

    > .t-radio-group.t-size-m:not(.setting-mode-radio) {
      pointer-events: none;
      opacity: 0.4;
      filter: grayscale(1);
      cursor: not-allowed;
    }

    .setting-layout-drawer .t-radio-button {
      border-color: var(--td-component-border) !important;
      cursor: not-allowed;
    }
  }
}
</style>
