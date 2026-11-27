import { readdir, readFile, stat, writeFile } from "node:fs/promises";
import path from "node:path";
import {
  brotliCompressSync,
  constants as zlibConstants,
  gzipSync,
} from "node:zlib";

const compressionExtensions = new Set([
  ".css",
  ".html",
  ".js",
  ".json",
  ".map",
  ".mjs",
  ".svg",
  ".txt",
  ".xml",
]);
const compressionThreshold = 1024;
const imageExtensions = new Set([
  ".png",
  ".jpg",
  ".jpeg",
  ".webp",
  ".gif",
  ".svg",
  ".avif",
]);
const fontExtensions = new Set([".woff", ".woff2", ".ttf", ".otf", ".eot"]);

async function collectOutputFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = await Promise.all(
    entries.map((entry) => {
      const filePath = path.join(directory, entry.name);
      return entry.isDirectory() ? collectOutputFiles(filePath) : [filePath];
    }),
  );
  return files.flat();
}

/** 三端共用静态资源分类，保持图片、字体和样式的部署路径一致。 */
export function resolveAssetFileName(assetInfo) {
  const extension = path.extname(String(assetInfo.name || "")).toLowerCase();

  if (extension === ".css") return "assets/css/[name]-[hash][extname]";
  if (imageExtensions.has(extension))
    return "assets/img/[name]-[hash][extname]";
  if (fontExtensions.has(extension))
    return "assets/fonts/[name]-[hash][extname]";
  return "assets/misc/[name]-[hash][extname]";
}

/** 为构建产物生成预压缩副本，原文件始终保留供静态服务器回退。 */
export function createPrecompressedAssetsPlugin() {
  let outputDirectory;

  return {
    name: "precompressed-assets",
    apply: "build",
    configResolved(config) {
      // 使用 Vite 最终配置，兼容 CLI --outDir、相对目录与绝对目录。
      outputDirectory = path.resolve(config.root, config.build.outDir);
    },
    async closeBundle() {
      if (
        !outputDirectory ||
        !(await stat(outputDirectory).catch(() => null))?.isDirectory()
      )
        return;

      const files = await collectOutputFiles(outputDirectory);
      await Promise.all(
        files.map(async (filePath) => {
          if (!compressionExtensions.has(path.extname(filePath).toLowerCase()))
            return;

          const buffer = await readFile(filePath);
          if (buffer.byteLength < compressionThreshold) return;

          const gzipBuffer = gzipSync(buffer, { level: 9 });
          const brotliBuffer = brotliCompressSync(buffer, {
            params: { [zlibConstants.BROTLI_PARAM_QUALITY]: 11 },
          });

          // 无收益时不写副本，避免不可压缩文件占用额外空间。
          if (gzipBuffer.byteLength < buffer.byteLength)
            await writeFile(filePath + ".gz", gzipBuffer);
          if (brotliBuffer.byteLength < buffer.byteLength)
            await writeFile(filePath + ".br", brotliBuffer);
        }),
      );
    },
  };
}
