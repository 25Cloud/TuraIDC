import axios, { type AxiosRequestConfig, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { getNetworkProfile, waitFor } from '../networkHints.ts'

const SAFE_METHODS = new Set(['get', 'head', 'options'])
const WRITE_METHODS = new Set(['post', 'put', 'patch', 'delete'])
const RETRYABLE_STATUS_CODES = new Set([408, 425, 429, 500, 502, 503, 504])
const RETRIABLE_ERROR_CODES = new Set(['ECONNABORTED', 'ETIMEDOUT', 'ERR_NETWORK'])

const pendingSafeRequests = new Map<string, Promise<AxiosResponse>>()

export function isSafeRequest(method = '') {
  return SAFE_METHODS.has(String(method).toLowerCase())
}

export function isWriteRequest(method = '') {
  return WRITE_METHODS.has(String(method).toLowerCase())
}

export function serializeKeyPart(value: unknown): string {
  if (value === null || value === undefined) {
    return ''
  }

  if (value instanceof Date) {
    return value.toISOString()
  }

  if (Array.isArray(value)) {
    return `[${value.map((item) => serializeKeyPart(item)).join(',')}]`
  }

  if (typeof value === 'object') {
    return `{${Object.keys(value as Record<string, unknown>).sort().map((key) => `${key}:${serializeKeyPart((value as Record<string, unknown>)[key])}`).join(',')}}`
  }

  return String(value)
}

export function buildSafeRequestKey(config: AxiosRequestConfig & Record<string, unknown>) {
  return [
    String(config.method || 'get').toLowerCase(),
    String(config.baseURL || ''),
    String(config.url || ''),
    serializeKeyPart(config.params),
    serializeKeyPart(config.data),
    serializeKeyPart(config.responseType),
    serializeKeyPart(config.timeout),
    serializeKeyPart(config.silentError),
  ].join('|')
}

export function createRequestId() {
  return globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`
}

export function resolveSafeRetryLimit(explicitCount?: number) {
  if (Number.isFinite(Number(explicitCount))) {
    return Number(explicitCount)
  }

  return getNetworkProfile().isWeakConnection ? 2 : 1
}

export function resolveSafeRetryDelay(error: any, attempt: number) {
  const retryAfter = Number(error?.response?.headers?.['retry-after'] || 0)
  if (retryAfter > 0) {
    return Math.min(retryAfter * 1000, 2500)
  }

  const baseDelay = getNetworkProfile().isWeakConnection ? 450 : 300
  const jitter = Math.round(Math.random() * 150)
  return Math.min(baseDelay * (2 ** Math.max(0, attempt - 1)) + jitter, 2500)
}

export function isRetryableNetworkError(error: any) {
  if (axios.isCancel(error) || error?.code === 'ERR_CANCELED' || error?.name === 'CanceledError') {
    return false
  }

  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    return false
  }

  if (error?.response) {
    return RETRYABLE_STATUS_CODES.has(Number(error.response.status || 0))
  }

  if (RETRIABLE_ERROR_CODES.has(String(error?.code || ''))) {
    return true
  }

  const message = String(error?.message || '').toLowerCase()
  return message.includes('timeout') || message.includes('network error') || message.includes('failed to fetch')
}

export function attachSafeRequestDedupe(config: AxiosRequestConfig & Record<string, any>, defaultsAdapter: AxiosRequestConfig['adapter']) {
  const adapter = axios.getAdapter(config.adapter || defaultsAdapter)
  const requestKey = config.__safeRequestKey

  config.adapter = (innerConfig: InternalAxiosRequestConfig & { __skipSafeDedupe?: boolean }) => {
    if (!requestKey || innerConfig.__skipSafeDedupe) {
      return adapter(innerConfig)
    }

    if (pendingSafeRequests.has(requestKey)) {
      return pendingSafeRequests.get(requestKey) as Promise<any>
    }

    const responsePromise = Promise.resolve(adapter(innerConfig))
      .finally(() => {
        if (pendingSafeRequests.get(requestKey) === responsePromise) {
          pendingSafeRequests.delete(requestKey)
        }
      })

    pendingSafeRequests.set(requestKey, responsePromise as Promise<AxiosResponse>)
    return responsePromise
  }
}

export async function retrySafeRequest(requester: (_config: AxiosRequestConfig) => Promise<any>, error: any) {
  const config = error?.config

  if (!config || !config.__safeRequest || config.retrySafeRequest === false) {
    throw error
  }

  if (config.signal?.aborted || !isRetryableNetworkError(error)) {
    throw error
  }

  const nextAttempt = Number(config.__safeRetryCount || 0) + 1
  if (nextAttempt > resolveSafeRetryLimit(config.safeRetryCount)) {
    throw error
  }

  config.__safeRetryCount = nextAttempt
  config.__skipSafeDedupe = false

  await waitFor(resolveSafeRetryDelay(error, nextAttempt))
  return requester(config)
}
