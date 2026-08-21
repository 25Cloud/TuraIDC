<template>
  <div v-loading="detailLoading" class="ticket-panel" :class="{ 'is-mobile': isMobile, 'ticket-panel--embedded': embedded }">
    <el-button
      v-if="detail?.status !== 3 && !isMobile"
      class="manual-close-button"
      plain
      :loading="closing"
      @click="handleClose"
    >
      关闭工单
    </el-button>

    <!-- ========== 桌面端布局 ========== -->
    <template v-if="!isMobile">
      <section class="conversation-section">
        <div class="message-list" ref="messageListRef">
          <!-- 工单原内容（主贴） -->
          <div v-if="detail?.content" class="message-item" :class="isSelfReply({ is_staff: false }) ? 'message-customer' : 'message-staff'">
            <div class="message-bubble">
              <div class="message-meta">
                <span class="message-sender">{{ isSelfReply({ is_staff: false }) ? '我' : userName }}</span>
                <span class="message-time">{{ formatDateTime(detail?.created_at) }}</span>
              </div>
              <div class="message-content-wrapper">
                <div class="message-content">{{ detail.content }}</div>
                <div v-if="hasAttachments(detail)" class="message-attachments">
                  <div
                    v-for="att in parseAttachments(detail)"
                    :key="att.id"
                    class="attachment-thumb"
                    @click="handleAttachmentPreview(att)"
                  >
                    <el-image :src="att.url" fit="cover" />
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 各个回复 -->
          <div
            v-for="reply in detail?.replies || []"
            :key="reply.id"
            class="message-item"
            :class="isSelfReply(reply) ? 'message-customer' : 'message-staff'"
          >
            <div class="message-bubble">
              <div class="message-meta">
                <span class="message-sender">{{ isSelfReply(reply) ? '我' : (reply.sender_name || (reply.is_staff ? '客服' : '我')) }}</span>
                <span class="sender-role-tag" :class="reply.is_staff ? 'sender-role-tag--staff' : 'sender-role-tag--user'">{{ reply.is_staff ? '管理员' : '用户' }} #{{ reply.user_id }}</span>
                <span class="message-time">{{ reply.created_at }}</span>
              </div>
              <div v-if="reply.recalled" class="message-recalled">消息已撤回</div>
              <div v-else class="message-content-wrapper">
                <div v-if="reply.quote" class="message-quote-preview">
                  <span class="quote-sender">{{ reply.quote.sender_name }}:</span>
                  <span class="quote-text">{{ reply.quote.recalled ? '消息已撤回' : reply.quote.content }}</span>
                </div>
                <div class="message-content">{{ reply.content || '无文字内容' }}</div>
                <div v-if="hasAttachments(reply)" class="message-attachments">
                  <div
                    v-for="att in parseAttachments(reply)"
                    :key="att.id"
                    class="attachment-thumb"
                    :class="{ 'attachment-thumb--deleted': att.deleted || !att.url }"
                    @click="att.url && handleAttachmentPreview(att)"
                  >
                    <template v-if="att.url">
                      <el-image
                        :src="att.url"
                        fit="cover"
                        :preview-src-list="getPreviewList(reply)"
                        :initial-index="getPreviewIndex(reply, att)"
                        preview-teleported
                      />
                    </template>
                    <template v-else>
                      <span class="deleted-placeholder">已删除</span>
                    </template>
                  </div>
                </div>
              </div>
            </div>
            <div class="message-actions">
              <span v-if="!reply.recalled" class="msg-action-btn" @click="handleQuote(reply)">引用</span>
              <span v-if="canRecall(reply)" class="msg-action-btn msg-action-recall" @click="handleRecall(reply)">撤回</span>
            </div>
          </div>
        </div>
      </section>

      <section class="reply-section" v-if="detail?.status !== 3">
        <div v-if="quoteReply" class="quote-preview-bar">
          <div class="quote-preview-content">
            <span class="quote-preview-sender">回复 {{ quoteReply.sender_name }}</span>
            <span class="quote-preview-text">{{ quoteReply.content }}</span>
          </div>
          <button class="quote-preview-cancel" @click="cancelQuote">×</button>
        </div>
        <div v-if="replyAttachments.length" class="draft-attachments">
          <button
            v-for="attachment in replyAttachments"
            :key="attachment.id || attachment.uid"
            class="draft-attachment"
            type="button"
            @click="handleDraftAttachmentPreview(attachment)"
          >
            <img :src="attachment.url" alt="附件" />
            <span class="draft-remove" @click.stop="removeReplyAttachment(attachment.id || attachment.uid)">×</span>
          </button>
        </div>

        <div class="composer-bar">
          <el-upload
            class="composer-upload"
            accept=".jpg,.jpeg,.png,.webp"
            multiple
            :show-file-list="false"
            :http-request="handleReplyUpload"
            :before-upload="beforeReplyUpload"
            :on-exceed="handleReplyUploadExceed"
            :limit="MAX_IMAGES"
            :disabled="replying"
          >
            <button class="composer-plus" type="button" :disabled="replyUploadDisabled || replying">
              <el-icon><Plus /></el-icon>
            </button>
          </el-upload>
          <el-input
            v-model="replyContent"
            type="textarea"
            :autosize="{ minRows: 1, maxRows: 4 }"
            maxlength="5000"
            placeholder="输入回复内容..."
            class="reply-textarea"
            @keydown.enter.exact.prevent="handleReply"
          />
          <el-button
            class="send-button"
            :loading="replying"
            :disabled="replySubmittingDisabled"
            @click="handleReply"
          >
            发送
          </el-button>
        </div>
      </section>

      <div v-if="detail?.status === 3" class="ticket-closed-notice">
        <el-icon><CircleCheck /></el-icon>
        <span>
          此工单已关闭
          {{ detail?.close_reason_label ? `（${detail.close_reason_label}）` : '' }}
        </span>
      </div>
    </template>

    <!-- ========== 移动端布局 ========== -->
    <template v-else>
      <section class="mobile-conversation-section">
        <div class="mobile-message-list" ref="messageListRef">
          <!-- 工单原内容（主贴） -->
          <div v-if="detail?.content" class="mobile-message" :class="isSelfReply({ is_staff: false }) ? 'mobile-message--customer' : 'mobile-message--staff'">
            <div class="mobile-message__bubble-wrap">
              <div class="mobile-message__meta">
                <span>{{ isSelfReply({ is_staff: false }) ? '我' : userName }}</span>
                <span>{{ formatDateTime(detail?.created_at) }}</span>
              </div>
              <div class="mobile-message__bubble">
                <div class="mobile-message__content">{{ detail.content }}</div>
                <div v-if="hasAttachments(detail)" class="mobile-message__images">
                  <div
                    v-for="att in parseAttachments(detail)"
                    :key="att.id"
                    class="mobile-message__img"
                    @click="handleAttachmentPreview(att)"
                  >
                    <el-image :src="att.url" fit="cover" />
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 各个回复 -->
          <div
            v-for="reply in detail?.replies || []"
            :key="reply.id"
            class="mobile-message"
            :class="isSelfReply(reply) ? 'mobile-message--customer' : 'mobile-message--staff'"
          >
            <div class="mobile-message__bubble-wrap">
              <div class="mobile-message__meta">
                <span>
                  {{ isSelfReply(reply) ? '我' : (reply.sender_name || (reply.is_staff ? '客服' : '客户')) }}
                  <span class="sender-role-tag" :class="reply.is_staff ? 'sender-role-tag--staff' : 'sender-role-tag--user'">{{ reply.is_staff ? '管理员' : '用户' }} #{{ reply.user_id }}</span>
                </span>
                <span>{{ reply.created_at }}</span>
              </div>
              <div class="mobile-message__bubble">
                <div v-if="reply.recalled" class="mobile-message__recalled">消息已撤回</div>
                <div v-else>
                  <div v-if="reply.quote" class="message-quote-preview">
                    <span class="quote-sender">{{ reply.quote.sender_name }}:</span>
                    <span class="quote-text">{{ reply.quote.recalled ? '消息已撤回' : reply.quote.content }}</span>
                  </div>
                  <div class="mobile-message__content">{{ reply.content || '无文字内容' }}</div>
                  <div v-if="hasAttachments(reply)" class="mobile-message__images">
                    <div
                      v-for="att in parseAttachments(reply)"
                      :key="att.id"
                      class="mobile-message__img"
                      :class="{ 'mobile-message__img--deleted': att.deleted || !att.url }"
                      @click="att.url && handleAttachmentPreview(att)"
                    >
                      <template v-if="att.url">
                        <el-image
                          :src="att.url"
                          fit="cover"
                          :preview-src-list="getPreviewList(reply)"
                          :initial-index="getPreviewIndex(reply, att)"
                          preview-teleported
                        />
                      </template>
                      <template v-else>
                        <span class="deleted-placeholder">已删除</span>
                      </template>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="message-actions">
              <span v-if="!reply.recalled" class="msg-action-btn" @click="handleQuote(reply)">引用</span>
              <span v-if="canRecall(reply)" class="msg-action-btn msg-action-recall" @click="handleRecall(reply)">撤回</span>
            </div>
          </div>
        </div>
      </section>

      <section class="mobile-reply-section" v-if="detail?.status !== 3">
        <div v-if="quoteReply" class="quote-preview-bar">
          <div class="quote-preview-content">
            <span class="quote-preview-sender">回复 {{ quoteReply.sender_name }}</span>
            <span class="quote-preview-text">{{ quoteReply.content }}</span>
          </div>
          <button class="quote-preview-cancel" @click="cancelQuote">×</button>
        </div>
        <div v-if="replyAttachments.length" class="mobile-draft-images">
          <button
            v-for="attachment in replyAttachments"
            :key="attachment.id || attachment.uid"
            class="mobile-draft-img"
            type="button"
            @click="handleDraftAttachmentPreview(attachment)"
          >
            <img :src="attachment.url" alt="附件" />
            <span class="mobile-draft-remove" @click.stop="removeReplyAttachment(attachment.id || attachment.uid)">×</span>
          </button>
        </div>

        <div class="mobile-composer-bar">
          <el-upload
            class="mobile-composer-upload"
            accept=".jpg,.jpeg,.png,.webp"
            multiple
            :show-file-list="false"
            :http-request="handleReplyUpload"
            :before-upload="beforeReplyUpload"
            :on-exceed="handleReplyUploadExceed"
            :limit="MAX_IMAGES"
            :disabled="replying"
          >
            <button class="mobile-plus-btn" type="button" :disabled="replyUploadDisabled || replying">+</button>
          </el-upload>
          <textarea
            v-model="replyContent"
            class="mobile-textarea"
            rows="1"
            maxlength="5000"
            placeholder="输入回复内容..."
            @input="adjustTextareaHeight"
            @keydown.enter.exact.prevent="handleReply"
          ></textarea>
          <button
            class="mobile-send-btn"
            :disabled="replySubmittingDisabled"
            :class="{ 'is-loading': replying }"
            @click="handleReply"
          >
            发送
          </button>
        </div>
      </section>

      <div v-if="detail?.status === 3" class="mobile-closed-notice">
        <el-icon><CircleCheck /></el-icon>
        <span>
          此工单已关闭
          {{ detail?.close_reason_label ? `（${detail.close_reason_label}）` : '' }}
        </span>
      </div>
    </template>

    <!-- 附件预览 -->
    <el-dialog v-model="previewVisible" title="附件预览" width="720px" append-to-body>
      <img v-if="previewUrl" :src="previewUrl" class="preview-image" alt="预览" />
    </el-dialog>
  </div>
</template>

<script setup>
import { computed, ref, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { CircleCheck, Plus } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'

const MAX_IMAGES = 9
const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp']
const MAX_SIZE = 5 * 1024 * 1024
const MOBILE_BREAKPOINT = 768

const props = defineProps({
  detail: {
    type: Object,
    required: true,
    default: () => ({ replies: [], content: '', status: null })
  },
  detailLoading: {
    type: Boolean,
    default: false
  },
  replying: {
    type: Boolean,
    default: false
  },
  closing: {
    type: Boolean,
    default: false
  },
  embedded: {
    type: Boolean,
    default: false
  },
  isStaffView: {
    type: Boolean,
    default: false
  },
  currentUserId: {
    type: [Number, String],
    default: null
  },
  uploadImage: {
    type: Function,
    required: true
  }
})

const emit = defineEmits(['reply', 'close', 'recall'])

const replyContent = ref('')
const replyAttachments = ref([])
const replyUploadingCount = ref(0)
const messageListRef = ref(null)
const previewVisible = ref(false)
const previewUrl = ref('')
const isMobile = ref(false)
const quoteReply = ref(null)

const userName = computed(() =>
  props.detail?.user?.display_name || props.detail?.user?.nickname || props.detail?.user?.email || '客户'
)

const replyUploadDisabled = computed(() =>
  replyAttachments.value.length + replyUploadingCount.value >= MAX_IMAGES
)

const canReply = computed(() =>
  replyContent.value.trim().length > 0 || replyAttachments.value.length > 0
)

const replySubmittingDisabled = computed(() =>
  !canReply.value || replyUploadingCount.value > 0
)

function checkMobile() {
  isMobile.value = window.innerWidth <= MOBILE_BREAKPOINT
}

watch(() => props.detail?.replies?.length, (newLen, oldLen) => {
  if (newLen > oldLen) {
    resetReplyDraft()
  }
  nextTick(() => scrollToBottom())
})

const adjustTextareaHeight = (e) => {
  const el = e.target
  el.style.height = 'auto'
  el.style.height = `${Math.min(100, el.scrollHeight)}px`
}

watch(replyContent, (val) => {
  if (!val) {
    const el = document.querySelector('.mobile-textarea')
    if (el) {
      el.style.height = 'auto'
    }
  }
})

function scrollToBottom() {
  const el = messageListRef.value
  if (el) {
    el.scrollTop = el.scrollHeight
  }
}

function isSelfReply(reply) {
  return Boolean(reply.is_staff) === Boolean(props.isStaffView)
}

function handleReply() {
  if (replyUploadingCount.value > 0) {
    ElMessage.warning('图片上传中，请稍后发送')
    return
  }

  if (!canReply.value) {
    ElMessage.warning('请输入回复内容或上传图片')
    return
  }

  const payload = {
    content: replyContent.value,
    attachments: replyAttachments.value.map((item) => item.path).filter(Boolean),
  }
  if (quoteReply.value?.id) {
    payload.quote_reply_id = quoteReply.value.id
  }

  emit('reply', payload)
}

function handleClose() {
  emit('close')
}

function canRecall(reply) {
  if (!reply || reply.recalled) return false
  if (props.isStaffView) {
    // 管理端：只要是自己作为 staff 身份发的且在两分钟内，均可撤回
    if (!reply.is_staff) return false
  } else {
    // 用户端：必须是非管理员且 user_id 和 currentUserId 一致，两分钟内
    if (reply.is_staff) return false
    if (props.currentUserId && Number(reply.user_id) !== Number(props.currentUserId)) return false
  }
  if (!reply.created_at) return false
  const created = new Date(reply.created_at).getTime()
  return Date.now() - created <= 120_000
}

function handleRecall(reply) {
  emit('recall', reply.id)
}

function handleQuote(reply) {
  if (!reply || reply.recalled) return
  quoteReply.value = {
    id: reply.id,
    sender_name: reply.sender_name || (reply.is_staff ? '管理员' : '用户'),
    content: reply.content || '',
  }
}

function cancelQuote() {
  quoteReply.value = null
}

function hasAttachments(item) {
  const attachments = Array.isArray(item?.attachments) && item.attachments.length > 0
    ? item.attachments
    : item?.attachment_urls
  return Array.isArray(attachments) && attachments.length > 0
}

function resetReplyDraft() {
  replyContent.value = ''
  replyAttachments.value = []
  replyUploadingCount.value = 0
  quoteReply.value = null
}

function beforeReplyUpload(file) {
  if (!IMAGE_TYPES.includes(file.type)) {
    ElMessage.warning('仅支持 jpg、png、webp 图片')
    return false
  }

  if (file.size > MAX_SIZE) {
    ElMessage.warning('单张图片不能超过 5MB')
    return false
  }

  if (replyUploadDisabled.value) {
    ElMessage.warning(`最多上传 ${MAX_IMAGES} 张图片`)
    return false
  }

  return true
}

function removeReplyAttachment(id) {
  const targetId = String(id)
  replyAttachments.value = replyAttachments.value.filter(
    (item) => String(item.id || item.uid) !== targetId
  )
}

async function handleReplyUpload(options) {
  replyUploadingCount.value += 1
  try {
    const formData = new FormData()
    formData.append('file', options.file)
    const res = await props.uploadImage(formData)
    replyAttachments.value = [...replyAttachments.value, res.data].slice(0, MAX_IMAGES)
    options.onSuccess?.({}, options.file)
  } catch (error) {
    options.onError?.(error)
    ElMessage.error(error?.message || '图片上传失败')
  } finally {
    replyUploadingCount.value = Math.max(0, replyUploadingCount.value - 1)
  }
}

function handleDraftAttachmentPreview(file) {
  previewUrl.value = file.url || ''
  previewVisible.value = !!previewUrl.value
}

function handleReplyUploadExceed() {
  ElMessage.warning(`最多上传 ${MAX_IMAGES} 张图片`)
}

function parseAttachments(item) {
  const attachments = Array.isArray(item?.attachments) && item.attachments.length > 0
    ? item.attachments
    : item?.attachment_urls || []
  return attachments.map((url, index) => ({
    id: index,
    url: typeof url === 'string' ? url : url.url,
    deleted: typeof url === 'string' ? false : url.deleted,
  }))
}

function handleAttachmentPreview(att) {
  previewUrl.value = att.url
  previewVisible.value = true
}

function getPreviewList(reply) {
  return parseAttachments(reply).filter((a) => a.url).map((a) => a.url)
}

function getPreviewIndex(reply, att) {
  return getPreviewList(reply).findIndex((url) => url === att.url)
}

function formatDateTime(dateStr) {
  if (!dateStr) return '--'
  return dateStr.slice(0, 16).replace('T', ' ')
}

onMounted(() => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
  nextTick(() => scrollToBottom())
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})
</script>

<style scoped lang="scss">
$border-color: var(--el-border-color-lighter, #e4e7ed);
$color-warning: var(--el-color-warning, #e6a23c);
$color-warning-soft: var(--el-color-warning-light-9, #fdf6ec);

.ticket-panel {
  position: relative;
  display: grid;
  grid-template-rows: minmax(360px, 1fr) auto;
  min-height: min(78vh, 760px);
  padding: 14px 0;
  border-radius: 12px;
  background: #fff;
  flex: 1;

  &.is-mobile {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
    padding: 0;
    border: none;
    border-radius: 0;
  }
}

.ticket-panel--embedded {
  min-height: calc(100vh - 180px);
  padding: 10px 0;
  border-radius: 12px;
  box-shadow: none;

  &.is-mobile {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
    padding: 0;
    border-radius: 0;
  }
}

.manual-close-button {
  position: absolute;
  top: -46px;
  right: 14px;
  z-index: 2;
  height: 30px;
  padding: 0 12px;
  color: #405675;
  border-color: #cfdcf0;
  border-radius: 999px;
  background: #fff;
  transition:
    transform 0.18s cubic-bezier(0.2, 0.8, 0.2, 1),
    border-color 0.18s ease,
    box-shadow 0.18s ease,
    color 0.18s ease;

  &:hover,
  &:focus {
    color: #dc2626;
    border-color: #fecaca;
    background: #fff;
    box-shadow: 0 8px 18px rgba(220, 38, 38, 0.12);
    transform: translateY(-1px);
  }

  &:active {
    transform: translateY(0) scale(0.98);
  }
}

.conversation-section {
  min-height: 0;
  padding: 4px 0 20px;
}

.message-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
  height: 100%;
  max-height: none;
  overflow-y: auto;
  padding: 4px 14px 4px 0;
}

.message-item {
  display: flex;
  position: relative;
  padding: 0 14px;
  margin-bottom: 4px;
}

.message-customer {
  flex-direction: row-reverse;

  .message-meta {
    flex-direction: row-reverse;
  }

  .message-attachments {
    justify-content: flex-end;
  }

  .message-actions {
    right: auto;
    left: 14px;
    flex-direction: row;
  }
}

.message-staff {
  .message-actions {
    left: auto;
    right: 14px;
    flex-direction: row-reverse;
  }
}

.message-bubble {
  min-width: 0;
  max-width: 72%;
}

.message-meta {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 6px;
}

.message-sender {
  font-size: 12px;
  font-weight: 500;
  color: #44546a;
}

.sender-role-tag {
  display: inline-flex;
  align-items: center;
  padding: 1px 6px;
  font-size: 10px;
  border-radius: 4px;
  font-weight: 500;
  line-height: 1.2;
}

.sender-role-tag--staff {
  background-color: var(--el-color-primary-light-9, #ecf5ff);
  color: var(--el-color-primary, #409eff);
}

.sender-role-tag--user {
  background-color: var(--el-color-info-light-9, #f4f4f5);
  color: var(--el-color-info, #909399);
}

.message-time {
  font-size: 12px;
  color: #8b98aa;
}

.message-content-wrapper {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.message-content {
  padding: 11px 14px;
  font-size: 14px;
  line-height: 1.6;
  color: #172033;
  white-space: pre-wrap;
  border: 1px solid #dce3ed;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
}

.message-staff .message-content {
  background: #fff;
  border-radius: 4px 14px 14px 14px;
}

.message-customer .message-content {
  background: #eaf2ff;
  border-color: #c9dcff;
  border-radius: 14px 4px 14px 14px;
}

.message-recalled {
  font-size: 12px;
  color: #a0aec0;
  font-style: italic;
  padding: 4px 14px;
  background: #f7fafc;
  border-radius: 12px;
  display: inline-block;
}

.message-quote-preview {
  padding: 6px 10px;
  background: #f1f5f9;
  border-left: 3px solid #cbd5e1;
  border-radius: 4px;
  font-size: 12px;
  margin-bottom: 6px;
  color: #64748b;
  display: flex;
  flex-direction: column;
  gap: 2px;

  .quote-sender {
    font-weight: 600;
  }
}

.message-attachments {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.attachment-thumb {
  width: 72px;
  height: 72px;
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid $border-color;
  cursor: pointer;
  transition: transform 0.2s;

  &:hover {
    transform: scale(1.05);
  }

  :deep(.el-image) {
    width: 100%;
    height: 100%;
  }
}

.attachment-thumb--deleted {
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--el-fill-color-light, #f5f7fa);
  border-style: dashed;
  cursor: default;

  &:hover {
    transform: none;
  }
}

.deleted-placeholder {
  font-size: 11px;
  color: var(--el-text-color-placeholder, #a8abb2);
}

.message-actions {
  position: absolute;
  bottom: -6px;
  display: none;
  gap: 8px;
  z-index: 10;
}

.message-item:hover .message-actions {
  display: flex;
}

.msg-action-btn {
  font-size: 11px;
  color: #3b82f6;
  cursor: pointer;
  background: #fff;
  padding: 1px 6px;
  border-radius: 4px;
  border: 1px solid #dbe4f1;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
  transition: all 0.15s ease;

  &:hover {
    color: #1d4ed8;
    background: #f0f6ff;
    border-color: #bfdbfe;
  }
}

.msg-action-recall {
  color: #ef4444;

  &:hover {
    color: #b91c1c;
    background: #fef2f2;
    border-color: #fecaca;
  }
}

.reply-section {
  padding-top: 4px;
  margin: 0 14px;
}

.quote-preview-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 6px 12px;
  margin-bottom: 8px;
  font-size: 12px;
}

.quote-preview-content {
  display: flex;
  flex-direction: column;
  gap: 2px;
  color: #475569;
}

.quote-preview-sender {
  font-weight: 600;
  color: #1e293b;
}

.quote-preview-text {
  text-overflow: ellipsis;
  overflow: hidden;
  white-space: nowrap;
  max-width: 500px;
}

.quote-preview-cancel {
  border: none;
  background: transparent;
  font-size: 16px;
  color: #94a3b8;
  cursor: pointer;

  &:hover {
    color: #ef4444;
  }
}

.draft-attachments {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin: 0 18px 10px;
}

.draft-attachment {
  position: relative;
  width: 54px;
  height: 54px;
  padding: 0;
  overflow: hidden;
  cursor: pointer;
  background: #fff;
  border: 1px solid #d7dce5;
  border-radius: 8px;
}

.draft-attachment img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.draft-remove {
  position: absolute;
  top: -1px;
  right: -1px;
  width: 14px;
  height: 14px;
  background: rgba(31, 41, 55, 0.78);
  color: #fff;
  font-size: 10px;
  line-height: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 0 8px 0 8px;
  border: none;
  cursor: pointer;
}

.composer-bar {
  display: grid;
  grid-template-columns: 48px minmax(0, 1fr) 112px;
  align-items: center;
  gap: 10px;
  min-height: 64px;
  padding: 8px 10px;
  border: 1px solid #d4dce8;
  border-radius: 999px;
  background: #fff;
  box-shadow: 0 12px 34px rgba(15, 23, 42, 0.08);
}

.composer-upload {
  display: flex;
  justify-content: center;

  :deep(.el-upload) {
    display: inline-flex;
  }
}

.composer-plus {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  padding: 0;
  color: #637083;
  cursor: pointer;
  background: #fff;
  border: 1px solid #ccd6e5;
  border-radius: 50%;
  transition:
    transform 0.18s cubic-bezier(0.2, 0.8, 0.2, 1),
    border-color 0.18s ease,
    box-shadow 0.18s ease,
    color 0.18s ease;

  .el-icon {
    font-size: 28px;
    transition: transform 0.22s cubic-bezier(0.2, 0.8, 0.2, 1);
  }

  &:not(:disabled):hover {
    color: #2563eb;
    border-color: #9fbcff;
    box-shadow: 0 8px 18px rgba(37, 99, 235, 0.16);
    transform: translateY(-1px) scale(1.04);
  }

  &:not(:disabled):hover .el-icon {
    transform: rotate(90deg);
  }

  &:not(:disabled):active {
    transform: translateY(0) scale(0.96);
    box-shadow: 0 4px 10px rgba(37, 99, 235, 0.12);
  }

  &:disabled {
    color: #b5b5b5;
    cursor: not-allowed;
    border-color: #d0d0d0;
  }
}

.reply-textarea {
  :deep(.el-textarea__inner) {
    min-height: 40px !important;
    padding: 9px 4px;
    resize: none;
    border: none;
    box-shadow: none;
    font-size: 16px;
    line-height: 1.5;
  }

  :deep(.el-input__count) {
    display: none;
  }
}

.send-button {
  position: relative;
  overflow: hidden;
  width: 96px;
  height: 48px;
  color: #405675;
  font-size: 18px;
  font-weight: 600;
  border: none;
  border-radius: 999px;
  background: #fff;
  transition:
    transform 0.18s cubic-bezier(0.2, 0.8, 0.2, 1),
    color 0.18s ease,
    background 0.18s ease;

  &::after {
    position: absolute;
    inset: 0;
    content: '';
    background: linear-gradient(110deg, transparent 0%, rgba(37, 99, 235, 0.1) 45%, transparent 72%);
    transform: translateX(-130%);
    transition: transform 0.48s ease;
  }

  :deep(span) {
    position: relative;
    z-index: 1;
  }

  &:hover,
  &:focus {
    color: #2563eb;
    background: #f8fbff;
    transform: translateY(-1px);
  }

  &:hover::after,
  &:focus::after {
    transform: translateX(130%);
  }

  &:active {
    transform: translateY(0) scale(0.98);
  }

  &.is-disabled,
  &.is-disabled:hover,
  &.is-disabled:focus {
    color: #9aa6b8;
    background: #eef2f7;
    transform: none;
  }

  &.is-disabled::after {
    display: none;
  }
}

.ticket-closed-notice {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 16px;
  background: $color-warning-soft;
  border: 1px solid $border-color;
  border-radius: 10px;
  color: $color-warning;
  font-size: 14px;
  margin: 0 14px;
}

/* ── 移动端：交流 ── */
.mobile-conversation-section {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-height: 0;
  overflow: hidden;
}

.mobile-message-list {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 14px;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
}

.mobile-message {
  display: flex;
  max-width: 88%;
  position: relative;
  padding-bottom: 4px;

  &--customer { align-self: flex-end; flex-direction: row-reverse; }
  &--staff { align-self: flex-start; }
}

.mobile-message__bubble-wrap {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.mobile-message__meta {
  display: flex;
  gap: 6px;
  font-size: 11px;
  color: #94a0b2;
  padding: 0 4px;

  .mobile-message--customer & { flex-direction: row-reverse; }
}

.mobile-message__bubble {
  padding: 9px 12px;
  font-size: 14px;
  line-height: 1.6;
  color: #1f2937;
  white-space: pre-wrap;
  word-break: break-word;
  border: 1px solid;

  .mobile-message--staff & {
    background: #fff;
    border-color: #dce5f2;
    border-radius: 4px 14px 14px 14px;
  }

  .mobile-message--customer & {
    background: #eaf2ff;
    border-color: #c9dcff;
    border-radius: 14px 4px 14px 14px;
  }
}

.mobile-message__recalled {
  font-size: 11px;
  color: #a0aec0;
  font-style: italic;
}

.mobile-message__images {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin-top: 8px;

  .mobile-message--customer & { justify-content: flex-end; }
}

.mobile-message__img {
  width: 68px;
  height: 68px;
  padding: 0;
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid #e5eaf3;
  cursor: pointer;
  background: #f8fafc;
  transition: transform 0.18s ease;

  &:active { transform: scale(0.95); }

  :deep(.el-image) {
    width: 100%;
    height: 100%;
  }
}

.mobile-message__img--deleted {
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--el-fill-color-light, #f5f7fa);
  border-style: dashed;
  cursor: default;

  &:active { transform: none; }
}

/* 移动端底部输入 */
.mobile-reply-section {
  padding: 10px 14px 14px;
  background: #fff;
  border-top: 1px solid #eef2f7;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.mobile-draft-images {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.mobile-draft-img {
  position: relative;
  width: 44px;
  height: 44px;
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid #e5eaf3;
  padding: 0;
  cursor: pointer;
  background: #fff;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.mobile-draft-remove {
  position: absolute;
  top: -1px;
  right: -1px;
  width: 16px;
  height: 16px;
  border: none;
  background: rgba(31, 41, 55, 0.78);
  color: #fff;
  font-size: 10px;
  line-height: 1;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 0 8px 0 8px;
}

.mobile-composer-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 8px;
  border: 1px solid #d4dce8;
  border-radius: 999px;
  background: #fff;
  box-shadow: 0 6px 24px rgba(15, 23, 42, 0.06);
}

.mobile-composer-upload {
  display: flex;

  :deep(.el-upload) {
    display: inline-flex;
  }
}

.mobile-plus-btn {
  width: 38px;
  height: 38px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #ccd6e5;
  border-radius: 50%;
  background: #fff;
  color: #637083;
  cursor: pointer;
  flex-shrink: 0;
  font-size: 22px;
  font-family: inherit;
  transition: all 0.18s ease;

  &:active { transform: scale(0.95); }
}

.mobile-textarea {
  flex: 1;
  min-width: 0;
  height: 38px;
  min-height: 38px;
  max-height: 100px;
  padding: 9px 12px;
  border: none;
  border-radius: 19px;
  background: #f1f5f9;
  color: #1f2937;
  font-size: 14px;
  line-height: 1.4;
  resize: none;
  font-family: inherit;
  transition: background-color 0.18s ease;

  &:focus {
    outline: none;
    background: #e2e8f0;
  }
}

.mobile-send-btn {
  height: 38px;
  padding: 0 16px;
  border: none;
  border-radius: 19px;
  background: #165dff;
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.18s ease;
  font-family: inherit;
  flex-shrink: 0;

  &:disabled {
    background: #e2e8f0;
    color: #94a3b8;
    cursor: not-allowed;
  }

  &.is-loading {
    background: #70a0ff;
    cursor: wait;
  }
}

.mobile-closed-notice {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 12px;
  background: #fef2f2;
  border-top: 1px solid #fee2e2;
  color: #ef4444;
  font-size: 13px;
  flex-shrink: 0;
}

.preview-image {
  width: 100%;
  max-height: 70vh;
  object-fit: contain;
  display: block;
}
</style>
