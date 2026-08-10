const PHONE_REGEX = /^1[3-9]\d{9}$/;
const EMAIL_REGEX = /^[^\s@]+@[^\s@][^\s.@]*\.[^\s@]+$/;

export type AccountType = 'phone' | 'email';

export function normalizeAccountInput(value = '') {
  return String(value || '').trim();
}

export function detectAccountType(value = ''): AccountType | null {
  const account = normalizeAccountInput(value);
  if (!account) {
    return null;
  }

  if (PHONE_REGEX.test(account)) {
    return 'phone';
  }

  if (EMAIL_REGEX.test(account.toLowerCase())) {
    return 'email';
  }

  return null;
}

export function normalizeAccountValue(value = '') {
  const account = normalizeAccountInput(value);
  return detectAccountType(account) === 'email' ? account.toLowerCase() : account;
}

export function buildAccountPayload(value = '') {
  const accountType = detectAccountType(value);
  const account = normalizeAccountValue(value);

  if (!accountType || !account) {
    return null;
  }

  return accountType === 'phone' ? { accountType, account, phone: account } : { accountType, account, email: account };
}
