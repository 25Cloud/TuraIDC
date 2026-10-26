<template>
  <div class="settings-page">
    <template v-if="activeTab !== 'site_hero'">
      <t-card :bordered="false" :loading="settingsLoading">
        <p v-if="pageConfig?.description" class="settings-page__desc">{{ pageConfig.description }}</p>

        <t-form :data="form" :disabled="!canManageCurrentTab">
          <section v-for="section in sections" :key="section.key" class="admin-field-section">
            <header class="admin-field-section__head">
              <h2 class="admin-field-section__title">{{ section.title }}</h2>
              <p v-if="section.description" class="admin-field-section__desc">{{ section.description }}</p>
            </header>

            <div class="admin-field-grid">
              <div
                v-for="field in section.fields"
                :key="field.key"
                class="admin-field"
                :class="{ 'admin-field--full': field.wide, 'admin-field--switch': field.type === 'switch' }"
              >
                <span class="admin-field__label" :class="{ 'admin-field__label--required': isFieldRequired(field) }">
                  {{ field.label }}
                </span>
                <p v-if="field.help" class="admin-field__desc">{{ field.help }}</p>
                <div class="admin-field__control" :class="controlWidthClass(field)">
                  <t-switch v-if="field.type === 'switch'" v-model="form[field.key]" />
                  <t-select
                    v-else-if="field.type === 'select'"
                    v-model="form[field.key]"
                    clearable
                    :placeholder="field.placeholder"
                  >
                    <t-option
                      v-for="option in field.options || []"
                      :key="String(option.value)"
                      :label="option.label"
                      :value="option.value"
                    />
                  </t-select>
                  <t-input-number
                    v-else-if="field.type === 'number'"
                    :model-value="form[field.key] as FieldNumberValue"
                    theme="normal"
                    :min="field.min"
                    :max="field.max"
                    :placeholder="field.placeholder"
                    @update:model-value="(value: unknown) => (form[field.key] = value as FieldNumberValue)"
                  />
                  <t-textarea
                    v-else-if="field.type === 'textarea'"
                    :model-value="form[field.key] as FieldStringValue"
                    :autosize="{ minRows: field.rows || 3, maxRows: Math.max(field.rows || 3, 6) }"
                    :maxlength="field.maxlength"
                    :placeholder="field.placeholder"
                    @update:model-value="(value: FieldStringValue) => (form[field.key] = value)"
                  />
                  <t-time-picker
                    v-else-if="field.type === 'time'"
                    :model-value="form[field.key] as string"
                    clearable
                    format="HH:mm:ss"
                    :placeholder="field.placeholder"
                    @update:model-value="(value: string) => (form[field.key] = value)"
                  />
                  <t-color-picker
                    v-else-if="field.type === 'color'"
                    :model-value="String(form[field.key] ?? '') || undefined"
                    :show-add-color="false"
                    format="HEX"
                    :clearable="true"
                    @update:model-value="
                      (value: unknown) => (form[field.key] = value === null ? '' : String(value ?? ''))
                    "
                  />
                  <div v-else-if="field.type === 'radius'" class="settings-radius-control">
                    <t-select
                      :model-value="radiusPresetOf(form[field.key])"
                      @update:model-value="(value: unknown) => applyRadiusPreset(field, value)"
                    >
                      <t-option
                        v-for="option in RADIUS_PRESETS"
                        :key="String(option.value)"
                        :label="option.label"
                        :value="option.value"
                      />
                      <t-option :label="RADIUS_CUSTOM_LABEL" :value="RADIUS_CUSTOM" />
                    </t-select>
                    <t-input-number
                      v-if="radiusPresetOf(form[field.key]) === RADIUS_CUSTOM"
                      :model-value="form[field.key] as FieldNumberValue"
                      theme="normal"
                      :min="0"
                      :max="24"
                      @update:model-value="(value: unknown) => (form[field.key] = value as FieldNumberValue)"
                    />
                  </div>
                  <div v-else-if="field.type === 'image'" class="cover-image-selector" @click="selectImage(field)">
                    <image-icon />
                    <span v-if="form[field.key]" class="cover-image-selector__name">{{
                      String(form[field.key]).split('/').pop()
                    }}</span>
                    <span v-else class="cover-image-selector__placeholder">{{
                      $t('settings.index.clickToSelectPrefix')
                    }}</span>
                    <chevron-right-icon />
                  </div>
                  <secret-input
                    v-else-if="isSecretSettingField(field)"
                    v-model="form[field.key]"
                    :has-value="hasSettingSecretValue(field)"
                    :placeholder="field.placeholder"
                    :reset-key="settingSecretResetKey(field)"
                    :can-reveal="canRevealSettingsSecret"
                    :reveal="() => revealSettingSecret(field)"
                    @edited-change="(value: boolean) => (settingsSecretEdited[settingSecretEditKey(field)] = value)"
                    @reveal-error="
                      (error: unknown) =>
                        MessagePlugin.error(errorMessage(error, $t('settings.index.readSensitiveConfigFailed')))
                    "
                  />
                  <t-input
                    v-else
                    :model-value="form[field.key] as FieldStringValue"
                    :type="field.type === 'password' ? 'password' : 'text'"
                    :maxlength="field.maxlength"
                    :placeholder="field.placeholder"
                    @update:model-value="(value: FieldStringValue) => (form[field.key] = value)"
                  />
                </div>
              </div>
            </div>
          </section>
        </t-form>
      </t-card>

      <div v-if="allFields.length > 0" class="settings-bottom-actions">
        <t-button theme="primary" :loading="currentSaving" :disabled="!canManageCurrentTab" @click="saveCurrentTab">
          <template #icon><check-icon /></template>
          {{ $t('settings.index.saveSettings') }}
        </t-button>
      </div>
    </template>

    <template v-else>
      <t-card :bordered="false" :loading="heroLoading" class="slides-config-card">
        <t-alert v-if="heroDirty" theme="warning" :message="$t('settings.index.unsavedTip')" />

        <section class="slides-section">
          <div class="slides-section-head">
            <div class="slides-section-head__text">
              <h2>{{ $t('settings.index.slidesTitle') }}（{{ heroForm.slides.length }} / {{ maxSlides }}）</h2>
              <p>{{ $t('settings.index.slidesTip') }}</p>
              <!-- 图片/视频的规则对每一项都成立，写在节头一次，不在每张卡里重复 -->
              <p>{{ $t('settings.index.slideImageHint') }}</p>
              <p>{{ $t('settings.index.slideVideoHint') }}</p>
            </div>
            <t-space>
              <t-button variant="outline" @click="resetSlidesToDefault">{{
                $t('settings.index.restoreDefault')
              }}</t-button>
              <t-button theme="primary" :disabled="heroForm.slides.length >= maxSlides" @click="addSlide">
                <template #icon><add-icon /></template>
                {{ $t('settings.index.addSlide') }}
              </t-button>
            </t-space>
          </div>

          <article v-for="(slide, index) in heroForm.slides" :key="`slide-${index}`" class="slide-card">
            <div class="slide-card-head">
              <span class="slide-card-head__index">{{ index + 1 }}</span>
              <strong class="slide-card-head__name">{{
                slide.rail_title || $t('settings.index.untitledSlide')
              }}</strong>
              <div class="slide-card-head__actions">
                <t-button variant="text" size="small" :disabled="index === 0" @click="moveSlide(index, -1)">
                  <template #icon><arrow-up-icon /></template>
                </t-button>
                <t-button
                  variant="text"
                  size="small"
                  :disabled="index === heroForm.slides.length - 1"
                  @click="moveSlide(index, 1)"
                >
                  <template #icon><arrow-down-icon /></template>
                </t-button>
                <t-button
                  theme="danger"
                  variant="text"
                  size="small"
                  :disabled="heroForm.slides.length <= 1"
                  @click="removeSlide(index)"
                >
                  <template #icon><delete-icon /></template>
                </t-button>
              </div>
            </div>

            <div class="slide-body">
              <div class="admin-field-grid">
                <div class="admin-field">
                  <span class="admin-field__label">
                    <span class="admin-field__label-scope">{{
                      $t('settings.index.slideIndexPrefix', { index: index + 1 })
                    }}</span>
                    {{ $t('settings.index.heroFieldRailTitle') }}
                  </span>
                  <div class="admin-field__control">
                    <t-input
                      v-model="slide.rail_title"
                      maxlength="20"
                      :placeholder="$t('settings.index.railTitlePlaceholder')"
                    />
                  </div>
                </div>
                <div class="admin-field">
                  <span class="admin-field__label">
                    <span class="admin-field__label-scope">{{
                      $t('settings.index.slideIndexPrefix', { index: index + 1 })
                    }}</span>
                    {{ $t('settings.index.heroFieldTitle') }}
                  </span>
                  <div class="admin-field__control">
                    <t-input
                      v-model="slide.title"
                      maxlength="80"
                      :placeholder="$t('settings.index.mainTitlePlaceholder')"
                    />
                  </div>
                </div>
                <div class="admin-field">
                  <span class="admin-field__label">
                    <span class="admin-field__label-scope">{{
                      $t('settings.index.slideIndexPrefix', { index: index + 1 })
                    }}</span>
                    {{ $t('settings.index.heroFieldPrimaryText') }}
                  </span>
                  <div class="admin-field__control">
                    <t-input
                      v-model="slide.primary_text"
                      maxlength="20"
                      :placeholder="$t('settings.index.buttonTextPlaceholder')"
                    />
                  </div>
                </div>
                <div class="admin-field">
                  <span class="admin-field__label">
                    <span class="admin-field__label-scope">{{
                      $t('settings.index.slideIndexPrefix', { index: index + 1 })
                    }}</span>
                    {{ $t('settings.index.heroFieldPrimaryPath') }}
                  </span>
                  <div class="admin-field__control">
                    <t-input
                      v-model="slide.primary_path"
                      maxlength="255"
                      :placeholder="$t('settings.index.jumpPathPlaceholder')"
                    />
                  </div>
                </div>
                <div class="admin-field">
                  <span class="admin-field__label">
                    <span class="admin-field__label-scope">{{
                      $t('settings.index.slideIndexPrefix', { index: index + 1 })
                    }}</span>
                    {{ $t('settings.index.heroFieldSecondaryText') }}
                  </span>
                  <div class="admin-field__control">
                    <t-input
                      v-model="slide.secondary_text"
                      maxlength="20"
                      :placeholder="$t('settings.index.buttonTextPlaceholder')"
                    />
                  </div>
                </div>
                <div class="admin-field">
                  <span class="admin-field__label">
                    <span class="admin-field__label-scope">{{
                      $t('settings.index.slideIndexPrefix', { index: index + 1 })
                    }}</span>
                    {{ $t('settings.index.heroFieldSecondaryPath') }}
                  </span>
                  <div class="admin-field__control">
                    <t-input
                      v-model="slide.secondary_path"
                      maxlength="255"
                      :placeholder="$t('settings.index.jumpPathPlaceholder')"
                    />
                  </div>
                </div>
              </div>

              <div class="admin-field admin-field--full">
                <span class="admin-field__label">
                  <span class="admin-field__label-scope">{{
                    $t('settings.index.slideIndexPrefix', { index: index + 1 })
                  }}</span>
                  {{ $t('settings.index.heroFieldDesc') }}
                </span>
                <div class="admin-field__control">
                  <t-textarea
                    v-model="slide.desc"
                    :autosize="{ minRows: 2, maxRows: 4 }"
                    maxlength="300"
                    :placeholder="$t('settings.index.slideDescPlaceholder')"
                  />
                </div>
              </div>

              <div class="admin-field admin-field--full">
                <span class="admin-field__label">
                  <span class="admin-field__label-scope">{{
                    $t('settings.index.slideIndexPrefix', { index: index + 1 })
                  }}</span>
                  {{ $t('settings.index.slideImage') }}
                </span>
                <div class="admin-field__control">
                  <div class="slide-image-field">
                    <div class="slide-image-preview">
                      <img
                        v-if="slide.image"
                        :src="resolveAssetUrl(slide.image)"
                        :alt="slide.rail_title || $t('settings.index.slideBackgroundImage')"
                      />
                      <span v-else class="slide-image-preview__empty">{{ $t('settings.index.notSet') }}</span>
                    </div>
                    <div class="slide-image-selector" @click="openHeroImageDrawer(index)">
                      <image-icon />
                      <span v-if="slide.image" class="slide-video-selector__name">{{
                        slide.image.split('/').pop()
                      }}</span>
                      <span v-else class="slide-video-selector__placeholder">{{
                        $t('settings.index.clickToSelectImage')
                      }}</span>
                      <chevron-right-icon />
                    </div>
                    <t-button
                      v-if="slide.image"
                      variant="text"
                      size="small"
                      theme="danger"
                      @click="clearSlideImage(index)"
                    >
                      {{ $t('settings.index.clear') }}
                    </t-button>
                  </div>
                </div>
              </div>

              <div class="admin-field admin-field--full">
                <span class="admin-field__label">
                  <span class="admin-field__label-scope">{{
                    $t('settings.index.slideIndexPrefix', { index: index + 1 })
                  }}</span>
                  {{ $t('settings.index.slideVideo') }}
                </span>
                <div class="admin-field__control">
                  <div class="slide-video-selector" @click="openVideoDrawer(index)">
                    <video-icon />
                    <span v-if="slide.video" class="slide-video-selector__name">{{
                      videoDisplayName(slide.video)
                    }}</span>
                    <span v-else class="slide-video-selector__placeholder">{{
                      $t('settings.index.clickToSelectVideo')
                    }}</span>
                    <chevron-right-icon />
                  </div>
                </div>
              </div>
            </div>
          </article>
        </section>

        <section class="slides-section">
          <div class="slides-section-head">
            <div class="slides-section-head__text">
              <h2>{{ $t('settings.index.featuresTitle') }}（{{ heroForm.features.length }} / {{ maxFeatures }}）</h2>
              <p>{{ $t('settings.index.featuresTip') }}</p>
            </div>
            <t-space>
              <t-button variant="outline" @click="resetFeaturesToDefault">{{
                $t('settings.index.restoreDefault')
              }}</t-button>
              <t-button theme="primary" :disabled="heroForm.features.length >= maxFeatures" @click="addFeature">
                <template #icon><add-icon /></template>
                {{ $t('settings.index.addFeature') }}
              </t-button>
            </t-space>
          </div>

          <div class="feature-list">
            <article v-for="(feature, index) in heroForm.features" :key="`feature-${index}`" class="feature-card">
              <div class="feature-card-head">
                <span class="slide-card-head__index">{{ index + 1 }}</span>
                <div class="slide-card-head__actions">
                  <t-button variant="text" size="small" :disabled="index === 0" @click="moveFeature(index, -1)">
                    <template #icon><arrow-up-icon /></template>
                  </t-button>
                  <t-button
                    variant="text"
                    size="small"
                    :disabled="index === heroForm.features.length - 1"
                    @click="moveFeature(index, 1)"
                  >
                    <template #icon><arrow-down-icon /></template>
                  </t-button>
                  <t-button
                    theme="danger"
                    variant="text"
                    size="small"
                    :disabled="heroForm.features.length <= 1"
                    @click="removeFeature(index)"
                  >
                    <template #icon><delete-icon /></template>
                  </t-button>
                </div>
              </div>

              <div class="admin-field-grid">
                <div class="admin-field">
                  <span class="admin-field__label">
                    <span class="admin-field__label-scope">{{
                      $t('settings.index.featureIndexPrefix', { index: index + 1 })
                    }}</span>
                    {{ $t('settings.index.heroFieldKicker') }}
                  </span>
                  <div class="admin-field__control">
                    <t-input
                      v-model="feature.kicker"
                      maxlength="20"
                      :placeholder="$t('settings.index.kickerPlaceholder')"
                    />
                  </div>
                </div>
                <div class="admin-field">
                  <span class="admin-field__label">
                    <span class="admin-field__label-scope">{{
                      $t('settings.index.featureIndexPrefix', { index: index + 1 })
                    }}</span>
                    {{ $t('settings.index.heroFieldTitle') }}
                  </span>
                  <div class="admin-field__control">
                    <t-input
                      v-model="feature.title"
                      maxlength="50"
                      :placeholder="$t('settings.index.titlePlaceholder')"
                    />
                  </div>
                </div>
              </div>

              <div class="admin-field admin-field--full">
                <span class="admin-field__label">
                  <span class="admin-field__label-scope">{{
                    $t('settings.index.featureIndexPrefix', { index: index + 1 })
                  }}</span>
                  {{ $t('settings.index.heroFieldDesc') }}
                </span>
                <div class="admin-field__control">
                  <t-textarea
                    v-model="feature.desc"
                    :autosize="{ minRows: 2, maxRows: 3 }"
                    maxlength="120"
                    :placeholder="$t('settings.index.descriptionPlaceholder')"
                  />
                </div>
              </div>

              <div class="admin-field admin-field--full">
                <span class="admin-field__label">
                  <span class="admin-field__label-scope">{{
                    $t('settings.index.featureIndexPrefix', { index: index + 1 })
                  }}</span>
                  {{ $t('settings.index.jumpPathOptional') }}
                </span>
                <div class="admin-field__control">
                  <t-input
                    v-model="feature.path"
                    maxlength="255"
                    :placeholder="$t('settings.index.jumpPathPlaceholder')"
                  />
                </div>
              </div>
            </article>
          </div>
        </section>
      </t-card>

      <div class="settings-bottom-actions">
        <t-button theme="primary" :loading="currentSaving" :disabled="!canManageCurrentTab" @click="saveCurrentTab">
          <template #icon><check-icon /></template>
          {{ $t('settings.index.saveSettings') }}
        </t-button>
      </div>
    </template>

    <input
      ref="mediaDrawerUploadRef"
      class="hidden-file-input"
      type="file"
      accept="image/jpeg,image/png,image/webp,video/mp4,video/webm"
      @change="handleMediaDrawerUpload"
    />

    <t-drawer
      :visible="mediaDrawerVisible"
      :header="$t('settings.index.selectMedia')"
      size="520px"
      placement="right"
      :footer="null"
      @close="closeMediaDrawer"
    >
      <div class="cover-drawer-toolbar">
        <t-select v-model="mediaDrawerType" :placeholder="$t('settings.index.allTypes')" @change="loadMediaDrawerList">
          <t-option v-for="item in mediaDrawerTypeOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
        <t-button variant="outline" @click="openMediaDrawerUpload">
          <template #icon><upload-icon /></template>
          {{ $t('settings.index.uploadNewFile') }}
        </t-button>
      </div>
      <div class="cover-drawer-grid">
        <div
          v-for="item in mediaDrawerList"
          :key="item.url"
          class="cover-drawer-card"
          :class="{ 'is-selected': currentMediaValue() === item.url }"
          @click="selectMediaFromDrawer(item)"
        >
          <video
            v-if="item.isVideo"
            class="cover-drawer-card__img"
            :src="item.url"
            muted
            preload="metadata"
            playsinline
          ></video>
          <img v-else class="cover-drawer-card__img" :src="item.url" :alt="item.filename" loading="lazy" />
          <div class="cover-drawer-card__label">
            <check-circle-filled-icon v-if="currentMediaValue() === item.url" class="cover-drawer-card__check" />
            <span>{{ item.filename }}</span>
          </div>
        </div>
        <div v-if="!mediaDrawerList.length && !mediaDrawerLoading" class="cover-drawer-empty">
          {{ $t('settings.index.noMediaYet') }}
        </div>
      </div>
    </t-drawer>

    <t-drawer
      :visible="videoDrawerVisible"
      :header="videoDrawerTitle"
      size="560px"
      placement="right"
      :footer="null"
      @close="closeVideoDrawer"
    >
      <t-radio-group v-model="videoDrawerMode" variant="default-filled" class="video-drawer-tabs">
        <t-radio-button value="select">{{ $t('settings.index.selectExistingVideo') }}</t-radio-button>
        <t-radio-button value="url">{{ $t('settings.index.inputVideoUrl') }}</t-radio-button>
      </t-radio-group>

      <div v-if="videoDrawerMode === 'select'" class="video-drawer-grid">
        <div
          v-for="opt in heroVideoOptions"
          :key="opt.value"
          class="video-drawer-card"
          :class="{ 'is-selected': videoDrawerCurrentSrc === opt.value }"
          @click="selectVideoFromDrawer(opt.value)"
          @mouseenter="onVideoCardEnter($event)"
          @mouseleave="onVideoCardLeave($event)"
        >
          <video class="video-drawer-card__video" :src="opt.value" muted loop playsinline preload="metadata"></video>
          <div class="video-drawer-card__overlay">
            <check-circle-filled-icon v-if="videoDrawerCurrentSrc === opt.value" class="video-drawer-card__check" />
            <span class="video-drawer-card__name">{{ opt.filename || opt.value.split('/').pop() }}</span>
            <span v-if="opt.size" class="video-drawer-card__size">{{ formatFileSize(opt.size) }}</span>
          </div>
        </div>
        <div v-if="!heroVideoOptions.length" class="video-drawer-empty">{{ $t('settings.index.noHeroVideo') }}</div>
      </div>

      <div v-else class="video-drawer-url-mode">
        <p class="video-drawer-url-mode__hint">{{ $t('settings.index.videoUrlHint') }}</p>
        <t-input
          v-model="videoUrlInput"
          placeholder="https://example.com/videos/bg.mp4"
          clearable
          @keyup.enter="confirmVideoUrl"
        />
        <div v-if="videoUrlPreview" class="video-drawer-url-mode__preview">
          <video :src="videoUrlPreview" muted loop playsinline preload="metadata" controls></video>
        </div>
        <t-space class="video-drawer-url-mode__actions">
          <t-button theme="primary" @click="confirmVideoUrl">
            <template #icon><check-icon /></template>
            {{ $t('settings.index.confirmVideoUrl') }}
          </t-button>
        </t-space>
      </div>
    </t-drawer>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import {
  AddIcon,
  ArrowDownIcon,
  ArrowUpIcon,
  CheckCircleFilledIcon,
  CheckIcon,
  ChevronRightIcon,
  DeleteIcon,
  ImageIcon,
  UploadIcon,
  VideoIcon,
} from 'tdesign-icons-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import type { HomeHeroFeature, HomeHeroPayload, HomeHeroSlide, MediaFileRecord, SettingItem } from '@/api/admin';
import { adminApi } from '@/api/admin';
import SecretInput from '@/components/secret-input/index.vue';
import { AdminPermissions } from '@/constants/permissions';
import { t } from '@/locales';
import { hasAdminPermission } from '@/utils/permission';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminSettings' });

type SettingsTab = 'referral' | 'automation' | 'log_archive' | 'site_basic' | 'site_hero';
type FieldType =
  | 'input'
  | 'password'
  | 'textarea'
  | 'switch'
  | 'select'
  | 'number'
  | 'image'
  | 'time'
  // 品牌主色取色器；radius 为「预设档位 + 自定义像素」联动，两者都是无级联的下拉替代品
  | 'color'
  | 'radius';
type FieldValue = string | number | boolean | null;
type FieldStringValue = string | number | null;
type FieldNumberValue = number | null;

interface FieldOption {
  label: string;
  value: string | number | boolean;
}

/**
 * 圆角预设档位。
 *
 * 档位值取自项目既有规范（控件 3px / 容器 6px），商家选档位即可覆盖全站三端；
 * 选「自定义」则直接填像素值，后端会校验 0–24 后再下发。
 */
const RADIUS_PRESETS: FieldOption[] = [
  { label: t('settings.index.radiusSharp'), value: 0 },
  { label: t('settings.index.radiusCompact'), value: 3 },
  { label: t('settings.index.radiusStandard'), value: 6 },
  { label: t('settings.index.radiusSoft'), value: 12 },
];
const RADIUS_CUSTOM = 'custom';
const RADIUS_CUSTOM_LABEL = t('settings.index.customRadius');

/** 当前值命中哪个预设；命中不到就按自定义处理，这样手填的值不会被下拉悄悄改掉 */
function radiusPresetOf(value: FieldValue): string | number {
  const numeric = Number(value);
  // 预设值全是数字，用 Number 收窄类型的同时也避免把 boolean 带进 t-select 的 value
  const matched = RADIUS_PRESETS.find((option) => Number(option.value) === numeric);

  return matched ? Number(matched.value) : RADIUS_CUSTOM;
}

function applyRadiusPreset(field: SettingField, value: unknown) {
  if (value === RADIUS_CUSTOM) {
    // 切到自定义时保留原有数值；若原本就没有合法值，给一个居中的默认值
    const current = Number(form[field.key]);
    if (!Number.isFinite(current) || current < 0) {
      form[field.key] = 8;
    }

    return;
  }

  form[field.key] = Number(value);
}

interface SettingField {
  key: string;
  label: string;
  type?: FieldType;
  default?: FieldValue;
  group?: string;
  help?: string;
  options?: FieldOption[];
  min?: number;
  max?: number;
  rows?: number;
  wide?: boolean;
  required?: boolean;
  pattern?: RegExp;
  patternMessage?: string;
  maxlength?: number;
  placeholder?: string;
}

interface SettingSection {
  key: string;
  title: string;
  description?: string;
  fields: SettingField[];
}

interface SettingsConfig {
  group: string;
  title: string;
  description: string;
  sections: SettingSection[];
}

const route = useRoute();
const settingsLoading = ref(false);
const settingsSaving = ref(false);
const heroLoading = ref(false);
const heroSaving = ref(false);
const form = reactive<Record<string, FieldValue>>({});
const settingsGroupCache = reactive<Record<string, Record<string, unknown>>>({});
const settingsMetaCache = reactive<Record<string, Record<string, SettingItem>>>({});
const settingsSecretEdited = reactive<Record<string, boolean>>({});
const pendingImageField = ref<SettingField | null>(null);
// 媒体抽屉的第二个落点：首页轮播项的背景图。-1 表示当前目标是设置项字段。
const pendingHeroImageIndex = ref(-1);
const mediaDrawerVisible = ref(false);
const mediaDrawerLoading = ref(false);
const mediaDrawerList = ref<Array<{ url: string; filename: string; isVideo: boolean }>>([]);
const mediaDrawerType = ref('');
const mediaDrawerTypeOptions = [
  { label: t('content.mediaLibrary.allTypes'), value: '' },
  { label: t('content.mediaLibrary.image'), value: 'image' },
  { label: t('content.mediaLibrary.video'), value: 'video' },
];
const mediaDrawerUploadRef = ref<HTMLInputElement>();

const maxSlides = 5;
const maxFeatures = 5;
const heroDefaults = reactive<{ slides: HomeHeroSlide[]; features: HomeHeroFeature[] }>({ slides: [], features: [] });
const heroForm = reactive<{ slides: HomeHeroSlide[]; features: HomeHeroFeature[] }>({ slides: [], features: [] });
const heroVideoOptions = ref<Array<{ label: string; value: string; filename?: string; size?: number }>>([]);
const heroSnapshot = ref('');
const videoDrawerVisible = ref(false);
const videoDrawerSlideIndex = ref(-1);
const videoDrawerCurrentSrc = ref('');
const videoDrawerMode = ref<'select' | 'url'>('select');
const videoUrlInput = ref('');
const videoUrlPreview = ref('');

const automationScheduleModeOptions: FieldOption[] = [
  { label: t('settings.index.everyFifteenMinutes'), value: 'every_fifteen_minutes' },
  { label: t('settings.index.everyThirtyMinutes'), value: 'every_thirty_minutes' },
  { label: t('settings.index.hourly'), value: 'hourly' },
  { label: t('settings.index.daily'), value: 'daily' },
];

/**
 * 本 SFC 被多个菜单路由复用，每页一个 tab（路由 meta.settingsTab 决定显示哪一套字段）。
 * 这里只维护合法取值，供 ?tab= 查询参数与路由 meta 归一，不承担导航渲染。
 */
const SETTINGS_TABS: SettingsTab[] = ['site_basic', 'site_hero', 'automation', 'log_archive', 'referral'];

const configs: Record<Exclude<SettingsTab, 'site_hero'>, SettingsConfig> = {
  site_basic: {
    group: 'basic',
    title: t('settings.index.siteBasicTitle'),
    description: t('settings.index.siteBasicDesc'),
    sections: [
      {
        key: 'brand',
        title: t('settings.index.sectionBrand'),
        description: t('settings.index.sectionBrandDesc'),
        fields: [
          {
            key: 'site_name',
            label: t('settings.index.siteName'),
            type: 'input',
            default: '',
            maxlength: 50,
            placeholder: t('settings.index.siteNamePlaceholder'),
            help: t('settings.index.siteNameHint'),
          },
          {
            key: 'browser_title',
            label: t('settings.index.browserTitle'),
            type: 'input',
            default: '',
            maxlength: 80,
            placeholder: t('settings.index.browserTitlePlaceholder'),
            help: t('settings.index.browserTitleHint'),
          },
          {
            key: 'site_logo',
            label: t('settings.index.siteLogo'),
            type: 'image',
            default: '',
            maxlength: 255,
            placeholder: '/branding/logo.png',
            help: t('settings.index.siteLogoHint'),
          },
          {
            key: 'site_favicon',
            label: t('settings.index.siteFavicon'),
            type: 'image',
            default: '',
            maxlength: 255,
            placeholder: '/branding/favicon-32.png',
            help: t('settings.index.siteFaviconHint'),
          },
          {
            key: 'client_console_icon',
            label: t('settings.index.consoleIcon'),
            type: 'image',
            default: '',
            maxlength: 255,
            placeholder: '/branding/logo.png',
            help: t('settings.index.consoleIconHint'),
          },
          {
            key: 'brand_primary_color',
            label: t('settings.index.brandColor'),
            type: 'color',
            default: '#165DFF',
            maxlength: 7,
            pattern: /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i,
            patternMessage: t('settings.index.colorPattern'),
            help: t('settings.index.brandColorHint'),
          },
          {
            key: 'brand_radius',
            label: t('settings.index.radius'),
            type: 'radius',
            default: 6,
            min: 0,
            max: 24,
            help: t('settings.index.radiusHint'),
          },
        ],
      },
      {
        key: 'contact',
        title: t('settings.index.sectionContact'),
        description: t('settings.index.sectionContactDesc'),
        fields: [
          {
            key: 'service_phone',
            label: t('settings.index.officialQqGroup'),
            type: 'input',
            default: '',
            maxlength: 40,
            help: t('settings.index.officialQqGroupHint'),
          },
          {
            key: 'service_email',
            label: t('settings.index.contactEmail'),
            type: 'input',
            default: '',
            maxlength: 120,
            placeholder: t('settings.index.contactEmailPlaceholder'),
            help: t('settings.index.contactEmailHint'),
          },
          {
            key: 'service_hours',
            label: t('settings.index.serviceHours'),
            type: 'input',
            default: '',
            maxlength: 60,
            placeholder: t('settings.index.serviceHoursPlaceholder'),
            help: t('settings.index.serviceHoursHint'),
          },
          {
            key: 'support_group_qr',
            label: t('settings.index.supportQrCode'),
            type: 'image',
            default: '',
            maxlength: 255,
            help: t('settings.index.supportQrCodeHint'),
          },
          {
            key: 'support_group_link',
            label: t('settings.index.supportGroupLink'),
            type: 'input',
            default: '',
            maxlength: 255,
            placeholder: 'https://your-domain.com/group',
            help: t('settings.index.supportGroupLinkHint'),
          },
        ],
      },
      {
        key: 'compliance',
        title: t('settings.index.sectionCompliance'),
        description: t('settings.index.sectionComplianceDesc'),
        fields: [
          {
            key: 'icp_record',
            label: t('settings.index.icpNumber'),
            type: 'input',
            default: '',
            maxlength: 60,
            placeholder: t('settings.index.icpNumberPlaceholder'),
            help: t('settings.index.footerRowHint'),
          },
          {
            key: 'value_added_license',
            label: t('settings.index.licenseNumber'),
            type: 'input',
            default: '',
            maxlength: 80,
            placeholder: t('settings.index.licenseNumberPlaceholder'),
            help: t('settings.index.footerRowHint'),
          },
          {
            key: 'terms_url',
            label: t('settings.index.termsUrl'),
            type: 'input',
            default: '',
            maxlength: 255,
            placeholder: 'https://your-domain.com/terms',
            help: t('settings.index.legalUrlHint'),
          },
          {
            key: 'privacy_url',
            label: t('settings.index.privacyUrl'),
            type: 'input',
            default: '',
            maxlength: 255,
            placeholder: 'https://your-domain.com/privacy',
            help: t('settings.index.legalUrlHint'),
          },
        ],
      },
      {
        key: 'notification',
        title: t('settings.index.notificationMaster'),
        description: t('settings.index.notificationDesc'),
        fields: [
          {
            key: 'email_enabled',
            label: t('settings.index.emailEnabled'),
            type: 'switch',
            group: 'notification',
            default: false,
            help: t('settings.index.emailHint'),
          },
          {
            key: 'sms_enabled',
            label: t('settings.index.smsEnabled'),
            type: 'switch',
            group: 'notification',
            default: false,
            help: t('settings.index.smsHint'),
          },
        ],
      },
    ],
  },
  referral: {
    group: 'referral',
    title: t('settings.index.referralTitle'),
    description: t('settings.index.referralDesc'),
    sections: [
      {
        key: 'topup',
        title: t('settings.index.sectionTopUp'),
        // 账户充值属于「钱怎么进来」，与推荐返利同属交易侧配置，因此并到这一页，
        // 不再单独占一个只有单个开关的 tab（那个 tab 连菜单入口都没有）。
        fields: [
          {
            key: 'recharge_requires_verification',
            group: 'finance',
            label: t('settings.index.topUpRequireVerification'),
            type: 'switch',
            default: true,
            help: t('settings.index.topUpVerificationHint'),
          },
        ],
      },
      {
        key: 'reward',
        title: t('settings.index.sectionReferral'),
        fields: [
          {
            key: 'enabled',
            label: t('settings.index.referralEnabled'),
            type: 'switch',
            default: true,
            help: t('settings.index.referralEnabledHint'),
          },
          {
            key: 'reward_rate',
            label: t('settings.index.rewardRate'),
            type: 'number',
            default: 10,
            min: 0,
            max: 100,
            help: t('settings.index.rewardRateHint'),
          },
          {
            key: 'reward_freeze_days',
            label: t('settings.index.rewardFreezeDays'),
            type: 'number',
            default: 4,
            min: 0,
            max: 365,
            help: t('settings.index.rewardFreezeDaysHint'),
          },
          {
            key: 'withdraw_min_amount',
            label: t('settings.index.withdrawMin'),
            type: 'number',
            default: 20,
            min: 0,
            help: t('settings.index.withdrawMinHint'),
          },
        ],
      },
    ],
  },
  automation: {
    group: 'automation',
    title: t('settings.index.automationTitle'),
    description: t('settings.index.automationDesc'),
    sections: [
      {
        key: 'lifecycle',
        title: t('settings.index.serviceLifecycle'),
        description: t('settings.index.serviceLifecycleDesc'),
        fields: [
          {
            key: 'expire_suspend_enabled',
            label: t('settings.index.expireSuspendEnabled'),
            type: 'switch',
            default: true,
          },
          {
            key: 'expire_suspend_after_days',
            label: t('settings.index.expireSuspendDays'),
            type: 'number',
            default: 0,
            min: 0,
            max: 365,
            help: t('settings.index.expireSuspendDaysHint'),
          },
          {
            key: 'expire_suspend_notify_enabled',
            label: t('settings.index.suspendNotify'),
            type: 'switch',
            default: true,
          },
          {
            key: 'expire_unsuspend_notify_enabled',
            label: t('settings.index.resumeNotify'),
            type: 'switch',
            default: true,
          },
          {
            key: 'expire_terminate_enabled',
            label: t('settings.index.terminateEnabled'),
            type: 'switch',
            default: false,
          },
          {
            key: 'expire_terminate_after_days',
            label: t('settings.index.terminateDays'),
            type: 'number',
            default: 7,
            min: 1,
            max: 365,
            help: t('settings.index.terminateDaysHint'),
          },
          {
            key: 'service_lifecycle_schedule_mode',
            label: t('settings.index.lifecycleTaskInterval'),
            type: 'select',
            default: 'every_fifteen_minutes',
            options: automationScheduleModeOptions,
            help: t('settings.index.scheduleModeHint'),
          },
          {
            key: 'service_lifecycle_schedule_time',
            label: t('settings.index.lifecycleTaskRunAt'),
            type: 'time',
            default: '00:00:00',
            placeholder: t('settings.index.minutePlaceholder'),
            help: t('settings.index.scheduleTimeHint'),
            pattern: /^\d{2}:(00|15|30|45):00$/,
            patternMessage: t('settings.index.minutePattern'),
          },
        ],
      },
      {
        key: 'billing',
        title: t('settings.index.billingReminder'),
        description: t('settings.index.billingReminderDesc'),
        fields: [
          { key: 'renew_notice_enabled', label: t('settings.index.renewNoticeEnabled'), type: 'switch', default: true },
          {
            key: 'renew_create_invoice_enabled',
            label: t('settings.index.renewInvoiceEnabled'),
            type: 'switch',
            default: true,
          },
          {
            key: 'invoice_unpaid_reminder_enabled',
            label: t('settings.index.unpaidReminderEnabled'),
            type: 'switch',
            default: true,
          },
          {
            key: 'invoice_unpaid_before_due_days',
            label: t('settings.index.remindBeforeDueDays'),
            type: 'number',
            default: 1,
            min: 0,
            max: 30,
            help: t('settings.index.remindBeforeDueDaysHint'),
          },
          {
            key: 'invoice_overdue_reminder_days',
            label: t('settings.index.overdueRemindDays'),
            type: 'input',
            default: '1,3,5',
            wide: true,
            help: t('settings.index.overdueRemindDaysHint'),
            pattern: /^\d+(,\d+)*$/,
            patternMessage: t('settings.index.overdueDaysPattern'),
          },
          {
            key: 'invoice_overdue_after_days',
            label: t('settings.index.overdueAfterDays'),
            type: 'number',
            default: 0,
            min: 0,
            max: 365,
            help: t('settings.index.overdueAfterDaysHint'),
          },
          {
            key: 'billing_maintenance_schedule_mode',
            label: t('settings.index.billingTaskInterval'),
            type: 'select',
            default: 'hourly',
            options: automationScheduleModeOptions,
            help: t('settings.index.scheduleModeHint'),
          },
          {
            key: 'billing_maintenance_schedule_time',
            label: t('settings.index.billingTaskRunAt'),
            type: 'time',
            default: '00:00:00',
            placeholder: t('settings.index.minutePlaceholder'),
            help: t('settings.index.scheduleTimeHint'),
            pattern: /^\d{2}:(00|15|30|45):00$/,
            patternMessage: t('settings.index.minutePattern'),
          },
        ],
      },
      {
        key: 'product_description_sync',
        title: t('settings.index.productDescriptionSync'),
        description: t('settings.index.productDescriptionSyncDesc'),
        fields: [
          {
            key: 'product_description_sync_schedule_mode',
            label: t('settings.index.syncTaskInterval'),
            type: 'select',
            default: 'daily',
            options: automationScheduleModeOptions,
            help: t('settings.index.scheduleModeHint'),
          },
          {
            key: 'product_description_sync_schedule_time',
            label: t('settings.index.syncTaskRunAt'),
            type: 'time',
            default: '03:45:00',
            placeholder: t('settings.index.minutePlaceholder'),
            help: t('settings.index.scheduleTimeHint'),
            pattern: /^\d{2}:(00|15|30|45):00$/,
            patternMessage: t('settings.index.minutePattern'),
          },
        ],
      },
      {
        key: 'ticket_cleanup',
        title: t('settings.index.ticketCleanup'),
        description: t('settings.index.ticketCleanupDesc'),
        fields: [
          {
            key: 'ticket_auto_close_enabled',
            label: t('settings.index.ticketAutoClose'),
            type: 'switch',
            default: true,
            help: t('settings.index.ticketAutoCloseHint'),
          },
          {
            key: 'ticket_auto_close_after_hours',
            label: t('settings.index.ticketAutoCloseHours'),
            type: 'number',
            default: 48,
            min: 1,
            max: 720,
            help: t('settings.index.ticketAutoCloseHoursHint'),
          },
          {
            key: 'ticket_auto_close_schedule_mode',
            label: t('settings.index.ticketTaskInterval'),
            type: 'select',
            default: 'hourly',
            options: automationScheduleModeOptions,
            help: t('settings.index.scheduleModeHint'),
          },
          {
            key: 'ticket_auto_close_schedule_time',
            label: t('settings.index.ticketRunAt'),
            type: 'time',
            default: '00:00:00',
            placeholder: t('settings.index.minutePlaceholder'),
            help: t('settings.index.scheduleTimeHint'),
            pattern: /^\d{2}:(00|15|30|45):00$/,
            patternMessage: t('settings.index.minutePattern'),
          },
          {
            key: 'order_cleanup_schedule_mode',
            label: t('settings.index.cleanupInterval'),
            type: 'select',
            default: 'every_fifteen_minutes',
            options: automationScheduleModeOptions,
            help: t('settings.index.cleanupIntervalHint'),
          },
          {
            key: 'order_cleanup_schedule_time',
            label: t('settings.index.cleanupRunAt'),
            type: 'time',
            default: '00:00:00',
            placeholder: t('settings.index.minutePlaceholder'),
            help: t('settings.index.scheduleTimeHint'),
            pattern: /^\d{2}:(00|15|30|45):00$/,
            patternMessage: t('settings.index.minutePattern'),
          },
        ],
      },
      {
        key: 'upstream',
        title: t('settings.index.upstreamBalanceAlert'),
        description: t('settings.index.upstreamBalanceAlertDesc'),
        fields: [
          {
            key: 'supplier_balance_log_retention_days',
            label: t('settings.index.upstreamLogRetentionDays'),
            type: 'number',
            default: 30,
            min: 1,
            max: 365,
            help: t('settings.index.upstreamLogRetentionHint'),
          },
          {
            key: 'supplier_low_balance_alert_repeat_hours',
            label: t('settings.index.balanceAlertRepeatHours'),
            type: 'number',
            default: 24,
            min: 1,
            max: 720,
            wide: true,
            help: t('settings.index.balanceAlertHint'),
          },
        ],
      },
    ],
  },
  log_archive: {
    group: 'log_archive',
    title: t('settings.index.logArchiveTitle'),
    description: t('settings.index.logArchiveDesc'),
    sections: [
      {
        key: 'runtime',
        title: t('settings.index.runtimeParams'),
        description: t('settings.index.runtimeParamsDesc'),
        fields: [
          {
            key: 'pt_archiver_binary',
            label: t('settings.index.archiverBinaryPath'),
            type: 'input',
            default: '/usr/bin/pt-archiver',
            required: true,
            maxlength: 255,
            help: t('settings.index.archiverBinaryPathHint'),
          },
          {
            key: 'pt_archiver_defaults_file',
            label: t('settings.index.archiverConfigFile'),
            type: 'input',
            default: '/etc/turaidc/pt-archiver.cnf',
            required: true,
            maxlength: 255,
            help: t('settings.index.archiverConfigFileHint'),
          },
          {
            key: 'concurrency',
            label: t('settings.index.maxConcurrency'),
            type: 'number',
            default: 2,
            min: 1,
            max: 8,
            help: t('settings.index.maxConcurrencyHint'),
          },
          {
            key: 'batch_size',
            label: t('settings.index.batchSize'),
            type: 'number',
            default: 1000,
            min: 100,
            max: 10000,
            help: t('settings.index.batchSizeHint'),
          },
          {
            key: 'sleep_seconds',
            label: t('settings.index.batchInterval'),
            type: 'number',
            default: 1,
            min: 0,
            max: 60,
            help: t('settings.index.batchIntervalHint'),
          },
        ],
      },
      {
        key: 'retention',
        title: t('settings.index.retentionPolicy'),
        description: t('settings.index.retentionPolicyDesc'),
        fields: [
          {
            key: 'retention_days',
            label: t('settings.index.logRetentionDays'),
            type: 'number',
            default: 30,
            min: 1,
            max: 3650,
            help: t('settings.index.logRetentionDaysHint'),
          },
          {
            key: 'file_retention_days',
            label: t('settings.index.archiveRetentionDays'),
            type: 'number',
            default: 180,
            min: 1,
            max: 3650,
            help: t('settings.index.archiveRetentionDaysHint'),
          },
        ],
      },
    ],
  },
};

const pageConfig = computed<SettingsConfig | null>(() =>
  activeTab.value === 'site_hero' ? null : configs[activeTab.value],
);
const sections = computed(() => pageConfig.value?.sections || []);
const allFields = computed(() => sections.value.flatMap((section) => section.fields));
const activeGroups = computed(() =>
  Array.from(new Set(allFields.value.map((field) => field.group || pageConfig.value?.group || 'system'))),
);
const currentSaving = computed(() => (activeTab.value === 'site_hero' ? heroSaving.value : settingsSaving.value));
const heroDirty = computed(() => JSON.stringify(heroForm) !== heroSnapshot.value);

const videoDrawerTitle = computed(() => {
  if (videoDrawerSlideIndex.value < 0) return t('settings.index.selectBackgroundVideo');
  const slide = heroForm.slides[videoDrawerSlideIndex.value];
  return slide?.rail_title
    ? t('settings.index.backgroundVideoNthWithName', {
        index: videoDrawerSlideIndex.value + 1,
        name: slide.rail_title,
      })
    : t('settings.index.backgroundVideoNth', { index: videoDrawerSlideIndex.value + 1 });
});

function normalizeTab(value: unknown): SettingsTab {
  const tab = Array.isArray(value) ? value[0] : value;
  if (tab === 'site') return 'site_basic';
  // 旧的「账户与财务」tab 已并入推荐与充值页，历史链接仍要落到能改那个开关的位置
  if (tab === 'finance') return 'referral';
  return SETTINGS_TABS.includes(tab as SettingsTab) ? (tab as SettingsTab) : 'automation';
}

function resolveInitialTab(): SettingsTab {
  const metaTab = route.meta.settingsTab;
  if (typeof metaTab === 'string' && SETTINGS_TABS.includes(metaTab as SettingsTab)) {
    return metaTab as SettingsTab;
  }
  return normalizeTab(route.query.tab);
}

const activeTab = ref<SettingsTab>(resolveInitialTab());
const canManageSettings = computed(() => hasAdminPermission(AdminPermissions.SETTINGS_MANAGE));
const canManageSite = computed(() => hasAdminPermission(AdminPermissions.SITE_MANAGE));
const canRevealSettingsSecret = computed(() => hasAdminPermission(AdminPermissions.SETTINGS_SECRET_REVEAL));
const canManageCurrentTab = computed(() =>
  activeTab.value === 'site_hero' ? canManageSite.value : canManageSettings.value,
);

function refreshCurrentTab() {
  if (activeTab.value === 'site_hero') return loadHero();
  return loadSettings();
}

// 路由切换时同步 activeTab
watch(
  () => route.meta.settingsTab,
  (newTab) => {
    if (typeof newTab === 'string' && SETTINGS_TABS.includes(newTab as SettingsTab)) {
      activeTab.value = newTab as SettingsTab;
      refreshCurrentTab();
    }
  },
);

function saveCurrentTab() {
  if (!canManageCurrentTab.value) {
    MessagePlugin.warning(t('settings.index.noSavePermission'));
    return undefined;
  }

  if (activeTab.value === 'site_hero') return saveHero();
  return saveSettings();
}

function resetFormDefaults() {
  Object.keys(form).forEach((key) => delete form[key]);
  Object.keys(settingsSecretEdited).forEach((key) => delete settingsSecretEdited[key]);
  allFields.value.forEach((field) => {
    form[field.key] = field.default ?? (field.type === 'switch' ? false : '');
  });
}

async function loadSettings() {
  if (!pageConfig.value) return;
  settingsLoading.value = true;
  resetFormDefaults();
  try {
    const maps = Object.fromEntries(
      await Promise.all(
        activeGroups.value.map(async (group): Promise<[string, Record<string, unknown>]> => [
          group,
          await loadSettingsGroup(group),
        ]),
      ),
    );
    allFields.value.forEach((field) => {
      const group = field.group || pageConfig.value?.group || 'system';
      form[field.key] = parseFieldValue(field, maps[group]?.[field.key]);
    });
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('settings.index.loadFailed')));
  } finally {
    settingsLoading.value = false;
  }
}

async function saveSettings() {
  if (!canManageSettings.value) {
    MessagePlugin.warning(t('settings.index.noConfigPermission'));
    return;
  }

  if (!validateSettings()) return;
  settingsSaving.value = true;
  try {
    const payload = buildSettingsPayload();
    await Promise.all(Object.entries(payload).map(([group, settings]) => adminApi.settings.save({ group, settings })));
    Object.entries(payload).forEach(([group, settings]) => {
      settingsGroupCache[group] = { ...(settingsGroupCache[group] || {}), ...settings };
    });
    MessagePlugin.success(
      t('settings.index.savedSuffix', { title: pageConfig.value?.title || t('settings.index.defaultTitle') }),
    );
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('settings.index.saveFailed')));
  } finally {
    settingsSaving.value = false;
  }
}

function validateSettings() {
  for (const field of allFields.value) {
    const value = form[field.key];
    if (isFieldRequired(field) && !String(value ?? '').trim() && field.type !== 'switch') {
      MessagePlugin.warning(t('settings.index.fieldRequired', { label: field.label }));
      return false;
    }
    if (field.type === 'number' || field.type === 'radius') {
      const num = Number(value);
      if (!Number.isFinite(num)) {
        MessagePlugin.warning(t('settings.index.fieldNumber', { label: field.label }));
        return false;
      }
      if (field.min !== undefined && num < field.min) {
        MessagePlugin.warning(t('settings.index.fieldMin', { label: field.label, min: field.min }));
        return false;
      }
      if (field.max !== undefined && num > field.max) {
        MessagePlugin.warning(t('settings.index.fieldMax', { label: field.label, max: field.max }));
        return false;
      }
    }
    if (field.pattern && !field.pattern.test(String(value ?? '').trim())) {
      MessagePlugin.warning(field.patternMessage || t('settings.index.fieldPattern', { label: field.label }));
      return false;
    }
  }
  return true;
}

function buildSettingsPayload() {
  return allFields.value.reduce<Record<string, Record<string, FieldValue>>>((payload, field) => {
    const group = field.group || pageConfig.value?.group || 'system';
    let value = form[field.key];
    if (field.type === 'switch') value = value ? 1 : 0;
    if (
      isSecretSettingField(field) &&
      hasSettingSecretValue(field) &&
      !settingsSecretEdited[settingSecretEditKey(field)]
    ) {
      value = '';
    }
    if (!payload[group]) payload[group] = {};
    payload[group][field.key] = value;
    return payload;
  }, {});
}

async function loadSettingsGroup(group: string) {
  if (settingsGroupCache[group]) return settingsGroupCache[group];
  const response = await adminApi.settings.list({ group });
  settingsGroupCache[group] = normalizeSettings(response);
  settingsMetaCache[group] = normalizeSettingItems(response);
  return settingsGroupCache[group];
}

function normalizeSettings(response: SettingItem[] | Record<string, unknown>) {
  if (Array.isArray(response)) return Object.fromEntries(response.map((item) => [item.key, item.value]));
  const record = toRecord(response);
  if (Array.isArray(record.list))
    return Object.fromEntries((record.list as SettingItem[]).map((item) => [item.key, item.value]));
  return record;
}

function normalizeSettingItems(response: SettingItem[] | Record<string, unknown>) {
  if (Array.isArray(response)) return Object.fromEntries(response.map((item) => [item.key, item]));
  const record = toRecord(response);
  if (Array.isArray(record.list))
    return Object.fromEntries((record.list as SettingItem[]).map((item) => [item.key, item]));
  return {};
}

function settingFieldGroup(field: SettingField) {
  return field.group || pageConfig.value?.group || 'system';
}

function settingSecretEditKey(field: SettingField) {
  return `${settingFieldGroup(field)}:${field.key}`;
}

function settingSecretResetKey(field: SettingField) {
  return `${activeTab.value}:${settingSecretEditKey(field)}`;
}

function settingMeta(field: SettingField) {
  return settingsMetaCache[settingFieldGroup(field)]?.[field.key];
}

function isSecretSettingField(field: SettingField) {
  return field.type === 'password' && settingMeta(field)?.is_secret === true;
}

function hasSettingSecretValue(field: SettingField) {
  return settingMeta(field)?.has_value === true;
}

async function revealSettingSecret(field: SettingField) {
  if (!canRevealSettingsSecret.value) return '';

  const response = await adminApi.settings.revealSecret(settingFieldGroup(field), field.key);
  return response.value;
}

function parseFieldValue(field: SettingField, raw: unknown): FieldValue {
  if (raw === undefined || raw === null || raw === '') return field.default ?? (field.type === 'switch' ? false : '');
  if (field.type === 'switch') return raw === true || raw === 1 || raw === '1' || raw === 'true';
  if (field.type === 'number' || field.type === 'radius') {
    const parsed = Number(raw);
    return Number.isFinite(parsed) ? parsed : Number(field.default || 0);
  }
  return String(raw);
}

function isFieldRequired(field: SettingField) {
  return Boolean(field.required);
}

/**
 * 控件宽度：数字、下拉、时点、取色这类短控件收窄，文本与图片选择器铺满所在栅格。
 * 宽度只在这里给类名，间距与栅格由全局 admin-field 样式统一定义。
 */
function controlWidthClass(field: SettingField) {
  if (field.wide) return '';
  return ['number', 'select', 'time', 'color', 'radius'].includes(field.type || 'input')
    ? 'admin-field__control--medium'
    : '';
}

function selectImage(field: SettingField) {
  pendingImageField.value = field;
  pendingHeroImageIndex.value = -1;
  openMediaDrawer();
}

/**
 * 打开媒体抽屉为某个轮播项选背景图。
 *
 * 复用设置项那套媒体库（上传/筛选/选择），只是回写目标换成
 * heroForm.slides[index].image，避免为轮播再实现一遍文件管理。
 */
function openHeroImageDrawer(slideIndex: number) {
  pendingImageField.value = null;
  pendingHeroImageIndex.value = slideIndex;
  mediaDrawerVisible.value = true;
  mediaDrawerType.value = 'image';
  loadMediaDrawerList();
}

function clearSlideImage(slideIndex: number) {
  const slide = heroForm.slides[slideIndex];
  if (slide) slide.image = '';
}

/**
 * 把站内相对路径（如 /media/xx.jpg）解析成可访问地址。
 *
 * 不能拿 VITE_API_BASE_URL 去拼：那个值是 `.../api`，而 /media 是静态资源目录、
 * 不归 API 前缀管，拼出来会变成 `.../api/media/xx.jpg` —— 一个打不开的地址。
 * 三端同域部署，直接用当前页的 origin 就是站点根，与后端 UploadUrl::resolve
 * 的口径一致（后端下发的绝对地址同样不带 /api）。
 */
function resolveAssetUrl(raw: unknown): string {
  const path = String(raw || '').trim();
  if (!path) return '';
  // 非捕获组：这里只需要判断协议前缀，不需要取出协议文本本身。
  if (/^(?:https?:)?\/\//.test(path)) return path;

  if (typeof window !== 'undefined' && window.location?.origin) {
    return `${window.location.origin}/${path.replace(/^\/+/, '')}`;
  }

  // SSR / 非浏览器环境兜底：由 API 基址回推站点根（去掉 /api 尾巴）
  const apiBaseUrl = String(import.meta.env.VITE_API_BASE_URL || '')
    .trim()
    .replace(/\/+$/, '');
  const siteRoot = apiBaseUrl.replace(/\/api$/i, '');
  if (!siteRoot) return path;
  return `${siteRoot}/${path.replace(/^\/+/, '')}`;
}

/** 媒体抽屉当前回写目标的值（设置项字段 or 轮播项背景图） */
function currentMediaValue(): string {
  if (pendingHeroImageIndex.value >= 0) {
    return String(heroForm.slides[pendingHeroImageIndex.value]?.image || '');
  }
  const field = pendingImageField.value;
  return field ? String(form[field.key] ?? '') : '';
}

/** 把选中的媒体写回当前目标 */
function applyMediaToTarget(url: string) {
  if (pendingHeroImageIndex.value >= 0) {
    const slide = heroForm.slides[pendingHeroImageIndex.value];
    if (slide) slide.image = url;
    return;
  }
  const field = pendingImageField.value;
  if (field) form[field.key] = url;
}

function openMediaDrawer() {
  mediaDrawerVisible.value = true;
  mediaDrawerType.value = '';
  loadMediaDrawerList();
}

function closeMediaDrawer() {
  mediaDrawerVisible.value = false;
}

async function loadMediaDrawerList() {
  mediaDrawerLoading.value = true;
  try {
    const res = await adminApi.media.list({
      type: mediaDrawerType.value || undefined,
      page_size: 100,
    });
    mediaDrawerList.value = (res.list || [])
      .map(function (item) {
        return {
          url: String(item.url || ''),
          filename:
            String(item.filename || '')
              .split('/')
              .pop() || '',
          isVideo: isMediaDrawerVideo(item),
        };
      })
      .filter(function (item) {
        return item.url;
      });
  } catch {
    mediaDrawerList.value = [];
  } finally {
    mediaDrawerLoading.value = false;
  }
}

function isMediaDrawerVideo(row: MediaFileRecord): boolean {
  return String(row.type || '').toLowerCase() === 'video' || String(row.mime_type || '').startsWith('video/');
}

function selectMediaFromDrawer(item: { url: string; filename: string; isVideo: boolean }) {
  if (pendingHeroImageIndex.value < 0 && !pendingImageField.value) return;
  applyMediaToTarget(item.url);
  MessagePlugin.success(t('settings.index.selected'));
  closeMediaDrawer();
}

function openMediaDrawerUpload() {
  if (mediaDrawerUploadRef.value) {
    mediaDrawerUploadRef.value.value = '';
    mediaDrawerUploadRef.value.click();
  }
}

async function handleMediaDrawerUpload(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  const data = new FormData();
  data.append('file', file);
  data.append('group', 'site-settings');
  try {
    const response = await adminApi.media.upload(data);
    const url = String(response.url || '');
    if (url) {
      mediaDrawerList.value.unshift({
        url,
        filename:
          String(response.filename || '')
            .split('/')
            .pop() || file.name,
        isVideo: isMediaDrawerVideo(response),
      });
    }
    // Also set as the selected image
    if (pendingHeroImageIndex.value >= 0 || pendingImageField.value) {
      applyMediaToTarget(url);
    }
    MessagePlugin.success(t('settings.index.uploadSuccess'));
  } catch (error) {
    const record = error as Record<string, unknown>;
    MessagePlugin.error(String(record.message || t('settings.index.uploadFailed')));
  }
}

async function loadHero() {
  heroLoading.value = true;
  try {
    const data = await adminApi.siteHero.get();
    applyHeroPayload(data);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('settings.index.loadHeroFailed')));
  } finally {
    heroLoading.value = false;
  }
}

function applyHeroPayload(payload: HomeHeroPayload = {}) {
  heroDefaults.slides = cloneList(payload.defaults?.slides);
  heroDefaults.features = cloneList(payload.defaults?.features);
  heroVideoOptions.value = normalizeHeroVideoOptions(payload.options?.videos);
  heroForm.slides = cloneList(payload.slides).length ? cloneList(payload.slides) : cloneList(heroDefaults.slides);
  heroForm.features = cloneList(payload.features).length
    ? cloneList(payload.features)
    : cloneList(heroDefaults.features);
  heroSnapshot.value = JSON.stringify(heroForm);
}

async function saveHero() {
  if (!canManageSite.value) {
    MessagePlugin.warning(t('settings.index.noSitePermission'));
    return;
  }

  if (!validateHero()) return;
  heroSaving.value = true;
  try {
    const payload = {
      slides: heroForm.slides.map((item) => ({ ...item })),
      features: heroForm.features.map((item) => ({ ...item })),
    };
    const response = await adminApi.siteHero.save(payload);
    applyHeroPayload({
      ...response,
      slides: response.slides || payload.slides,
      features: response.features || payload.features,
    });
    MessagePlugin.success(t('settings.index.heroSaved'));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('settings.index.saveHeroFailed')));
  } finally {
    heroSaving.value = false;
  }
}

function validateHero() {
  for (const [index, slide] of heroForm.slides.entries()) {
    for (const [key, label] of [
      ['rail_title', t('settings.index.heroFieldRailTitle')],
      ['title', t('settings.index.heroFieldTitle')],
      ['desc', t('settings.index.heroFieldDesc')],
      ['primary_text', t('settings.index.heroFieldPrimaryText')],
      ['primary_path', t('settings.index.heroFieldPrimaryPath')],
      ['secondary_text', t('settings.index.heroFieldSecondaryText')],
      ['secondary_path', t('settings.index.heroFieldSecondaryPath')],
    ] as Array<[keyof HomeHeroSlide, string]>) {
      if (!String(slide[key] ?? '').trim()) {
        MessagePlugin.warning(t('settings.index.slideFieldRequired', { index: index + 1, label }));
        return false;
      }
    }
  }
  for (const [index, feature] of heroForm.features.entries()) {
    for (const [key, label] of [
      ['kicker', t('settings.index.heroFieldKicker')],
      ['title', t('settings.index.heroFieldTitle')],
      ['desc', t('settings.index.heroFieldDesc')],
    ] as Array<[keyof HomeHeroFeature, string]>) {
      if (!String(feature[key] ?? '').trim()) {
        MessagePlugin.warning(t('settings.index.featureFieldRequired', { index: index + 1, label }));
        return false;
      }
    }
  }
  return true;
}

function buildBlankSlide(): HomeHeroSlide {
  return {
    rail_title: t('settings.index.newSlide'),
    title: '',
    desc: '',
    primary_text: t('settings.index.primaryButtonText'),
    primary_path: '/products',
    secondary_text: t('settings.index.secondaryButtonText'),
    secondary_path: '/about',
    // 新卡片的背景视频必须是空的：以前默认取视频列表第一项，新增几张就几张挂着同一个视频被误发布。
    video: '',
  };
}

function buildBlankFeature(): HomeHeroFeature {
  return { kicker: t('settings.index.newFeature'), title: '', desc: '', path: '' };
}

function addSlide() {
  if (heroForm.slides.length < maxSlides) heroForm.slides.push(buildBlankSlide());
}

function removeSlide(index: number) {
  if (heroForm.slides.length > 1) heroForm.slides.splice(index, 1);
}

function moveSlide(index: number, offset: number) {
  moveItem(heroForm.slides, index, offset);
}

function addFeature() {
  if (heroForm.features.length < maxFeatures) heroForm.features.push(buildBlankFeature());
}

function removeFeature(index: number) {
  if (heroForm.features.length > 1) heroForm.features.splice(index, 1);
}

function moveFeature(index: number, offset: number) {
  moveItem(heroForm.features, index, offset);
}

function resetSlidesToDefault() {
  const dialog = DialogPlugin.confirm({
    header: t('settings.index.restoreSlidesTitle'),
    body: t('settings.index.restoreSlidesBody'),
    confirmBtn: t('settings.index.restoreDefault'),
    cancelBtn: t('common.cancel'),
    onConfirm: () => {
      heroForm.slides = cloneList(heroDefaults.slides);
      dialog.destroy();
    },
  });
}

function resetFeaturesToDefault() {
  const dialog = DialogPlugin.confirm({
    header: t('settings.index.restoreFeaturesTitle'),
    body: t('settings.index.restoreFeaturesBody'),
    confirmBtn: t('settings.index.restoreDefault'),
    cancelBtn: t('common.cancel'),
    onConfirm: () => {
      heroForm.features = cloneList(heroDefaults.features);
      dialog.destroy();
    },
  });
}

function normalizeHeroVideoOptions(list: unknown) {
  if (!Array.isArray(list)) return [];
  return list
    .map((item) => {
      const record = toRecord(item);
      const value = String(record.path || record.url || '').trim();
      if (!value) return null;
      const filename = String(record.filename || value.split('/').pop() || value);
      const size = Number(record.size || 0);
      return {
        label: size > 0 ? `${filename} · ${formatFileSize(size)}` : filename,
        value,
        filename,
        size,
      };
    })
    .filter(Boolean) as Array<{ label: string; value: string; filename?: string; size?: number }>;
}

function formatFileSize(size: number) {
  if (!Number.isFinite(size) || size <= 0) return '';
  if (size >= 1024 * 1024) return `${(size / 1024 / 1024).toFixed(1)} MB`;
  if (size >= 1024) return `${Math.round(size / 1024)} KB`;
  return `${size} B`;
}

function onVideoCardEnter(event: MouseEvent) {
  const el = event.currentTarget as HTMLElement;
  const video = el.querySelector('video') as HTMLVideoElement | null;
  if (video) {
    video.currentTime = 0;
    video.play().catch(() => {});
  }
}

function onVideoCardLeave(event: MouseEvent) {
  const el = event.currentTarget as HTMLElement;
  const video = el.querySelector('video') as HTMLVideoElement | null;
  if (video) {
    video.pause();
    video.currentTime = 0;
  }
}

function openVideoDrawer(slideIndex: number) {
  videoDrawerSlideIndex.value = slideIndex;
  const current = String(heroForm.slides[slideIndex]?.video || '');
  videoDrawerCurrentSrc.value = current;
  const isPredefined = heroVideoOptions.value.some((opt) => opt.value === current);
  if (current && !isPredefined) {
    videoDrawerMode.value = 'url';
    videoUrlInput.value = current;
    videoUrlPreview.value = current;
  } else {
    videoDrawerMode.value = 'select';
    videoUrlInput.value = '';
    videoUrlPreview.value = '';
  }
  videoDrawerVisible.value = true;
}

function closeVideoDrawer() {
  videoDrawerVisible.value = false;
  videoDrawerSlideIndex.value = -1;
  videoUrlInput.value = '';
  videoUrlPreview.value = '';
}

function selectVideoFromDrawer(value: string) {
  const idx = videoDrawerSlideIndex.value;
  if (idx >= 0 && idx < heroForm.slides.length) {
    heroForm.slides[idx].video = heroForm.slides[idx].video === value ? '' : value;
  }
  closeVideoDrawer();
}

function confirmVideoUrl() {
  const url = videoUrlInput.value.trim();
  if (!url) {
    MessagePlugin.warning(t('settings.index.videoUrlRequired'));
    return;
  }
  if (!/^https?:\/\/.+/.test(url)) {
    MessagePlugin.warning(t('settings.index.invalidUrl'));
    return;
  }
  const idx = videoDrawerSlideIndex.value;
  if (idx >= 0 && idx < heroForm.slides.length) {
    heroForm.slides[idx].video = url;
  }
  MessagePlugin.success(t('settings.index.videoUrlSet'));
  closeVideoDrawer();
}

function videoDisplayName(src: string) {
  if (!src) return '';
  const opt = heroVideoOptions.value.find((item) => item.value === src);
  if (opt?.size) return `${opt.filename || src.split('/').pop()} · ${formatFileSize(opt.size)}`;
  if (opt) return opt.filename || src.split('/').pop() || src;
  // External URL — show shortened domain path
  try {
    const url = new URL(src);
    const path = url.pathname.split('/').filter(Boolean).pop() || url.hostname;
    return t('settings.index.externalPrefix', { path });
  } catch {
    return src.split('/').pop() || src;
  }
}

function moveItem<T>(list: T[], index: number, offset: number) {
  const target = index + offset;
  if (target < 0 || target >= list.length) return;
  const [item] = list.splice(index, 1);
  list.splice(target, 0, item);
}

function cloneList<T extends Record<string, unknown>>(list?: T[]) {
  return Array.isArray(list) ? list.map((item) => ({ ...item })) : [];
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

watch(videoUrlInput, (value) => {
  if (/^https?:\/\/.+/.test(value)) {
    videoUrlPreview.value = value;
  } else {
    videoUrlPreview.value = '';
  }
});

watch(
  () => route.query.tab,
  (value) => {
    const next = normalizeTab(value);
    if (next === activeTab.value) return;
    activeTab.value = next;
    refreshCurrentTab();
  },
);

onMounted(() => {
  refreshCurrentTab();
});
</script>
