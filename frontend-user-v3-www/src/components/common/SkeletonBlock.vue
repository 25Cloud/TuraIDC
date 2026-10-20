<template>
  <span
    class="tura-skeleton"
    :class="tone === 'inverse' ? 'tura-skeleton--inverse' : ''"
    :style="blockStyle"
    aria-hidden="true"
  />
</template>

<script setup>
/**
 * 通用骨架块：全站骨架都由它拼出来，保证只有一种底色与扫光节奏
 * （底色/扫光在 src/components/common/skeleton.scss 中统一维护）。
 *
 * 用法：
 *   <SkeletonBlock :width="120" :height="14" />          文本行
 *   <SkeletonBlock :width="'62%'" :height="24" />        标题行
 *   <SkeletonBlock :width="34" :height="34" circle />     头像
 *   <SkeletonBlock tone="inverse" :height="72" />         主题色底上的占位
 *
 * 尺寸单位：数字按 px 处理，字符串原样透传（支持 %、min()、vw 等）。
 * 默认 100% × 14px × 6px 圆角，接近正文行高，替换真实文本时不跳动。
 */
import { computed } from "vue";

const props = defineProps({
  width: {
    type: [String, Number],
    default: "100%",
  },
  height: {
    type: [String, Number],
    default: 14,
  },
  radius: {
    type: [String, Number],
    default: 6,
  },
  // 圆形占位（头像、图标底）
  circle: {
    type: Boolean,
    default: false,
  },
  // base：浅色底；inverse：主题色/深色底上的白色系占位
  tone: {
    type: String,
    default: "base",
  },
});

const toSize = (value) => (typeof value === "number" ? `${value}px` : value);

const blockStyle = computed(() => ({
  width: toSize(props.width),
  height: toSize(props.height),
  borderRadius: props.circle ? "50%" : toSize(props.radius),
}));
</script>
