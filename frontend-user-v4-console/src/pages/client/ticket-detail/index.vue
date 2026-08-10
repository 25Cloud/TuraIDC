<template>
  <section class="ticket-detail-page">
    <div class="ticket-detail-toolbar">
      <t-button variant="text" @click="goBack">返回工单列表</t-button>
      <h1>工单详情</h1>
      <t-button v-if="detail && !isClosed" theme="danger" variant="outline" :loading="closing" @click="closeTicket">
        关闭工单
      </t-button>
    </div>

    <loading-state :loading="loading" text="正在加载工单详情">
      <template v-if="detail">
        <div class="ticket-detail-shell">
          <aside class="ticket-meta-card">
            <div class="ticket-meta-card__head">
              <strong>{{ titleText }}</strong>
              <t-tag :theme="resolveTicketTagTheme(detail.status)" variant="light">
                {{ resolveTicketStatusLabel(detail.status) }}
              </t-tag>
            </div>
            <t-descriptions :column="1" bordered>
              <t-descriptions-item label="用户">
                {{ userName }}
                <span class="meta-id">id: {{ detail.user?.id || detail.user_id || '--' }}</span>
              </t-descriptions-item>
              <t-descriptions-item label="工单分类">{{
                resolveDepartmentLabel(detail.department)
              }}</t-descriptions-item>
              <t-descriptions-item label="优先级">
                <t-tag :theme="resolvePriorityTheme(detail.priority)" variant="light">
                  {{ resolvePriorityLabel(detail.priority) }}
                </t-tag>
              </t-descriptions-item>
              <t-descriptions-item label="处理人">{{ assigneeName }}</t-descriptions-item>
              <t-descriptions-item label="关联服务">
                {{ detail.service?.id || detail.service_id || '--' }}
                <template v-if="detail.service?.display_name || detail.service?.product_name">
                  （{{ detail.service?.display_name || detail.service?.product_name }}）
                </template>
              </t-descriptions-item>
              <t-descriptions-item label="创建时间">{{ formatTicketTime(detail.created_at) }}</t-descriptions-item>
              <t-descriptions-item label="更新时间">{{ formatTicketTime(detail.updated_at) }}</t-descriptions-item>
              <t-descriptions-item v-if="detail.close_reason_label" label="关闭原因">
                {{ detail.close_reason_label }}
              </t-descriptions-item>
            </t-descriptions>
          </aside>

          <section class="ticket-conversation-card">
            <t-tabs v-model="activeMobileTab" class="mobile-ticket-tabs">
              <t-tab-panel value="chat" label="交流" />
              <t-tab-panel value="detail" label="详情" />
            </t-tabs>

            <div class="mobile-detail-card">
              <t-descriptions :column="1" bordered>
                <t-descriptions-item label="工单分类">{{
                  resolveDepartmentLabel(detail.department)
                }}</t-descriptions-item>
                <t-descriptions-item label="优先级">{{ resolvePriorityLabel(detail.priority) }}</t-descriptions-item>
                <t-descriptions-item label="状态">{{ resolveTicketStatusLabel(detail.status) }}</t-descriptions-item>
                <t-descriptions-item label="处理人">{{ assigneeName }}</t-descriptions-item>
                <t-descriptions-item label="关联服务">
                  <template v-if="detail.service?.id || detail.service_id">
                    {{ detail.service?.id || detail.service_id }}
                    <template v-if="detail.service?.display_name || detail.service?.name">
                      （{{ detail.service?.display_name || detail.service?.name }}）
                    </template>
                  </template>
                  <template v-else>--</template>
                </t-descriptions-item>
                <t-descriptions-item v-if="detail.close_reason_label" label="关闭原因">
                  {{ detail.close_reason_label }}
                </t-descriptions-item>
              </t-descriptions>
              <t-button v-if="!isClosed" theme="danger" variant="outline" block :loading="closing" @click="closeTicket">
                关闭工单
              </t-button>
            </div>

            <div class="ticket-chat-panel">
              <div class="message-list">
                <article v-if="detail.content" class="message-item message-item--user">
                  <div class="message-meta">
                    <span>{{ detail.user?.display_name || '我' }}</span>
                    <span>{{ formatTicketTime(detail.created_at) }}</span>
                  </div>
                  <div class="message-bubble">
                    <p>{{ detail.content }}</p>
                    <div v-if="parseAttachments(detail).length" class="message-attachments">
                      <button
                        v-for="attachment in parseAttachments(detail)"
                        :key="attachment.id"
                        type="button"
                        class="message-attachment"
                        @click="previewAttachment(attachment)"
                      >
                        <img :src="attachment.url" alt="附件" />
                      </button>
                    </div>
                  </div>
                </article>

                <article
                  v-for="reply in detail.replies || []"
                  :key="reply.id"
                  class="message-item"
                  :class="reply.is_staff ? 'message-item--staff' : 'message-item--user'"
                >
                  <div class="message-meta">
                    <span>{{ reply.sender_name || (reply.is_staff ? '客服' : '我') }}</span>
                    <t-tag size="small" :theme="reply.is_staff ? 'primary' : 'success'" variant="light">
                      {{ reply.is_staff ? '管理员' : '用户' }} #{{ reply.user_id }}
                    </t-tag>
                    <span>{{ formatTicketTime(reply.created_at) }}</span>
                  </div>
                  <div class="message-bubble">
                    <p v-if="reply.recalled" class="message-recalled">消息已撤回</p>
                    <template v-else>
                      <blockquote v-if="reply.quote">
                        {{ reply.quote.sender_name }}：{{ reply.quote.recalled ? '消息已撤回' : reply.quote.content }}
                      </blockquote>
                      <p>{{ reply.content || '无文字内容' }}</p>
                      <div v-if="parseAttachments(reply).length" class="message-attachments">
                        <button
                          v-for="attachment in parseAttachments(reply)"
                          :key="attachment.id"
                          type="button"
                          class="message-attachment"
                          @click="previewAttachment(attachment)"
                        >
                          <img :src="attachment.url" alt="附件" />
                        </button>
                      </div>
                    </template>
                  </div>
                  <div class="message-actions">
                    <t-button
                      v-if="canRecall(reply)"
                      size="small"
                      theme="danger"
                      variant="text"
                      :loading="recalling"
                      @click="recallReply(reply)"
                    >
                      撤回
                    </t-button>
                  </div>
                </article>
              </div>

              <section v-if="!isClosed" class="reply-composer">
                <div v-if="replyAttachments.length" class="reply-attachments">
                  <button
                    v-for="(file, index) in replyAttachments"
                    :key="file.id || file.path || index"
                    type="button"
                    class="reply-attachment"
                    @click="previewAttachment(file)"
                  >
                    <img :src="file.url || file.path" alt="附件" />
                    <span @click.stop="removeReplyAttachment(index)">移除</span>
                  </button>
                </div>
                <div class="reply-composer__bar">
                  <label class="composer-upload-trigger">
                    <input type="file" accept=".jpg,.jpeg,.png,.webp" multiple @change="handleReplyUpload" />
                    <span><add-icon /></span>
                  </label>
                  <t-textarea
                    v-model="replyContent"
                    class="reply-textarea"
                    :autosize="{ minRows: 1, maxRows: 4 }"
                    maxlength="5000"
                    placeholder="输入回复内容..."
                    @enter="submitReply"
                  />
                  <t-button
                    theme="primary"
                    :loading="replying"
                    :disabled="!canSubmitReply || replyUploading"
                    @click="submitReply"
                  >
                    发送
                  </t-button>
                </div>
              </section>

              <div v-else class="ticket-closed-notice">此工单已关闭</div>
            </div>
          </section>
        </div>
      </template>
      <t-empty v-else description="工单不存在" />
    </loading-state>

    <t-dialog
      v-model:visible="previewVisible"
      header="附件预览"
      width="min(45rem, calc(100vw - var(--td-comp-margin-xl)))"
    >
      <img v-if="previewUrl" :src="previewUrl" class="preview-image" alt="附件预览" />
    </t-dialog>
  </section>
</template>
<script setup lang="ts">
import LoadingState from '@shared/user-v3/components/LoadingState.vue';
import { AddIcon } from 'tdesign-icons-vue-next';
import { onMounted } from 'vue';

import {
  formatTicketTime,
  parseAttachments,
  resolveDepartmentLabel,
  resolvePriorityLabel,
  resolvePriorityTheme,
  resolveTicketStatusLabel,
  resolveTicketTagTheme,
  useTicketDetail,
} from '@/domains/support/useTickets';

const {
  loading,
  replying,
  closing,
  recalling,
  replyUploading,
  detail,
  replyContent,
  replyAttachments,
  previewVisible,
  previewUrl,
  activeMobileTab,
  userName,
  assigneeName,
  isClosed,
  canSubmitReply,
  titleText,
  canRecall,
  loadDetail,
  uploadReplyImage,
  removeReplyAttachment,
  previewAttachment,
  submitReply,
  recallReply,
  closeTicket,
  goBack,
} = useTicketDetail();

async function handleReplyUpload(event: Event) {
  const input = event.target as HTMLInputElement;
  const files = Array.from(input.files || []);
  for (const file of files) {
    await uploadReplyImage(file);
  }
  input.value = '';
}

onMounted(() => {
  void loadDetail();
});
</script>
<style scoped lang="less">
.ticket-detail-page {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
  min-height: calc(100vh - var(--td-comp-size-xxxxxl));
  // padding 由 Starter 布局层统一提供
}

.ticket-detail-toolbar {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: var(--td-comp-margin-s);
  align-items: center;

  h1 {
    margin: 0;
    color: var(--td-text-color-primary);
    font: var(--td-font-title-large);
  }
}

.ticket-detail-shell {
  display: grid;
  grid-template-columns: minmax(17rem, 0.38fr) minmax(0, 1fr);
  gap: var(--td-comp-margin-m);
  min-height: 35rem;
}

.ticket-meta-card,
.ticket-conversation-card {
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.ticket-meta-card {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
  padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
}

.ticket-meta-card__head {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: flex-start;
  justify-content: space-between;

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-title-medium);
  }
}

.meta-id {
  margin-left: var(--td-comp-margin-xs);
  color: var(--td-text-color-placeholder);
}

.ticket-conversation-card {
  display: flex;
  flex-direction: column;
  min-width: 0;
  overflow: hidden;
}

.mobile-ticket-tabs,
.mobile-detail-card {
  display: none;
}

.ticket-chat-panel {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 0;
}

.message-list {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
  min-height: 26rem;
  max-height: calc(100vh - 20rem);
  padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
  overflow-y: auto;
}

.message-item {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xs);
  max-width: min(72%, 40rem);
}

.message-item--user {
  align-self: flex-end;
  align-items: flex-end;

  .message-bubble {
    background: var(--td-brand-color-light);
    border-color: var(--td-brand-color-light);
  }
}

.message-item--staff {
  align-self: flex-start;
}

.message-meta,
.message-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-xs);
  align-items: center;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.message-bubble {
  display: grid;
  gap: var(--td-comp-margin-s);
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  color: var(--td-text-color-primary);
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);

  p {
    margin: 0;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }

  blockquote {
    margin: 0;
    padding-left: var(--td-comp-paddingLR-s);
    color: var(--td-text-color-secondary);
    border-left: var(--td-comp-margin-xxs) solid var(--td-border-color);
  }
}

.message-recalled {
  color: var(--td-text-color-placeholder);
  font-style: italic;
}

.message-attachments,
.reply-attachments {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
}

.message-attachment,
.reply-attachment {
  position: relative;
  width: var(--td-comp-size-xxxxl);
  height: var(--td-comp-size-xxxxl);
  padding: 0;
  overflow: hidden;
  cursor: pointer;
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.reply-composer {
  display: grid;
  gap: var(--td-comp-margin-s);
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-l);
  border-top: thin solid var(--td-border-color);
}

.reply-attachment span {
  position: absolute;
  right: 0;
  bottom: 0;
  left: 0;
  color: var(--td-text-color-anti);
  font: var(--td-font-body-small);
  text-align: center;
  background: var(--td-mask-active);
}

.reply-composer__bar {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: var(--td-comp-margin-s);
  align-items: center;
}

.composer-upload-trigger {
  display: inline-flex;
  cursor: pointer;

  input {
    display: none;
  }

  span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: var(--td-comp-size-xl);
    height: var(--td-comp-size-xl);
    color: var(--td-brand-color);
    background: var(--td-brand-color-light);
    border-radius: var(--td-radius-round);
  }
}

.reply-textarea :deep(.t-textarea__inner) {
  resize: none;
  min-height: 2rem;
  max-height: 7.5rem;
}

.reply-textarea {
  align-self: center;
}

.reply-textarea :deep(.t-textarea__info_wrapper) {
  display: none;
}

.preview-image {
  display: block;
  width: 100%;
}

@media (max-width: @screen-sm-rem) {
  .ticket-detail-page {
    min-height: calc(100vh - var(--td-comp-size-xxxl));
  }

  .ticket-detail-toolbar {
    grid-template-columns: 1fr auto;

    h1 {
      display: none;
    }
  }

  .ticket-detail-shell {
    grid-template-columns: 1fr;
    min-height: 0;
  }

  .ticket-meta-card {
    display: none;
  }

  .mobile-ticket-tabs {
    display: block;
  }

  .mobile-detail-card {
    display: none;
    gap: var(--td-comp-margin-m);
    padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  }

  .ticket-chat-panel {
    display: flex;
  }

  .ticket-conversation-card:has(.t-tabs__nav-item:nth-child(3).t-is-active) {
    .mobile-detail-card {
      display: grid;
    }

    .ticket-chat-panel {
      display: none;
    }
  }

  .message-list {
    min-height: 24rem;
    max-height: none;
    padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  }

  .message-item {
    max-width: 88%;
  }

  .reply-composer {
    padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-m);
  }

  .reply-composer__bar {
    grid-template-columns: auto minmax(0, 1fr) auto;
  }
}
</style>
