<template>
  <div
    ref="listEl"
    class="ai-messages"
    role="region"
    :aria-label="$t('ai.panel.recentConversations')"
    @scroll="handleScroll"
  >
    <div v-if="loading" class="ai-messages__loading">
      <el-skeleton :rows="3" animated />
    </div>

    <div v-else-if="!messages.length" class="ai-messages__empty">
      <span class="ai-messages__empty-icon" aria-hidden="true">
        <el-icon><Service /></el-icon>
      </span>
      <p class="ai-messages__empty-text">{{ $t("ai.panel.empty") }}</p>
    </div>

    <article
      v-for="message in messages"
      :key="message.id"
      class="ai-msg"
      :class="`ai-msg--${message.role}`"
    >
      <!-- 用户消息：右侧气泡，保留用户自己敲的换行 -->
      <p v-if="message.role === 'user'" class="ai-msg__user">{{ message.content }}</p>

      <!-- 系统消息（执行结果 / 待确认动作）：居中细条 + 确认卡 -->
      <template v-else-if="message.role === 'system'">
        <p v-if="message.content" class="ai-msg__system">{{ message.content }}</p>
        <AiGuideConfirmCard
          v-for="action in message.pending_actions || []"
          :key="`${message.id}-action-${action.id}`"
          :action="action"
          :confirm-handler="confirmHandler"
          :reject-handler="rejectHandler"
        />
      </template>

      <!-- 助手消息：左侧圆形头像 + 工具胶囊 / 思考 / 正文 -->
      <template v-else>
        <span class="ai-msg__avatar" aria-hidden="true">
          <el-icon><Service /></el-icon>
        </span>

        <div class="ai-msg__body">
          <!-- 工具调用：一行小胶囊，不占正文空间 -->
          <div v-if="message.tool_runs?.length" class="ai-msg__tools">
            <span
              v-for="(run, index) in message.tool_runs"
              :key="`${message.id}-tool-${run.name}-${index}`"
              class="ai-msg__tool"
              :class="`ai-msg__tool--${run.status}`"
              :title="run.summary || ''"
            >
              <code class="ai-msg__tool-name">{{ run.name }}</code>
              <span class="ai-msg__tool-sep" aria-hidden="true">·</span>
              <span class="ai-msg__tool-status">{{ toolStatusLabel(run.status) }}</span>
            </span>
          </div>

          <!-- 首个增量到达前：三点呼吸占位，避免面板看起来「死住」 -->
          <div v-if="message.started === false && !message.error" class="ai-thinking">
            <span class="ai-thinking__dot" />
            <span class="ai-thinking__dot" />
            <span class="ai-thinking__dot" />
            <span class="ai-thinking__text">{{ $t("ai.thinking") }}</span>
          </div>

          <!-- 思考过程：流式期间自动展开 + 标题 shimmer，结束后收起成一行摘要 -->
          <section
            v-if="message.reasoning"
            class="ai-reason"
            :class="{ 'ai-reason--live': message.streaming }"
          >
            <button
              type="button"
              class="ai-reason__head"
              :aria-expanded="isReasoningOpen(message) ? 'true' : 'false'"
              @click="toggleReasoning(message.id)"
            >
              <el-icon class="ai-reason__chevron" :class="{ 'ai-reason__chevron--open': isReasoningOpen(message) }">
                <ArrowDown />
              </el-icon>
              <span class="ai-reason__title">
                {{ message.streaming ? $t("ai.thinking") : $t("ai.reasoning.done") }}
              </span>
              <span class="ai-reason__meta">{{ reasoningChars(message.reasoning) }}</span>
            </button>
            <div v-show="isReasoningOpen(message)" class="ai-reason__body">{{ message.reasoning }}</div>
          </section>

          <p v-if="message.error" class="ai-msg__error">
            {{ message.content || $t("ai.error.fallback") }}
          </p>

          <!--
            正文：流式期间按纯文本渲染（增量已在共享层按 16ms 合并，仍不必每帧重排 markdown），
            结束后再切 markdown-it 结果；两者都做了换行与断行保护。
          -->
          <div v-if="message.content && !message.error" class="ai-msg__content">
            <!-- 单行书写：pre-wrap 下模板换行会变成真实空格，正文开头会莫名缩进 -->
            <p v-if="message.streaming" class="ai-msg__plain">{{ message.content }}<span class="ai-msg__caret" aria-hidden="true" /></p>
            <!-- markdown-it 已关闭原始 HTML（html: false），产物是转义后的安全结构 -->
            <!-- eslint-disable-next-line vue/no-v-html -->
            <div v-else class="ai-msg__markdown" v-html="renderMarkdown(message.content)"></div>
          </div>

          <AiGuideConfirmCard
            v-for="action in message.pending_actions || []"
            :key="`${message.id}-action-${action.id}`"
            :action="action"
            :confirm-handler="confirmHandler"
            :reject-handler="rejectHandler"
          />
        </div>
      </template>
    </article>
  </div>
</template>

<script setup>
import { ArrowDown, Service } from "@element-plus/icons-vue";
import MarkdownIt from "markdown-it";
import { nextTick, onMounted, ref, watch } from "vue";

import { t } from "@/locales";

import AiGuideConfirmCard from "./AiGuideConfirmCard.vue";

const props = defineProps({
  messages: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
  /** chat.confirmAction / chat.rejectAction 原样下传，确认卡自己管忙碌态 */
  confirmHandler: {
    type: Function,
    required: true,
  },
  rejectHandler: {
    type: Function,
    required: true,
  },
});

// html: false —— 模型输出里的原始 HTML 一律当文本，杜绝 v-html 变成注入入口
const markdown = new MarkdownIt({ html: false, breaks: true, linkify: true });

// 外链统一新窗口打开，并切断 opener 引用，避免把当前页带跑
markdown.renderer.rules.link_open = (tokens, index, options, _env, self) => {
  tokens[index].attrSet("target", "_blank");
  tokens[index].attrSet("rel", "noopener noreferrer nofollow");
  return self.renderToken(tokens, index, options);
};

function renderMarkdown(content) {
  return markdown.render(String(content || ""));
}

const TOOL_STATUS_KEYS = {
  done: "ai.tool.status.done",
  pending: "ai.tool.status.pending",
  rejected: "ai.tool.status.rejected",
  failed: "ai.tool.status.failed",
};

function toolStatusLabel(status) {
  return t(TOOL_STATUS_KEYS[status] || "ai.tool.status.done");
}

// 用户手动点开的思考块（按消息 id 记）；流式中的消息默认展开
const openReasoning = ref({});

function isReasoningOpen(message) {
  if (message.streaming) {
    return openReasoning.value[message.id] !== false;
  }

  return openReasoning.value[message.id] === true;
}

function toggleReasoning(id) {
  openReasoning.value = { ...openReasoning.value, [id]: !openReasoning.value[id] };
}

function reasoningChars(text) {
  const length = (text || "").length;
  const count = length >= 1000 ? `${(length / 1000).toFixed(1)}k` : String(length);

  return t("ai.reasoning.chars", { count });
}

const listEl = ref(null);
// 用户往上翻历史时不要把他拽回底部；只有贴着底部才跟随最新内容
const stickToBottom = ref(true);

function handleScroll() {
  const el = listEl.value;
  if (!el) {
    return;
  }

  stickToBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
}

function scrollToBottom() {
  const el = listEl.value;
  if (el) {
    el.scrollTop = el.scrollHeight;
  }
}

onMounted(() => {
  void nextTick(scrollToBottom);
});

// 新消息进来先回到跟随态：用户刚发完话，视线应该在底部
watch(
  () => props.messages.length,
  () => {
    stickToBottom.value = true;
    void nextTick(scrollToBottom);
  },
);

// 流式追加：正文与思考都可能长高，两者都盯（增量已由共享层按帧合并）
watch(
  () => {
    const last = props.messages[props.messages.length - 1];
    return [last?.content, last?.reasoning, last?.started];
  },
  () => {
    if (!stickToBottom.value) {
      return;
    }

    void nextTick(scrollToBottom);
  },
);
</script>

<style scoped lang="scss">
.ai-messages {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 12px;
  min-height: 0;
  padding: 16px;
  overflow-y: auto;
  background: var(--el-fill-color-light);
  overscroll-behavior: contain;
  /* 不启用 scroll-behavior: smooth：流式每个增量都会重设 scrollTop，动画会一直被打断 */
}

.ai-messages__loading {
  padding: 0;
}

/* ===== 空态 ===== */
.ai-messages__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  margin: auto 0;
  text-align: center;
}

.ai-messages__empty-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  font-size: 20px;
  color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
  border-radius: 50%;
}

.ai-messages__empty-text {
  max-width: 280px;
  margin: 0;
  font-size: 13px;
  line-height: 1.7;
  color: var(--el-text-color-placeholder);
}

/* ===== 消息骨架 ===== */
.ai-msg {
  display: flex;
  gap: 8px;
  min-width: 0;
}

.ai-msg--user {
  justify-content: flex-end;
}

.ai-msg--assistant {
  align-items: flex-start;
}

.ai-msg--system {
  flex-direction: column;
  align-items: stretch;
  gap: 8px;
}

.ai-msg__avatar {
  display: inline-flex;
  flex: none;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  margin-top: 2px;
  font-size: 15px;
  color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
  border-radius: 50%;
}

.ai-msg__body {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
}

.ai-msg__user {
  max-width: 86%;
  padding: 8px 12px;
  margin: 0;
  font-size: 13px;
  line-height: 1.7;
  color: var(--el-text-color-primary);
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  background: var(--el-color-primary-light-9);
  border: 1px solid var(--el-color-primary-light-8);
  border-radius: 8px;
}

.ai-msg__system {
  padding: 4px 12px;
  margin: 0;
  font-size: 12px;
  line-height: 1.7;
  text-align: center;
  color: var(--el-text-color-regular);
  background: var(--el-fill-color-lighter);
  border-radius: 8px;
}

.ai-msg__error {
  margin: 0;
  font-size: 13px;
  line-height: 1.7;
  color: var(--el-color-danger);
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

/* ===== 工具调用胶囊 ===== */
.ai-msg__tools {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.ai-msg__tool {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  max-width: 100%;
  padding: 2px 8px;
  font-size: 11px;
  line-height: 18px;
  color: var(--el-text-color-regular);
  background: var(--el-bg-color);
  border: 1px solid var(--el-border-color-light);
  border-radius: 8px;
}

.ai-msg__tool-name {
  max-width: 180px;
  overflow: hidden;
  font-family: ui-monospace, sfmono-regular, menlo, consolas, monospace;
  color: var(--el-text-color-primary);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-msg__tool-sep {
  color: var(--el-text-color-placeholder);
}

.ai-msg__tool--done .ai-msg__tool-status {
  color: var(--el-color-success);
}

.ai-msg__tool--pending .ai-msg__tool-status {
  color: var(--el-color-warning);
}

.ai-msg__tool--failed .ai-msg__tool-status {
  color: var(--el-color-danger);
}

.ai-msg__tool--rejected .ai-msg__tool-status {
  color: var(--el-text-color-placeholder);
}

/* ===== 等待首个增量：三点呼吸 ===== */
.ai-thinking {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 0;
  font-size: 12px;
  color: var(--el-text-color-regular);
}

.ai-thinking__dot {
  width: 6px;
  height: 6px;
  background: var(--el-color-primary);
  border-radius: 50%;
  opacity: 0.25;
  animation: ai-thinking-bounce 1.2s ease-in-out infinite;
}

.ai-thinking__dot:nth-child(2) {
  animation-delay: 0.15s;
}

.ai-thinking__dot:nth-child(3) {
  animation-delay: 0.3s;
}

.ai-thinking__text {
  margin-left: 4px;
}

@keyframes ai-thinking-bounce {
  0%,
  80%,
  100% {
    opacity: 0.25;
    transform: translateY(0);
  }

  40% {
    opacity: 1;
    transform: translateY(-2px);
  }
}

/* ===== 思考过程（可折叠） ===== */
.ai-reason {
  overflow: hidden;
  background: var(--el-bg-color);
  border: 1px solid var(--el-border-color-light);
  border-radius: 8px;
}

.ai-reason__head {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 8px 12px;
  font-size: 12px;
  color: var(--el-text-color-regular);
  text-align: left;
  cursor: pointer;
  background: transparent;
  border: 0;

  &:hover {
    color: var(--el-color-primary);
  }
}

.ai-reason__chevron {
  flex: none;
  font-size: 12px;
  transition: transform 0.15s ease;
}

.ai-reason__chevron--open {
  transform: rotate(0deg);
}

.ai-reason__chevron:not(.ai-reason__chevron--open) {
  transform: rotate(-90deg);
}

.ai-reason__title {
  color: var(--el-text-color-primary);
}

/* 流式期间标题扫光：让「还在想」这件事在视觉上动起来 */
.ai-reason--live .ai-reason__title {
  background: linear-gradient(
    90deg,
    var(--el-text-color-regular),
    var(--el-color-primary),
    var(--el-text-color-regular)
  );
  background-size: 200% 100%;
  background-clip: text;
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  animation: ai-reason-shimmer 1.6s linear infinite;
}

.ai-reason__meta {
  margin-left: auto;
  font-size: 11px;
  color: var(--el-text-color-placeholder);
  font-variant-numeric: tabular-nums;
}

.ai-reason__body {
  max-height: 200px;
  padding: 8px 12px 12px;
  overflow-y: auto;
  font-size: 12px;
  line-height: 1.7;
  color: var(--el-text-color-regular);
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  border-top: 1px dashed var(--el-border-color-light);
}

@keyframes ai-reason-shimmer {
  0% {
    background-position: 200% 0;
  }

  100% {
    background-position: -200% 0;
  }
}

/* ===== 正文 ===== */
.ai-msg__content {
  font-size: 13px;
  line-height: 1.75;
  color: var(--el-text-color-primary);
  overflow-wrap: anywhere;
}

.ai-msg__plain {
  margin: 0;
  white-space: pre-wrap;
}

.ai-msg__caret {
  display: inline-block;
  width: 2px;
  height: 1em;
  margin-left: 2px;
  vertical-align: text-bottom;
  background: var(--el-color-primary);
  border-radius: 1px;
  animation: ai-caret-blink 1s steps(2, start) infinite;
}

@keyframes ai-caret-blink {
  0%,
  100% {
    opacity: 1;
  }

  50% {
    opacity: 0;
  }
}

/* ===== markdown 排版（v-html 注入，选择器必须穿透作用域） ===== */
.ai-msg__markdown {
  color: var(--el-text-color-primary);

  :deep(p) {
    margin: 0 0 8px;
  }

  :deep(p:last-child) {
    margin-bottom: 0;
  }

  :deep(h1),
  :deep(h2),
  :deep(h3),
  :deep(h4),
  :deep(h5),
  :deep(h6) {
    margin: 16px 0 8px;
    font-weight: 600;
    line-height: 1.4;
  }

  :deep(h1) {
    font-size: 18px;
  }

  :deep(h2) {
    font-size: 16px;
  }

  :deep(h3),
  :deep(h4),
  :deep(h5),
  :deep(h6) {
    font-size: 14px;
  }

  :deep(h1:first-child),
  :deep(h2:first-child),
  :deep(h3:first-child) {
    margin-top: 0;
  }

  /* 全局把所有 ul/ol 的 list-style 清掉了，markdown 列表必须显式还原，否则只剩缩进 */
  :deep(ul),
  :deep(ol) {
    padding-left: 24px;
    margin: 0 0 8px;
    list-style-position: outside;
  }

  :deep(ul) {
    list-style-type: disc;
  }

  :deep(ol) {
    list-style-type: decimal;
  }

  :deep(li) {
    margin-bottom: 4px;
  }

  :deep(li > p) {
    margin: 0;
  }

  :deep(code) {
    padding: 2px 4px;
    font-family: ui-monospace, sfmono-regular, menlo, consolas, monospace;
    font-size: 12px;
    color: var(--el-text-color-primary);
    background: var(--el-fill-color-lighter);
    border-radius: 4px;
  }

  :deep(pre) {
    padding: 12px;
    margin: 0 0 8px;
    overflow-x: auto;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-light);
    border-radius: 8px;
  }

  :deep(pre code) {
    padding: 0;
    font-size: 12px;
    line-height: 1.6;
    background: transparent;
    border-radius: 0;
  }

  :deep(blockquote) {
    padding: 8px 12px;
    margin: 0 0 8px;
    color: var(--el-text-color-regular);
    background: var(--el-fill-color-light);
    border-left: 3px solid var(--el-border-color);
    border-radius: 8px;
  }

  :deep(blockquote > p:last-child) {
    margin-bottom: 0;
  }

  :deep(a) {
    color: var(--el-color-primary);
    text-decoration: underline;
    text-underline-offset: 2px;
  }

  :deep(table) {
    display: block;
    width: 100%;
    margin: 0 0 8px;
    overflow-x: auto;
    font-size: 12px;
    border-collapse: collapse;
  }

  :deep(th),
  :deep(td) {
    padding: 8px 12px;
    text-align: left;
    white-space: nowrap;
    border: 1px solid var(--el-border-color-light);
  }

  :deep(th) {
    font-weight: 600;
    background: var(--el-fill-color-light);
  }

  :deep(hr) {
    margin: 16px 0;
    border: 0;
    border-top: 1px solid var(--el-border-color-light);
  }

  :deep(img) {
    max-width: 100%;
    border-radius: 8px;
  }
}
</style>
