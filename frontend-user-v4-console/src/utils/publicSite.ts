function resolvePublicSiteOrigin() {
  const configuredOrigin = String(import.meta.env.VITE_PUBLIC_SITE_URL || '')
    .trim()
    .replace(/\/+$/, '');
  if (configuredOrigin) return configuredOrigin;

  if (typeof window === 'undefined') return '';

  const { protocol, hostname, port } = window.location;
  if (hostname.startsWith('console.')) {
    return `${protocol}//www.${hostname.slice('console.'.length)}${port ? `:${port}` : ''}`;
  }

  if (hostname === '127.0.0.1' || hostname === 'localhost') {
    const publicSitePort = String(import.meta.env.VITE_WWW_DEV_PORT || '5175').trim();
    return `${protocol}//${hostname}${publicSitePort ? `:${publicSitePort}` : ''}`;
  }

  return '';
}

export function buildPublicSiteUrl(path = '/products') {
  const normalizedPath = String(path || '/products').startsWith('/') ? String(path || '/products') : `/${path}`;
  const origin = resolvePublicSiteOrigin();
  return origin ? `${origin}${normalizedPath}` : normalizedPath;
}

export function openPublicProducts() {
  const target = buildPublicSiteUrl('/products');
  if (typeof window !== 'undefined') {
    window.location.assign(target);
  }
  return target;
}
