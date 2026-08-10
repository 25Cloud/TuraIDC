import { createSessionDriver, initSessionActivityTracking } from '@ewyfinance/shared/runtime';

const driver = createSessionDriver({
  tokenKey: 'admin_token',
  lastActiveKey: 'admin_last_active_at',
});

export function getAdminToken() {
  return driver.getToken();
}

export function setAdminToken(token: string) {
  driver.setToken(token);
}

export function removeAdminToken() {
  driver.removeToken();
}

export function isAdminLoggedIn() {
  return driver.isLoggedIn();
}

export function isAdminSessionExpired() {
  return driver.isSessionExpired();
}

export function initAdminSessionActivityTracking() {
  initSessionActivityTracking('admin', () => driver.touchSessionActivity());
}
