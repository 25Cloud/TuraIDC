import {
  getClientToken,
  setClientToken,
  removeClientToken,
  isClientLoggedIn,
  isClientSessionExpired,
  initClientSessionActivityTracking,
  touchClientSessionActivity,
} from '@/app/runtime/session'

export function getToken() {
  return getClientToken()
}

export function setToken(token) {
  setClientToken(token)
}

export function removeToken() {
  removeClientToken()
}

export function isLoggedIn() {
  return isClientLoggedIn()
}

export function isSessionExpired() {
  return isClientSessionExpired()
}

export function getUserType() {
  return 'client'
}

export function touchSessionActivity() {
  touchClientSessionActivity()
}

export function initSessionActivityTracking() {
  initClientSessionActivityTracking()
}
