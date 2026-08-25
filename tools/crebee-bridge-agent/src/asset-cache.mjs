import { createHash } from 'node:crypto';
import { createWriteStream, existsSync } from 'node:fs';
import { mkdir, readdir, rm, stat } from 'node:fs/promises';
import { basename, extname, join } from 'node:path';
import { Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';

export class AssetCache {
  constructor(config) {
    this.config = config;
  }

  async prepareJob(job) {
    await this.cleanupExpired();

    if (job?.contentType !== 'video') {
      return job;
    }

    const assets = Array.isArray(job.assets) ? job.assets : [];
    const videoAsset = assets.find((asset) => asset?.key === 'video' || asset?.type === 'video');
    const coverAsset = assets.find((asset) => asset?.key === 'cover' || asset?.type === 'image');

    const videoPath = videoAsset?.url ? await this.download(job.id, 'video', videoAsset.url) : '';
    const coverPath = coverAsset?.url ? await this.download(job.id, 'cover', coverAsset.url) : '';

    if (!videoPath) {
      throw new Error('Video publish job is missing a downloadable video asset');
    }
    if (!coverPath) {
      throw new Error('Video publish job is missing a downloadable cover asset');
    }

    return this.localizeVideoPayload(job, videoPath, coverPath);
  }

  async download(jobId, key, url) {
    const normalizedUrl = String(url ?? '').trim();
    if (!normalizedUrl) {
      return '';
    }

    await mkdir(this.config.assetCacheDir, { recursive: true });
    const extension = this.extensionFromUrl(normalizedUrl, key);
    const hash = createHash('sha1').update(normalizedUrl).digest('hex').slice(0, 16);
    const target = join(this.config.assetCacheDir, `${jobId}-${key}-${hash}${extension}`);

    if (existsSync(target)) {
      return target;
    }

    const response = await fetch(normalizedUrl);
    if (!response.ok || !response.body) {
      throw new Error(`Asset download failed: ${response.status} ${normalizedUrl}`);
    }

    await pipeline(Readable.fromWeb(response.body), createWriteStream(target));

    return target;
  }

  localizeVideoPayload(job, videoPath, coverPath) {
    const commonForm = {
      ...(job.commonForm ?? {}),
      videoPath,
      coverPath,
    };

    const tasks = (job.tasks ?? []).map((task) => {
      const params = {
        ...(task.params ?? {}),
        videoPath,
        coverPath,
      };
      if (Object.prototype.hasOwnProperty.call(params, 'verticalCoverPath')) {
        params.verticalCoverPath = coverPath;
      }

      return {
        ...task,
        params,
      };
    });

    return {
      ...job,
      commonForm,
      tasks,
    };
  }

  async cleanupExpired() {
    const maxAgeMs = Math.max(1, this.config.assetCacheMaxAgeHours ?? 72) * 3600 * 1000;
    try {
      await mkdir(this.config.assetCacheDir, { recursive: true });
      const files = await readdir(this.config.assetCacheDir);
      const now = Date.now();

      await Promise.all(files.map(async (file) => {
        const path = join(this.config.assetCacheDir, file);
        const fileStat = await stat(path);
        if (fileStat.isFile() && now - fileStat.mtimeMs > maxAgeMs) {
          await rm(path, { force: true });
        }
      }));
    } catch {
      // Cache cleanup should not block publishing.
    }
  }

  extensionFromUrl(url, key) {
    try {
      const parsed = new URL(url);
      const extension = extname(basename(parsed.pathname)).toLowerCase();
      if (extension) {
        return extension;
      }
    } catch {
      // Fall through to key-based defaults.
    }

    return key === 'video' ? '.mp4' : '.jpg';
  }
}
