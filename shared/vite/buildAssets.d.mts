import type { Plugin } from "vite";

/** 三端共用的静态资源输出目录规则。 */
export function resolveAssetFileName(assetInfo: { name?: string }): string;

/** 按 Vite 最终输出目录生成 gzip/Brotli 副本。 */
export function createPrecompressedAssetsPlugin(): Plugin;
