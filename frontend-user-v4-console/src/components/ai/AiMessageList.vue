<template>
  <div ref="listRef" class="ai-message-list" @scroll="handleScroll">
    <div v-if="!messages.length" class="ai-message-list__empty">
      <robot-icon class="ai-message-list__empty-icon" />
      <p class="ai-message-list__empty-title">{{ $t('ai.empty.title') }}</p>
      <p class="ai-message-list__empty-desc">{{ $t('ai.empty.description') }}</p>
    </div>

    <article
      v-for="message in messages"
      :key="message.id"
      class="ai-message"
      :class="`ai-message--${message.role}`"
    >
      <!-- 用户消息：右侧气泡 -->
      <template v-if="message.role === 'user'">
        <div class="ai-message__user">{{ message.content }}</div>
      </template>

      <!-- 系统提示（操作结果等）：居中细条 -->
      <template v-else-if="message.role === 'system'">
        <p v-if="message.content" class="ai-message__system">{{ message.content }}</p>
        <ai-confirm-card v-for="action in message.pending_actions" :key="`action-${action.id}`" :action="action" />
      </template>

      <!-- 助手消息：头像 + 思考过程 + 正文 -->
      <template v-else>
        <div class="ai-message__avatar">
          <robot-icon />
        </div>
        <div class="ai-message__body">
          <div v-if="message.tool_runs.length" class="ai-message__tools">
            <ai-tool-runs :runs="message.tool_runs" />
          </div>

          <!-- 首个增量到达前：呼吸圆点占位，避免面板看起来「死住」 -->
          <div v-if="!message.started && !message.error" class="ai-thinking">
            <span class="ai-thinking__dot" />
            <span class="ai-thinking__dot" />
            <span class="ai-thinking__dot" />
            <span class="ai-thinking__text">{{ $t('ai.thinking') }}</span>
          </div>

          <!-- 思考过程：流式时自动展开，结束后收起成一行摘要 -->
          <section v-if="message.reasoning" class="ai-reason" :class="{ 'ai-reason--live': message.streaming }">
            <button type="button" class="ai-reason__head" @click="toggleReasoning(message.id)">
              <chevron-down-icon class="ai-reason__chevron" :class="{ 'ai-reason__chevron--open': isReasoningOpen(message) }" />
              <span class="ai-reason__title">
                {{ message.streaming ? $t('ai.reasoning.live') : $t('ai.reasoning.done') }}
              </span>
              <span class="ai-reason__meta">{{ reasoningChars(message.reasoning) }}</span>
            </button>
            <div v-show="isReasoningOpen(message)" class="ai-reason__body">{{ message.reasoning }}</div>
          </section>

          <p v-if="message.error" class="ai-message__error">{{ message.content || $t('ai.error.fallback') }}</p>

          <!-- 正文：流式期间按纯文本渲染（每 16ms 合并一次增量，不再逐 token 重排 markdown），
               结束后再切到 markdown-it 结果；两者都做了换行与断行保护 -->
          <div v-if="message.content && !message.error" class="ai-message__content">
            <div v-if="message.streaming" class="ai-message__plain">{{ message.content }}<span class="ai-message__caret" /></div>
            <!-- markdown-it 已关闭原始 HTML，产物是转义后的安全结构 -->
            <!-- eslint-disable-next-line vue/no-v-html -->
            <div v-else class="ai-message__markdown" v-html="renderMarkdown(message.content)"></div>
          </div>

          <ai-confirm-card v-for="action in message.pending_actions" :key="`action-${action.id}`" :action="action" />
        </div>
      </template>
    </article>
  </div>
</template>
<script setup lang="ts">
import type { AiChatMessage } from '@turaidc/shared/runtime';
import type { RendererRule } from 'markdown-it';
import MarkdownIt from 'markdown-it';
import { ChevronDownIcon, RobotIcon } from 'tdesign-icons-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

import AiConfirmCard from './AiConfirmCard.vue';
import AiToolRuns from './AiToolRuns.vue';

const props = defineProps<{ messages: AiChatMessage[] }>();

// html: false —— 不渲染任何原始 HTML，把模型输出当成纯 markdown 处理
const markdown = new MarkdownIt({ html: false, breaks: true, linkify: true });

// 外链统一新窗口打开，并切断 opener 引用，避免把当前页带跑
const linkOpenRule: RendererRule = (tokens, idx, options, _env, self) => {
  tokens[idx].attrSet('target', '_blank');
  tokens[idx].attrSet('rel', 'noopener noreferrer nofollow');
  return self.renderToken(tokens, idx, options);
};
markdown.renderer.rules.link_open = linkOpenRule;

/** 只有消息落定后才渲染 markdown：流式期间用纯文本，避免每个增量都整段重排 */
const renderMarkdown = (content: string): string => markdown.render(String(content || ''));

/** 用户手动点开的思考块（按消息 id 记）；流式中的消息默认展开 */
const openReasoning = ref<Record<string, boolean>>({});

const isReasoningOpen = (message: AiChatMessage): boolean => {
  const explicit = openReasoning.value[message.id];
  if (explicit !== undefined) {
    return explicit;
  }

  // 还没出正文时展开思考（用户能看见它在想什么），一旦开始出正文就自动收起，避免思考块占满面板
  return message.streaming === true && (message.content ?? '') === '';
};

const toggleReasoning = (id: string): void => {
  openReasoning.value = { ...openReasoning.value, [id]: !openReasoning.value[id] };
};

const reasoningChars = (text?: string): string => {
  const length = (text ?? '').length;

  return length > 1000 ? `${(length / 1000).toFixed(1)}k` : String(length);
};

const listRef = ref<HTMLElement | null>(null);
/** 用户手动往上翻时不再抢滚动条；回到底部附近自动恢复跟随 */
const stickToBottom = ref(true);

const handleScroll = (): void => {
  const element = listRef.value;
  if (!element) {
    return;
  }
  stickToBottom.value = element.scrollHeight - element.scrollTop - element.clientHeight < 80;
};

const scrollToBottom = (): void => {
  const element = listRef.value;
  if (element) {
    element.scrollTop = element.scrollHeight;
  }
};

onMounted(() => {
  void nextTick(scrollToBottom);
});

watch(
  () => props.messages.length,
  () => {
    stickToBottom.value = true;
    void nextTick(scrollToBottom);
  },
);

// 流式追加：最新一条消息内容/思考变化时跟随到底部（增量已在 store 里按帧合并）
watch(
  () => [props.messages[props.messages.length - 1]?.content, props.messages[props.messages.length - 1]?.reasoning],
  () => {
    if (stickToBottom.value) {
      void nextTick(scrollToBottom);
    }
  },
);
</script>
<style scoped lang="less">
.ai-message-list {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: var(--td-comp-margin-l);
  min-height: 0;
  padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
  background: var(--td-bg-color-container);
  overflow-y: auto;
  // 不启用 smooth：流式时每个增量都会重设 scrollTop，平滑动画会一直被打断而落后
}

.ai-message-list__empty {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: var(--td-comp-margin-s);
  align-items: center;
  justify-content: center;
  padding: var(--td-comp-paddingTB-xxl) var(--td-comp-paddingLR-l);
  text-align: center;
}

.ai-message-list__empty-icon {
  color: var(--td-brand-color);
  font-size: 2.25rem;
}

.ai-message-list__empty-title {
  margin: var(--td-comp-margin-s) 0 0;
  color: var(--td-text-color-primary);
  font: var(--td-font-title-small);
}

.ai-message-list__empty-desc {
  max-width: 20rem;
  margin: 0;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  line-height: 1.6;
}

.ai-message {
  display: flex;
  gap: var(--td-comp-margin-s);
  min-width: 0;
}

.ai-message--assistant {
  align-items: flex-start;
}

.ai-message--user {
  justify-content: flex-end;
}

.ai-message--system {
  flex-direction: column;
  align-items: stretch;
}

.ai-message__avatar {
  display: inline-flex;
  flex: 0 0 1.75rem;
  align-items: center;
  justify-content: center;
  width: 1.75rem;
  height: 1.75rem;
  margin-top: 2px;
  color: var(--td-brand-color);
  font-size: 1rem;
  background: var(--td-brand-color-light);
  border-radius: 50%;
}

.ai-message__body {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: var(--td-comp-margin-s);
  min-width: 0;
  // 独立页很宽，正文限宽保证行长可读；抽屉里自然撑满
  max-width: 56rem;
}

.ai-message__user {
  max-width: 86%;
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-m);
  color: var(--td-text-color-primary);
  font: var(--td-font-body-medium);
  line-height: 1.7;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  background: var(--td-bg-color-secondarycontainer);
  border-radius: var(--td-radius-medium) var(--td-radius-small) var(--td-radius-medium) var(--td-radius-medium);
}

.ai-message__system {
  margin: 0;
  padding: var(--td-comp-paddingTB-xs) var(--td-comp-paddingLR-s);
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  text-align: center;
  background: var(--td-bg-color-secondarycontainer);
  border-radius: var(--td-radius-small);
}

.ai-message__error {
  margin: 0;
  color: var(--td-error-color);
  font: var(--td-font-body-medium);
  white-space: pre-wrap;
}

.ai-message__tools {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-xs);
}

// —— 思考中：三点呼吸 ——
.ai-thinking {
  display: inline-flex;
  gap: var(--td-comp-margin-xs);
  align-items: center;
  padding: var(--td-comp-paddingTB-xs) 0;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.ai-thinking__dot {
  width: 0.375rem;
  height: 0.375rem;
  background: var(--td-brand-color);
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
  margin-left: var(--td-comp-margin-xs);
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

// —— 思考过程（可折叠） ——
.ai-reason {
  overflow: hidden;
  background: var(--td-bg-color-secondarycontainer);
  border: thin solid var(--td-component-stroke);
  border-radius: var(--td-radius-medium);
}

.ai-reason__head {
  display: flex;
  gap: var(--td-comp-margin-xs);
  align-items: center;
  width: 100%;
  padding: var(--td-comp-paddingTB-xs) var(--td-comp-paddingLR-s);
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  text-align: left;
  cursor: pointer;
  background: transparent;
  border: 0;
}

.ai-reason__head:hover {
  color: var(--td-brand-color);
}

.ai-reason__chevron {
  transition: transform 0.15s ease;
}

.ai-reason__chevron--open {
  transform: rotate(0deg);
}

.ai-reason__chevron:not(.ai-reason__chevron--open) {
  transform: rotate(-90deg);
}

.ai-reason__title {
  color: var(--td-text-color-primary);
}

.ai-reason--live .ai-reason__title {
  background: linear-gradient(90deg, var(--td-text-color-secondary), var(--td-brand-color), var(--td-text-color-secondary));
  background-size: 200% 100%;
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
  animation: ai-reason-shimmer 1.6s linear infinite;
}

.ai-reason__meta {
  margin-left: auto;
  font-variant-numeric: tabular-nums;
}

.ai-reason__body {
  max-height: 14rem;
  padding: 0 var(--td-comp-paddingLR-s) var(--td-comp-paddingTB-s);
  overflow-y: auto;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  line-height: 1.7;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  border-top: thin dashed var(--td-component-stroke);
}

@keyframes ai-reason-shimmer {
  0% {
    background-position: 200% 0;
  }

  100% {
    background-position: -200% 0;
  }
}

// —— 正文 ——
.ai-message__content {
  color: var(--td-text-color-primary);
  font: var(--td-font-body-medium);
  line-height: 1.75;
  overflow-wrap: anywhere;
}

.ai-message__plain {
  white-space: pre-wrap;
}

.ai-message__caret {
  display: inline-block;
  width: 0.375rem;
  height: 0.95em;
  margin-left: 1px;
  vertical-align: text-bottom;
  background: var(--td-brand-color);
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

// markdown 内容由 v-html 注入，选择器必须穿透作用域
.ai-message__markdown {
  color: var(--td-text-color-primary);

  :deep(p) {
    margin: 0 0 var(--td-comp-margin-s);
  }

  :deep(p:last-child) {
    margin-bottom: 0;
  }

  :deep(h1),
  :deep(h2),
  :deep(h3),
  :deep(h4) {
    margin: var(--td-comp-margin-l) 0 var(--td-comp-margin-s);
    font-weight: 600;
    line-height: 1.4;
  }

  :deep(h1) {
    font-size: 1.15rem;
  }

  :deep(h2) {
    font-size: 1.05rem;
  }

  :deep(h3),
  :deep(h4) {
    font-size: 1rem;
  }

  :deep(ul),
  :deep(ol) {
    padding-left: 1.25rem;
    margin: 0 0 var(--td-comp-margin-s);
  }

  :deep(li) {
    margin-bottom: var(--td-comp-margin-xs);
  }

  :deep(li > p) {
    margin: 0;
  }

  :deep(code) {
    padding: 0.1rem 0.3rem;
    font-family: ui-monospace, sfmono-regular, menlo, consolas, monospace;
    font-size: 0.8125rem;
    background: var(--td-bg-color-secondarycontainer);
    border-radius: var(--td-radius-small);
  }

  :deep(pre) {
    padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-s);
    margin: 0 0 var(--td-comp-margin-s);
    overflow-x: auto;
    background: var(--td-bg-color-secondarycontainer);
    border-radius: var(--td-radius-medium);
  }

  :deep(pre code) {
    padding: 0;
    background: transparent;
  }

  :deep(blockquote) {
    padding: var(--td-comp-paddingTB-xs) var(--td-comp-paddingLR-s);
    margin: 0 0 var(--td-comp-margin-s);
    color: var(--td-text-color-secondary);
    background: var(--td-bg-color-secondarycontainer);
    border-left: 3px solid var(--td-component-stroke);
  }

  :deep(a) {
    color: var(--td-brand-color);
    text-decoration: underline;
    text-underline-offset: 2px;
  }

  :deep(table) {
    display: block;
    width: 100%;
    margin: 0 0 var(--td-comp-margin-s);
    overflow-x: auto;
    border-collapse: collapse;
    font-size: 0.8125rem;
  }

  :deep(th),
  :deep(td) {
    padding: var(--td-comp-paddingTB-xs) var(--td-comp-paddingLR-s);
    border: thin solid var(--td-component-stroke);
    text-align: left;
    white-space: normal;
  }

  :deep(th) {
    font-weight: 600;
    background: var(--td-bg-color-secondarycontainer);
  }

  :deep(hr) {
    margin: var(--td-comp-margin-l) 0;
    border: 0;
    border-top: thin solid var(--td-component-stroke);
  }
}
</style>
