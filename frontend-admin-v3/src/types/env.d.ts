interface ImportMetaEnv {
  readonly VITE_API_BASE_URL: string;
  readonly VITE_APP_TITLE: string;
  readonly VITE_PUBLIC_SITE_URL: string;
  readonly VITE_ADMIN_ASSET_BASE_URL: string;
  readonly VITE_CDN_ASSET_HOST: string;
  readonly VITE_ASSET_BASE_URL: string;
  readonly VITE_BASE_URL: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
