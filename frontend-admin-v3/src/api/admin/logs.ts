import { request } from '@/utils/request';

import type { LogCleanupPayload, LogListParams, PaginatedList } from './types';

type LogChannel = 'system' | 'runtime' | 'admin-logins' | 'api' | 'sms' | 'email' | 'tasks' | 'gateway' | 'activity';

interface V2LogPage {
  list?: Record<string, unknown>[];
  total?: number;
  page?: number;
  page_size?: number;
  summary?: Record<string, unknown>;
}

interface V2LogDetail {
  id?: string | number;
  channel?: string;
  source?: string;
  fields?: Record<string, unknown>;
  message?: string;
  context?: Record<string, unknown>;
  created_at?: string;
}

function toV2Params(params: LogListParams = {}) {
  const { include_summary = false, ...rest } = params;

  return {
    ...rest,
    include_summary: include_summary ? 1 : 0,
  };
}

function toV2SummaryParams(params: LogListParams = {}) {
  const { page: _page, page_size: _pageSize, include_summary: _includeSummary, ...rest } = params;
  return rest;
}

function listChannel(channel: LogChannel, params: LogListParams): Promise<PaginatedList> {
  return request.get<V2LogPage>({ url: `/v2/admin/logs/${channel}`, params: toV2Params(params) }).then((payload) => ({
    list: (payload.list || []).map(toLegacyLogRow),
    total: Number(payload.total || 0),
    page: Number(payload.page || params.page || 1),
    page_size: Number(payload.page_size || params.page_size || 20),
    summary: payload.summary || {},
  }));
}

function summaryChannel(channel: LogChannel, params: LogListParams): Promise<Record<string, unknown>> {
  return request.get<Record<string, unknown>>({
    url: `/v2/admin/log-summaries/${channel}`,
    params: toV2SummaryParams(params),
  });
}

function detailChannel(channel: LogChannel, id: number | string): Promise<Record<string, unknown>> {
  return request
    .get<{ log?: V2LogDetail }>({ url: `/v2/admin/logs/${channel}/${encodeURIComponent(String(id))}` })
    .then((payload) => toLegacyLogDetail(payload.log || {}));
}

function toLegacyLogRow(row: Record<string, unknown>): Record<string, unknown> {
  const message = String(row.message_excerpt || row.error_excerpt || '');
  const context = String(row.context_excerpt || '');

  return {
    ...row,
    message,
    content: message,
    description: message,
    error_msg: row.error_excerpt || '',
    detail: context,
    context,
  };
}

function toLegacyLogDetail(log: V2LogDetail): Record<string, unknown> {
  const fields = log.fields || {};
  const context = log.context || {};

  return {
    ...fields,
    id: fields.id || log.id,
    channel: log.channel,
    source: log.source || fields.source,
    message: log.message || '',
    content: log.message || '',
    description: log.message || '',
    detail: context,
    context,
    params: context.params,
    request_data: context.request_data,
    response_data: context.response_data,
    request_meta: context.request_meta,
    response_meta: context.response_meta,
    summary: context.summary || fields.summary,
    raw: log.message || '',
    created_at: log.created_at || fields.created_at,
  };
}

export const logsApi = {
  sms: (params: LogListParams) => listChannel('sms', params),
  smsSummary: (params: LogListParams) => summaryChannel('sms', params),
  email: (params: LogListParams) => listChannel('email', params),
  emailSummary: (params: LogListParams) => summaryChannel('email', params),
  api: (params: LogListParams) => listChannel('api', params),
  tasks: (params: LogListParams) => listChannel('tasks', params),
  tasksSummary: (params: LogListParams) => summaryChannel('tasks', params),
  system: (params: LogListParams) => listChannel('system', params),
  systemSummary: (params: LogListParams) => summaryChannel('system', params),
  runtime: (params: LogListParams) => listChannel('runtime', params),
  runtimeSummary: (params: LogListParams) => summaryChannel('runtime', params),
  adminLogins: (params: LogListParams) => listChannel('admin-logins', params),
  gateway: (params: LogListParams) => listChannel('gateway', params),
  activity: (params: LogListParams) => listChannel('activity', params),
  detail: (channel: LogChannel, id: number | string) => detailChannel(channel, id),
  cleanupOverview: () => request.get<Record<string, unknown>>({ url: '/v2/admin/log-cleanups/overview' }),
  cleanup: (data: LogCleanupPayload) =>
    request
      .post<{ detail?: { cleanup?: Record<string, unknown> } } | Record<string, unknown>>({
        url: '/v2/admin/log-cleanups',
        data,
      })
      .then((response) => {
        const detail = (response as { detail?: { cleanup?: Record<string, unknown> } }).detail;
        return detail?.cleanup || response;
      }),
};
