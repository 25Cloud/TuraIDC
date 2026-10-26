/**
 * AI 助手公共类型：三端共享的唯一契约来源。
 * 字段命名与后端 /api/v2/client/ai/* 的 data 结构一一对应，改动必须同步后端 Resource。
 */

export type AiRiskLevel = 'read' | 'write' | 'dangerous'

/** 权限模式：仅可查看 / 完全权限（写操作需确认）/ 自动审批（beta，写操作直接执行） */
export type AiPermissionMode = 'read_only' | 'confirm' | 'auto_approve'

export type AiChannel = 'console' | 'site' | 'admin'

export interface AiToolDescriptor {
  name: string
  risk: AiRiskLevel
  description: string
}

export interface AiQuota {
  daily_messages: number
  daily_tokens: number
  used_messages: number
  used_tokens: number
  resets_at: string | null
}

export interface AiBootstrap {
  enabled: boolean
  channels: Record<AiChannel, boolean>
  permission_mode: AiPermissionMode
  allowed_permission_modes: AiPermissionMode[]
  auto_approve_beta: boolean
  model_label: string
  protocol: string
  quota: AiQuota
  tools: AiToolDescriptor[]
}

/** 待确认动作：后端只落 pending，前端必须带 confirm_token 回来才执行 */
export interface AiPendingAction {
  id: number
  tool: string
  risk: AiRiskLevel
  title: string
  description: string
  arguments_summary: string
  impact: string
  amount_cents: number | null
  expires_at: string
  confirm_token: string
}

export interface AiToolRun {
  name: string
  risk: AiRiskLevel
  status: 'done' | 'pending' | 'rejected' | 'failed'
  summary: string
}

export interface AiUsage {
  prompt_tokens: number
  completion_tokens: number
}

export interface AiConversation {
  id: number
  title: string
  channel: AiChannel
  permission_mode: AiPermissionMode
  message_count: number
  last_message_at: string | null
  created_at: string | null
  updated_at?: string | null
}

export interface AiMessageToolCall {
  id?: string
  name: string
  risk?: AiRiskLevel | string
  status?: string
  summary?: string
  arguments?: string
}

export interface AiMessageRow {
  id: number
  role: 'user' | 'assistant' | 'system' | 'tool'
  content: string
  reasoning?: string
  tool_calls?: AiMessageToolCall[] | null
  created_at: string | null
}

export interface AiSendPayload {
  conversation_id?: number | null
  channel?: AiChannel
  message: string
  permission_mode?: AiPermissionMode
  subject?: AiSubject | null
}

export interface AiSendResult {
  conversation_id: number
  message_id: number
  content: string
  reasoning?: string
  tool_runs: AiToolRun[]
  pending_actions: AiPendingAction[]
  permission_mode: AiPermissionMode
  usage: AiUsage
  quota: AiQuota
}

export interface AiConversationDetail {
  conversation: AiConversation
  messages: AiMessageRow[]
  pending_actions: AiPendingAction[]
}

export interface AiPage<T> {
  list: T[]
  total: number
  page: number
  page_size: number
}

/** 页面上下文：告诉助手「用户正在看什么」，只影响提示词与工具默认参数，不构成权限 */
export interface AiSubject {
  type: 'product' | 'service' | 'invoice' | 'ticket' | string
  id: number
  label?: string
}

export interface AiConfirmResult {
  action_id: number
  status: 'done' | 'failed' | 'rejected'
  summary: string
  conversation_id: number | null
}

/** 对话视图模型：浮窗与独立页共用 */
export interface AiChatMessage {
  id: string
  role: 'user' | 'assistant' | 'system'
  content: string
  /** 推理模型的思考过程；有值时折叠展示，流式期间自动展开 */
  reasoning?: string
  tool_runs: AiToolRun[]
  pending_actions: AiPendingAction[]
  created_at: string | null
  streaming?: boolean
  /** 已收到首个增量（正文或思考），用于收起「思考中」占位 */
  started?: boolean
  error?: boolean
}
