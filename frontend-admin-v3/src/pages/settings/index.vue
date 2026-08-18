<template>
  <div class="settings-page">
    <template v-if="activeTab !== 'site_hero'">
      <t-card v-if="sections.length > 1" :bordered="false">
        <div class="section-nav">
          <span>分组</span>
          <t-radio-group v-model="activeSection" variant="default-filled">
            <t-radio-button v-for="section in sections" :key="section.anchor" :value="section.anchor">
              {{ section.title }} · {{ section.fields.length }} 项
            </t-radio-button>
          </t-radio-group>
        </div>
      </t-card>

      <t-card :bordered="false" :loading="settingsLoading">
        <template v-if="currentSection" #title>{{ currentSection.title }}</template>
        <template v-if="currentSection?.description" #subtitle>{{ currentSection.description }}</template>

        <t-form :data="form" label-align="top" class="settings-form" :disabled="!canManageCurrentTab">
          <div class="settings-field-grid">
            <article
              v-for="field in currentSection?.fields || []"
              :key="field.key"
              class="field-card"
              :class="{ 'field-card--switch': field.type === 'switch', 'field-card--wide': field.wide }"
            >
              <div class="field-info">
                <strong>
                  <span v-if="isFieldRequired(field)" class="required-mark">*</span>
                  {{ field.label }}
                </strong>
                <p v-if="field.help">{{ field.help }}</p>
              </div>
              <div class="field-control">
                <t-switch v-if="field.type === 'switch'" v-model="form[field.key]" />
                <t-select
                  v-else-if="field.type === 'select'"
                  v-model="form[field.key]"
                  clearable
                  :placeholder="field.placeholder || `请选择${field.label}`"
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
                  v-model="form[field.key]"
                  theme="normal"
                  :min="field.min"
                  :max="field.max"
                  :placeholder="field.placeholder || `请输入${field.label}`"
                />
                <t-textarea
                  v-else-if="field.type === 'textarea'"
                  v-model="form[field.key]"
                  :autosize="{ minRows: field.rows || 3, maxRows: Math.max(field.rows || 3, 6) }"
                  :maxlength="field.maxlength"
                  :placeholder="field.placeholder || `请输入${field.label}`"
                />
                <t-time-picker
                  v-else-if="field.type === 'time'"
                  v-model="form[field.key]"
                  clearable
                  format="HH:mm:ss"
                  :placeholder="field.placeholder || `请选择${field.label}`"
                />
                <div v-else-if="field.type === 'image'" class="cover-image-selector" @click="selectImage(field)">
                  <image-icon />
                  <span v-if="form[field.key]" class="cover-image-selector__name">{{
                    String(form[field.key]).split('/').pop()
                  }}</span>
                  <span v-else class="cover-image-selector__placeholder">点击选择{{ field.label }}</span>
                  <chevron-right-icon />
                </div>
                <secret-input
                  v-else-if="isSecretSettingField(field)"
                  v-model="form[field.key]"
                  :has-value="hasSettingSecretValue(field)"
                  :placeholder="field.placeholder || `请输入${field.label}`"
                  :reset-key="settingSecretResetKey(field)"
                  :can-reveal="canRevealSettingsSecret"
                  :reveal="() => revealSettingSecret(field)"
                  @edited-change="(value: boolean) => (settingsSecretEdited[settingSecretEditKey(field)] = value)"
                  @reveal-error="(error: unknown) => MessagePlugin.error(errorMessage(error, '读取敏感配置失败'))"
                />
                <t-input
                  v-else
                  v-model="form[field.key]"
                  :type="field.type === 'password' ? 'password' : 'text'"
                  :maxlength="field.maxlength"
                  :placeholder="field.placeholder || `请输入${field.label}`"
                />
              </div>
            </article>
          </div>
        </t-form>
      </t-card>

      <div v-if="allFields.length > 0" class="settings-bottom-actions">
        <t-button theme="primary" :loading="currentSaving" :disabled="!canManageCurrentTab" @click="saveCurrentTab">
          <template #icon><check-icon /></template>
          保存设置
        </t-button>
      </div>
    </template>

    <template v-else>
      <t-card :bordered="false" :loading="heroLoading" class="slides-config-card">
        <t-alert v-if="heroDirty" theme="warning" message="当前存在未保存修改。保存后官网首页最长约 2 分钟同步。" />

        <section class="slides-section">
          <div class="slides-section-head">
            <div>
              <h2>轮播项（{{ heroForm.slides.length }} / {{ maxSlides }}）</h2>
              <p>每个轮播项包含导航名称、主标题、描述和两个按钮。</p>
            </div>
            <t-space>
              <t-button variant="outline" @click="resetSlidesToDefault">恢复默认</t-button>
              <t-button theme="primary" :disabled="heroForm.slides.length >= maxSlides" @click="addSlide">
                <template #icon><add-icon /></template>
                新增轮播
              </t-button>
            </t-space>
          </div>

          <article v-for="(slide, index) in heroForm.slides" :key="`slide-${index}`" class="slide-card">
            <div class="slide-card-head">
              <span class="slide-card-head__index">{{ index + 1 }}</span>
              <strong class="slide-card-head__name">{{ slide.rail_title || '未命名轮播' }}</strong>
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
              <div class="slide-row">
                <label class="slide-label">标题</label>
                <t-input v-model="slide.rail_title" maxlength="20" placeholder="导航名" class="slide-field--sm" />
                <t-input
                  v-model="slide.title"
                  maxlength="80"
                  placeholder="主标题，例如：官网焕新 · 云上新体验"
                  class="slide-field--lg"
                />
              </div>

              <div class="slide-row">
                <label class="slide-label">按钮</label>
                <div class="slide-btn-group">
                  <span class="slide-btn-group__tag">主</span>
                  <t-input v-model="slide.primary_text" maxlength="20" placeholder="按钮文案" class="slide-field--sm" />
                  <t-input
                    v-model="slide.primary_path"
                    maxlength="255"
                    placeholder="跳转路径"
                    class="slide-field--lg"
                  />
                </div>
                <div class="slide-btn-group">
                  <span class="slide-btn-group__tag">次</span>
                  <t-input
                    v-model="slide.secondary_text"
                    maxlength="20"
                    placeholder="按钮文案"
                    class="slide-field--sm"
                  />
                  <t-input
                    v-model="slide.secondary_path"
                    maxlength="255"
                    placeholder="跳转路径"
                    class="slide-field--lg"
                  />
                </div>
              </div>

              <div class="slide-row">
                <label class="slide-label">描述</label>
                <t-textarea
                  v-model="slide.desc"
                  :autosize="{ minRows: 2, maxRows: 4 }"
                  maxlength="300"
                  placeholder="轮播描述文案"
                  class="slide-field--full"
                />
              </div>

              <div class="slide-row">
                <label class="slide-label">视频</label>
                <div class="slide-video-selector" @click="openVideoDrawer(index)">
                  <video-icon />
                  <span v-if="slide.video" class="slide-video-selector__name">{{ videoDisplayName(slide.video) }}</span>
                  <span v-else class="slide-video-selector__placeholder">点击选择背景视频</span>
                  <chevron-right-icon />
                </div>
              </div>
            </div>
          </article>
        </section>

        <section class="slides-section">
          <div class="slides-section-head">
            <div>
              <h2>底部特色卡片（{{ heroForm.features.length }} / {{ maxFeatures }}）</h2>
              <p>用于官网首页轮播下方的横向卡片组。</p>
            </div>
            <t-space>
              <t-button variant="outline" @click="resetFeaturesToDefault">恢复默认</t-button>
              <t-button theme="primary" :disabled="heroForm.features.length >= maxFeatures" @click="addFeature">
                <template #icon><add-icon /></template>
                新增卡片
              </t-button>
            </t-space>
          </div>

          <div class="feature-list">
            <article v-for="(feature, index) in heroForm.features" :key="`feature-${index}`" class="feature-card">
              <div class="feature-card-sort">
                <t-button variant="text" :disabled="index === 0" @click="moveFeature(index, -1)">
                  <template #icon><arrow-up-icon /></template>
                </t-button>
                <t-button
                  variant="text"
                  :disabled="index === heroForm.features.length - 1"
                  @click="moveFeature(index, 1)"
                >
                  <template #icon><arrow-down-icon /></template>
                </t-button>
              </div>
              <t-input v-model="feature.kicker" maxlength="20" placeholder="标签 kicker" />
              <t-input v-model="feature.title" maxlength="50" placeholder="标题" />
              <t-textarea
                v-model="feature.desc"
                :autosize="{ minRows: 2, maxRows: 3 }"
                maxlength="120"
                placeholder="描述"
              />
              <t-input v-model="feature.path" maxlength="255" placeholder="跳转路径，可选" />
              <t-button
                theme="danger"
                variant="text"
                :disabled="heroForm.features.length <= 1"
                @click="removeFeature(index)"
              >
                <template #icon><delete-icon /></template>
                删除
              </t-button>
            </article>
          </div>
        </section>
      </t-card>

      <div class="settings-bottom-actions">
        <t-button theme="primary" :loading="currentSaving" :disabled="!canManageCurrentTab" @click="saveCurrentTab">
          <template #icon><check-icon /></template>
          保存设置
        </t-button>
      </div>
    </template>

    <input ref="fileInputRef" class="hidden-file-input" type="file" accept="image/*" @change="handleImageFileChange" />
    <input
      ref="mediaDrawerUploadRef"
      class="hidden-file-input"
      type="file"
      accept="image/jpeg,image/png,image/webp,video/mp4,video/webm"
      @change="handleMediaDrawerUpload"
    />

    <t-drawer
      :visible="mediaDrawerVisible"
      header="选择媒体"
      size="520px"
      placement="right"
      :footer="null"
      @close="closeMediaDrawer"
    >
      <div class="cover-drawer-toolbar">
        <t-select v-model="mediaDrawerType" placeholder="全部类型" @change="loadMediaDrawerList">
          <t-option v-for="item in mediaDrawerTypeOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
        <t-button variant="outline" @click="openMediaDrawerUpload">
          <template #icon><upload-icon /></template>
          上传新文件
        </t-button>
      </div>
      <div class="cover-drawer-grid">
        <div
          v-for="item in mediaDrawerList"
          :key="item.url"
          class="cover-drawer-card"
          :class="{ 'is-selected': pendingImageField && String(form[pendingImageField.key]) === item.url }"
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
            <check-circle-filled-icon
              v-if="pendingImageField && String(form[pendingImageField.key]) === item.url"
              class="cover-drawer-card__check"
            />
            <span>{{ item.filename }}</span>
          </div>
        </div>
        <div v-if="!mediaDrawerList.length && !mediaDrawerLoading" class="cover-drawer-empty">
          暂无已上传媒体，请先上传
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
        <t-radio-button value="select">选择已有视频</t-radio-button>
        <t-radio-button value="url">输入视频 URL</t-radio-button>
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
        <div v-if="!heroVideoOptions.length" class="video-drawer-empty">后端 uploads/hero-videos 目录暂无视频</div>
      </div>

      <div v-else class="video-drawer-url-mode">
        <p class="video-drawer-url-mode__hint">输入第三方视频 URL，支持 mp4/webm 格式。</p>
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
            确认使用该 URL
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
import { hasAdminPermission } from '@/utils/permission';
import { errorMessage } from '@/utils/userMessage';

type SettingsTab = 'referral' | 'automation' | 'log_archive' | 'site_basic' | 'site_hero';
type FieldType = 'input' | 'password' | 'textarea' | 'switch' | 'select' | 'number' | 'image' | 'time';
type FieldValue = string | number | boolean | null;

interface FieldOption {
  label: string;
  value: string | number | boolean;
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
  requiredWhen?: (model: Record<string, FieldValue>) => boolean;
  pattern?: RegExp;
  patternMessage?: string;
  preview?: 'image';
  maxlength?: number;
  placeholder?: string;
}

interface SettingSection {
  title: string;
  description?: string;
  fields: SettingField[];
  anchor?: string;
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
const activeSection = ref('');
const form = reactive<Record<string, FieldValue>>({});
const settingsGroupCache = reactive<Record<string, Record<string, unknown>>>({});
const settingsMetaCache = reactive<Record<string, Record<string, SettingItem>>>({});
const settingsSecretEdited = reactive<Record<string, boolean>>({});
const fileInputRef = ref<HTMLInputElement>();
const pendingImageField = ref<SettingField | null>(null);
const mediaDrawerVisible = ref(false);
const mediaDrawerLoading = ref(false);
const mediaDrawerList = ref<Array<{ url: string; filename: string; isVideo: boolean }>>([]);
const mediaDrawerType = ref('');
const mediaDrawerTypeOptions = [
  { label: '全部类型', value: '' },
  { label: '图片', value: 'image' },
  { label: '视频', value: 'video' },
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
  { label: '每 15 分钟', value: 'every_fifteen_minutes' },
  { label: '每 30 分钟', value: 'every_thirty_minutes' },
  { label: '每小时', value: 'hourly' },
  { label: '每天', value: 'daily' },
];

const tabGroups: Array<{ group: string; label: string; tabs: Array<{ label: string; value: SettingsTab }> }> = [
  {
    group: 'config',
    label: '基础配置',
    tabs: [
      { label: '推荐奖励', value: 'referral' },
      { label: '自动化策略', value: 'automation' },
      { label: '日志归档', value: 'log_archive' },
    ],
  },
  {
    group: 'site',
    label: '站点内容',
    tabs: [
      { label: '基础信息', value: 'site_basic' },
      { label: '首页 Banner', value: 'site_hero' },
    ],
  },
];
const tabOptions = tabGroups.flatMap((g) => g.tabs);

const configs: Record<Exclude<SettingsTab, 'site_hero'>, SettingsConfig> = {
  referral: {
    group: 'referral',
    title: '推荐奖励配置',
    description: '集中控制推荐返利开关、默认比例、冻结期和最低提现门槛。',
    sections: [
      {
        title: '奖励规则',
        fields: [
          { key: 'enabled', label: '启用推荐返利', type: 'switch', default: true },
          { key: 'reward_rate', label: '默认奖励比例（%）', type: 'number', default: 10, min: 0, max: 100 },
          { key: 'reward_freeze_days', label: '奖励冻结期（天）', type: 'number', default: 4, min: 0, max: 365 },
          { key: 'withdraw_min_amount', label: '最低提现金额（元）', type: 'number', default: 20, min: 0 },
        ],
      },
    ],
  },
  automation: {
    group: 'automation',
    title: '自动化策略配置',
    description: '配置服务生命周期、账单提醒、工单关闭和未支付账单清理策略。',
    sections: [
      {
        title: '服务生命周期',
        fields: [
          { key: 'expire_suspend_enabled', label: '启用到期自动暂停', type: 'switch', default: true },
          { key: 'expire_suspend_after_days', label: '到期后暂停天数', type: 'number', default: 0, min: 0, max: 365 },
          { key: 'expire_suspend_notify_enabled', label: '暂停前发送通知', type: 'switch', default: true },
          { key: 'expire_unsuspend_notify_enabled', label: '恢复后发送通知', type: 'switch', default: true },
          { key: 'expire_terminate_enabled', label: '启用自动终止', type: 'switch', default: false },
          { key: 'expire_terminate_after_days', label: '暂停后终止天数', type: 'number', default: 7, min: 1, max: 365 },
          {
            key: 'service_lifecycle_schedule_mode',
            label: '任务执行周期',
            type: 'select',
            default: 'every_fifteen_minutes',
            options: automationScheduleModeOptions,
          },
          {
            key: 'service_lifecycle_schedule_time',
            label: '执行时间',
            type: 'time',
            default: '00:00:00',
            placeholder: '分钟仅支持 00/15/30/45',
            pattern: /^\d{2}:(00|15|30|45):00$/,
            patternMessage: '分钟仅支持 00、15、30 或 45，秒必须为 00',
          },
        ],
      },
      {
        title: '账单与提醒',
        fields: [
          { key: 'renew_notice_enabled', label: '启用续费提醒', type: 'switch', default: true },
          { key: 'renew_create_invoice_enabled', label: '自动创建续费账单', type: 'switch', default: true },
          { key: 'invoice_unpaid_reminder_enabled', label: '启用未支付提醒', type: 'switch', default: true },
          {
            key: 'invoice_unpaid_before_due_days',
            label: '到期前提醒天数',
            type: 'number',
            default: 1,
            min: 0,
            max: 30,
          },
          {
            key: 'invoice_overdue_reminder_days',
            label: '逾期提醒天数',
            type: 'input',
            default: '1,3,5',
            wide: true,
            pattern: /^\d+(,\d+)*$/,
            patternMessage: '请使用英文逗号分隔天数，例如 1,3,5',
          },
          { key: 'invoice_overdue_after_days', label: '逾期标记天数', type: 'number', default: 0, min: 0, max: 365 },
          {
            key: 'billing_maintenance_schedule_mode',
            label: '任务执行周期',
            type: 'select',
            default: 'hourly',
            options: automationScheduleModeOptions,
          },
          {
            key: 'billing_maintenance_schedule_time',
            label: '执行时间',
            type: 'time',
            default: '00:00:00',
            placeholder: '分钟仅支持 00/15/30/45',
            pattern: /^\d{2}:(00|15|30|45):00$/,
            patternMessage: '分钟仅支持 00、15、30 或 45，秒必须为 00',
          },
        ],
      },
      {
        title: '工单与待支付清理',
        fields: [
          { key: 'ticket_auto_close_enabled', label: '启用工单自动关闭', type: 'switch', default: true },
          {
            key: 'ticket_auto_close_after_hours',
            label: '工单自动关闭时长（小时）',
            type: 'number',
            default: 48,
            min: 1,
            max: 720,
          },
          { key: 'pending_order_cleanup_enabled', label: '启用未支付账单清理', type: 'switch', default: true },
          {
            key: 'pending_order_cleanup_after_hours',
            label: '未支付账单保留时长（小时）',
            type: 'number',
            default: 1,
            min: 1,
            max: 720,
          },
          { key: 'pending_recharge_cleanup_enabled', label: '启用未支付充值单清理', type: 'switch', default: true },
          {
            key: 'pending_recharge_cleanup_after_days',
            label: '未支付充值单保留天数',
            type: 'number',
            default: 3,
            min: 0,
            max: 365,
          },
          {
            key: 'ticket_auto_close_schedule_mode',
            label: '工单任务执行周期',
            type: 'select',
            default: 'hourly',
            options: automationScheduleModeOptions,
          },
          {
            key: 'ticket_auto_close_schedule_time',
            label: '工单执行时间',
            type: 'time',
            default: '00:00:00',
            placeholder: '分钟仅支持 00/15/30/45',
            pattern: /^\d{2}:(00|15|30|45):00$/,
            patternMessage: '分钟仅支持 00、15、30 或 45，秒必须为 00',
          },
          {
            key: 'order_cleanup_schedule_mode',
            label: '账单清理执行周期',
            type: 'select',
            default: 'every_fifteen_minutes',
            options: automationScheduleModeOptions,
          },
          {
            key: 'order_cleanup_schedule_time',
            label: '账单清理执行时间',
            type: 'time',
            default: '00:00:00',
            placeholder: '分钟仅支持 00/15/30/45',
            pattern: /^\d{2}:(00|15|30|45):00$/,
            patternMessage: '分钟仅支持 00、15、30 或 45，秒必须为 00',
          },
        ],
      },
    ],
  },
  log_archive: {
    group: 'log_archive',
    title: '日志归档设置',
    description: '配置 pt-archiver 的运行参数与日志保留策略。',
    sections: [
      {
        title: '运行参数',
        fields: [
          {
            key: 'pt_archiver_binary',
            label: 'pt-archiver 二进制路径',
            type: 'input',
            default: '/usr/bin/pt-archiver',
            required: true,
            maxlength: 255,
          },
          {
            key: 'pt_archiver_defaults_file',
            label: '默认配置文件',
            type: 'input',
            default: '/etc/turaidc/pt-archiver.cnf',
            required: true,
            maxlength: 255,
          },
          { key: 'concurrency', label: '最大并发数', type: 'number', default: 2, min: 1, max: 8 },
          { key: 'batch_size', label: '每批处理行数', type: 'number', default: 1000, min: 100, max: 10000 },
          { key: 'sleep_seconds', label: '批次间隔（秒）', type: 'number', default: 1, min: 0, max: 60 },
        ],
      },
      {
        title: '保留策略',
        fields: [
          { key: 'retention_days', label: '日志保留天数', type: 'number', default: 30, min: 1, max: 3650 },
          { key: 'file_retention_days', label: '归档文件保留天数', type: 'number', default: 180, min: 1, max: 3650 },
        ],
      },
    ],
  },
  site_basic: {
    group: 'basic',
    title: '基础信息',
    description: '维护站点名称、Logo、Favicon、官方联系方式与备案号。',
    sections: [
      {
        title: '站点信息',
        fields: [
          {
            key: 'site_name',
            label: '站点名称',
            type: 'input',
            default: '',
            maxlength: 50,
            placeholder: '例如：图拉云',
          },
          {
            key: 'browser_title',
            label: '浏览器标题',
            type: 'input',
            default: '',
            maxlength: 80,
            placeholder: '留空则默认使用站点名称',
          },
          {
            key: 'site_logo',
            label: '站点 Logo',
            type: 'image',
            default: '',
            maxlength: 255,
            placeholder: '/branding/logo.svg',
          },
          {
            key: 'site_favicon',
            label: '站点 Favicon',
            type: 'image',
            default: '',
            maxlength: 255,
            placeholder: '/branding/logo1.svg',
          },
          {
            key: 'client_console_icon',
            label: '用户控制台图标',
            type: 'image',
            default: '',
            maxlength: 255,
            placeholder: '/branding/logo1.svg',
            help: '用于用户控制台侧边栏与登录页 Logo，留空则使用站点 Favicon。',
          },
          { key: 'service_phone', label: '官方QQ群', type: 'input', default: '', maxlength: 40 },
          { key: 'support_group_qr', label: '官方群聊二维码', type: 'image', default: '', maxlength: 255 },
          { key: 'support_group_link', label: '入群链接', type: 'input', default: '', maxlength: 255 },
          { key: 'terms_url', label: '服务条款链接', type: 'input', default: '', maxlength: 255 },
          { key: 'privacy_url', label: '隐私政策链接', type: 'input', default: '', maxlength: 255 },
        ],
      },
      {
        title: '通知总开关',
        description: '邮件与短信的全局开关；关闭后对应渠道的验证码与业务通知不再发送。',
        fields: [
          {
            key: 'email_enabled',
            label: '启用邮件通知',
            type: 'switch',
            group: 'notification',
            default: false,
            help: '关闭后系统邮件（邮箱验证码、服务/账单等通知）不再发送。',
          },
          {
            key: 'sms_enabled',
            label: '启用短信通知',
            type: 'switch',
            group: 'notification',
            default: false,
            help: '关闭后系统短信（短信验证码等）不再发送。',
          },
        ],
      },
    ],
  },
};

const pageConfig = computed<SettingsConfig | null>(() =>
  activeTab.value === 'site_hero' ? null : configs[activeTab.value],
);
const sections = computed(() =>
  (pageConfig.value?.sections || []).map((section, index) => ({ ...section, anchor: `${activeTab.value}-${index}` })),
);
const currentSection = computed(
  () => sections.value.find((section) => section.anchor === activeSection.value) || sections.value[0],
);
const allFields = computed(() => sections.value.flatMap((section) => section.fields));
const activeGroups = computed(() =>
  Array.from(new Set(allFields.value.map((field) => field.group || pageConfig.value?.group || 'system'))),
);
const currentSaving = computed(() => (activeTab.value === 'site_hero' ? heroSaving.value : settingsSaving.value));
const heroDirty = computed(() => JSON.stringify(heroForm) !== heroSnapshot.value);

const videoDrawerTitle = computed(() => {
  if (videoDrawerSlideIndex.value < 0) return '选择背景视频';
  const slide = heroForm.slides[videoDrawerSlideIndex.value];
  return `背景视频 · 第 ${videoDrawerSlideIndex.value + 1} 项${slide?.rail_title ? `（${slide.rail_title}）` : ''}`;
});

function normalizeTab(value: unknown): SettingsTab {
  const tab = Array.isArray(value) ? value[0] : value;
  if (tab === 'site') return 'site_basic';
  return tabOptions.some((item) => item.value === tab) ? (tab as SettingsTab) : 'automation';
}

function resolveInitialTab(): SettingsTab {
  const metaTab = route.meta.settingsTab;
  if (typeof metaTab === 'string' && tabOptions.some((item) => item.value === metaTab)) {
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
    if (typeof newTab === 'string' && tabOptions.some((item) => item.value === newTab)) {
      activeTab.value = newTab as SettingsTab;
      refreshCurrentTab();
    }
  },
);

function saveCurrentTab() {
  if (!canManageCurrentTab.value) {
    MessagePlugin.warning('当前账号无保存权限');
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
        activeGroups.value.map(
          async (group): Promise<[string, Record<string, unknown>]> => [group, await loadSettingsGroup(group)],
        ),
      ),
    );
    allFields.value.forEach((field) => {
      const group = field.group || pageConfig.value?.group || 'system';
      form[field.key] = parseFieldValue(field, maps[group]?.[field.key]);
    });
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载设置失败'));
  } finally {
    settingsLoading.value = false;
  }
}

async function saveSettings() {
  if (!canManageSettings.value) {
    MessagePlugin.warning('当前账号无系统配置管理权限');
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
    MessagePlugin.success(`${pageConfig.value?.title || '设置'}已保存`);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存设置失败'));
  } finally {
    settingsSaving.value = false;
  }
}

function validateSettings() {
  for (const field of allFields.value) {
    const value = form[field.key];
    if (isFieldRequired(field) && !String(value ?? '').trim() && field.type !== 'switch') {
      MessagePlugin.warning(`请填写${field.label}`);
      return false;
    }
    if (field.type === 'number') {
      const num = Number(value);
      if (!Number.isFinite(num)) {
        MessagePlugin.warning(`${field.label}必须是数字`);
        return false;
      }
      if (field.min !== undefined && num < field.min) {
        MessagePlugin.warning(`${field.label}不能小于 ${field.min}`);
        return false;
      }
      if (field.max !== undefined && num > field.max) {
        MessagePlugin.warning(`${field.label}不能大于 ${field.max}`);
        return false;
      }
    }
    if (field.pattern && !field.pattern.test(String(value ?? '').trim())) {
      MessagePlugin.warning(field.patternMessage || `${field.label}格式不正确`);
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
  if (field.type === 'number') {
    const parsed = Number(raw);
    return Number.isFinite(parsed) ? parsed : Number(field.default || 0);
  }
  return String(raw);
}

function isFieldRequired(field: SettingField) {
  return typeof field.requiredWhen === 'function' ? field.requiredWhen(form) : Boolean(field.required);
}

function selectImage(field: SettingField) {
  pendingImageField.value = field;
  openMediaDrawer();
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
  const field = pendingImageField.value;
  if (!field) return;
  form[field.key] = item.url;
  MessagePlugin.success('已选择');
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
    const field = pendingImageField.value;
    if (field) form[field.key] = url;
    MessagePlugin.success('上传成功');
  } catch (error) {
    const record = error as Record<string, unknown>;
    MessagePlugin.error(String(record.message || '上传失败'));
  }
}

async function handleImageFileChange(event: Event) {
  const field = pendingImageField.value;
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!field || !file) return;
  const data = new FormData();
  data.append('file', file);
  data.append('group', 'site-settings');
  try {
    const response = await adminApi.media.upload(data);
    form[field.key] = String(response.url || '');
    MessagePlugin.success('图片上传成功');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '图片上传失败'));
  }
}

async function loadHero() {
  heroLoading.value = true;
  try {
    const data = await adminApi.siteHero.get();
    applyHeroPayload(data);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载首页 Banner 失败'));
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
    MessagePlugin.warning('当前账号无站点管理权限');
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
    MessagePlugin.success('首页 Banner 已保存');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存首页 Banner 失败'));
  } finally {
    heroSaving.value = false;
  }
}

function validateHero() {
  for (const [index, slide] of heroForm.slides.entries()) {
    for (const [key, label] of [
      ['rail_title', '导航名称'],
      ['title', '主标题'],
      ['desc', '描述'],
      ['primary_text', '主按钮文案'],
      ['primary_path', '主按钮跳转'],
      ['secondary_text', '次按钮文案'],
      ['secondary_path', '次按钮跳转'],
    ] as Array<[keyof HomeHeroSlide, string]>) {
      if (!String(slide[key] ?? '').trim()) {
        MessagePlugin.warning(`第 ${index + 1} 个轮播项的${label}不能为空`);
        return false;
      }
    }
  }
  for (const [index, feature] of heroForm.features.entries()) {
    for (const [key, label] of [
      ['kicker', '标签'],
      ['title', '标题'],
      ['desc', '描述'],
    ] as Array<[keyof HomeHeroFeature, string]>) {
      if (!String(feature[key] ?? '').trim()) {
        MessagePlugin.warning(`第 ${index + 1} 张卡片的${label}不能为空`);
        return false;
      }
    }
  }
  return true;
}

function buildBlankSlide(): HomeHeroSlide {
  return {
    rail_title: '新轮播项',
    title: '',
    desc: '',
    primary_text: '立即体验',
    primary_path: '/products',
    secondary_text: '查看详情',
    secondary_path: '/about',
    video: heroVideoOptions.value[0]?.value || '',
  };
}

function buildBlankFeature(): HomeHeroFeature {
  return { kicker: '新卡片', title: '', desc: '', path: '' };
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
    header: '恢复默认轮播',
    body: '恢复默认会覆盖当前轮播项，下次保存才会写入数据库。',
    confirmBtn: '恢复默认',
    cancelBtn: '取消',
    onConfirm: () => {
      heroForm.slides = cloneList(heroDefaults.slides);
      dialog.destroy();
    },
  });
}

function resetFeaturesToDefault() {
  const dialog = DialogPlugin.confirm({
    header: '恢复默认卡片',
    body: '恢复默认会覆盖当前特色卡片，下次保存才会写入数据库。',
    confirmBtn: '恢复默认',
    cancelBtn: '取消',
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
    MessagePlugin.warning('请输入视频 URL');
    return;
  }
  if (!/^https?:\/\/.+/.test(url)) {
    MessagePlugin.warning('请输入以 http:// 或 https:// 开头的有效 URL');
    return;
  }
  const idx = videoDrawerSlideIndex.value;
  if (idx >= 0 && idx < heroForm.slides.length) {
    heroForm.slides[idx].video = url;
  }
  MessagePlugin.success('已设置第三方视频 URL');
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
    return `外部 · ${path}`;
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

watch(
  sections,
  (next) => {
    activeSection.value = next[0]?.anchor || '';
  },
  { immediate: true },
);

onMounted(() => {
  refreshCurrentTab();
});
</script>
