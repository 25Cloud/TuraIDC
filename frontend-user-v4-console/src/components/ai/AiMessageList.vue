<template>
  <div ref="listRef" class="ai-message-list" @scroll="handleScroll">
    <div v-if="!messages.length" class="ai-message-list__empty">
      <robot-icon class="ai-message-list__empty-icon" />
      <p class="ai-message-list__empty-title">{{ $t('ai.empty.title') }}</p>
      <p class="ai-message-list__empty-desc">{{ $t('ai.empty.description') }}</p>
    </div>

    <article v-for="message in messages" :key="message.id" class="ai-message" :class="`ai-message--${message.role}`">
      <div v-if="message.tool_runs.length" class="ai-message__tools">
        <ai-tool-runs :runs="message.tool_runs" />
      </div>

      <div v-if="message.role === 'user'" class="ai-message__bubble">{{ message.content }}</div>

      <p v-else-if="message.role === 'system' && message.content" class="ai-message__system">{{ message.content }}</p>

      <div v-else-if="message.role === 'assistant'" class="ai-message__bubble">
        <p v-if="message.error" class="ai-message__error">{{ message.content || $t('ai.error.fallback') }}</p>
        <template v-else>
          <!-- 助手正文走 markdown-it({ html: false })：模型输出里的原始 HTML 一律转义成文本 -->
          <!-- markdown-it 已关闭原始 HTML，注入的是转义后的纯 markdown 结果，故可用 v-html -->
          <!-- eslint-disable-next-line vue/no-v-html -->
          <div v-if="message.content" class="ai-message__markdown" v-html="renderMarkdown(message.content)"></div>
          <p v-else-if="message.streaming" class="ai-message__typing">{{ $t('ai.thinking') }}</p>
          <span v-if="message.streaming" class="ai-message__caret" aria-hidden="true"></span>
        </template>
      </div>

      <ai-confirm-card v-for="action in message.pending_actions" :key="`action-${action.id}`" :action="action" />
    </article>
  </div>
</template>
<script setup lang="ts">
import type { AiChatMessage } from '@turaidc/shared/runtime';
import type { RendererRule } from 'markdown-it';
import MarkdownIt from 'markdown-it';
import { RobotIcon } from 'tdesign-icons-vue-next';
import { nextTick, onMounted, ref, watch } from 'vue';

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

const renderMarkdown = (content: string): string => markdown.render(String(content || ''));

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

// 流式追加：最新一条消息内容变化时跟随到底部
watch(
  () => props.messages[props.messages.length - 1]?.content,
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
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-l);
  overflow-y: auto;
  // 不启用 smooth：流式时每个 delta 都会重设 scrollTop，平滑动画会一直被打断而落后
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
  font-size: 2rem;
}

.ai-message-list__empty-title {
  margin: 0;
  color: var(--td-text-color-primary);
  font: var(--td-font-title-small);
}

.ai-message-list__empty-desc {
  max-width: 22rem;
  margin: 0;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.ai-message {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-s);
  min-width: 0;
}

.ai-message__bubble {
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-m);
  color: var(--td-text-color-primary);
  font: var(--td-font-body-medium);
  overflow-wrap: anywhere;
  white-space: pre-wrap;
  background: var(--td-bg-color-container);
  border: thin solid var(--td-component-stroke);
  border-radius: var(--td-radius-medium);
}

.ai-message--user {
  align-items: flex-end;
}

.ai-message--user .ai-message__bubble {
  max-width: 88%;
  background: var(--td-brand-color-light);
  border-color: transparent;
}

.ai-message--assistant .ai-message__bubble {
  white-space: normal;
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

.ai-message__typing {
  margin: 0;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.ai-message__caret {
  display: inline-block;
  width: 0.375rem;
  height: 0.875rem;
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
  font: var(--td-font-body-medium);
  color: var(--td-text-color-primary);
  overflow-wrap: anywhere;

  :deep(p) {
    margin: 0 0 var(--td-comp-margin-s);
  }

  :deep(p:last-child) {
    margin-bottom: 0;
  }

  :deep(ul),
  :deep(ol) {
    padding-left: 1.25rem;
    margin: 0 0 var(--td-comp-margin-s);
  }

  :deep(li) {
    margin-bottom: var(--td-comp-margin-xs);
  }

  :deep(code) {
    padding: 0 0.25rem;
    font-family: ui-monospace, menlo, consolas, monospace;
    font-size: 0.8125rem;
    background: var(--td-bg-color-secondarycontainer);
    border-radius: var(--td-radius-small);
  }

  :deep(pre) {
    padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-s);
    margin: 0 0 var(--td-comp-margin-s);
    overflow-x: auto;
    background: var(--td-bg-color-secondarycontainer);
    border-radius: var(--td-radius-small);
  }

  :deep(pre code) {
    padding: 0;
    background: transparent;
  }

  :deep(blockquote) {
    padding-left: var(--td-comp-paddingLR-s);
    margin: 0 0 var(--td-comp-margin-s);
    color: var(--td-text-color-secondary);
    border-left: 2px solid var(--td-component-stroke);
  }

  :deep(a) {
    color: var(--td-brand-color);
  }

  :deep(table) {
    width: 100%;
    border-collapse: collapse;
  }

  :deep(th),
  :deep(td) {
    padding: var(--td-comp-paddingTB-xs) var(--td-comp-paddingLR-xs);
    border: thin solid var(--td-component-stroke);
    text-align: left;
  }
}
</style>
