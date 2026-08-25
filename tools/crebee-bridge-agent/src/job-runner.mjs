import { AssetCache } from './asset-cache.mjs';

export class JobRunner {
  constructor(cloudClient, crebeeClient) {
    this.cloud = cloudClient;
    this.crebee = crebeeClient;
    this.assetCache = new AssetCache(crebeeClient.config);
    this.running = false;
  }

  async tick() {
    if (this.running) {
      return;
    }

    this.running = true;
    try {
      const job = await this.cloud.nextJob();
      if (!job) {
        return;
      }

      await this.runJob(job);
    } finally {
      this.running = false;
    }
  }

  async runJob(job) {
    try {
      job = await this.assetCache.prepareJob(job);
      const taskIds = (job.tasks ?? []).map((task) => task.taskId);
      if (taskIds.length === 0) {
        const response = await this.crebee.publishBatch(job);
        await this.cloud.markAccepted(job.id, response);
        await this.cloud.markFinished(job.id, []);

        return;
      }

      const progressReady = this.deferred();
      const progressController = new AbortController();
      const finalEventsPromise = this.crebee.listenPublishProgress(
        taskIds,
        async (event) => {
          await this.cloud.recordEvents(job.id, [{
            taskId: event.taskId,
            type: event.type,
            progress: event.progress,
            message: event.message ?? '',
            raw: event,
          }]);
        },
        this.crebee.config.publishEventTimeoutMs,
        {
          onReady: () => progressReady.resolve(),
          signal: progressController.signal,
        },
      );
      finalEventsPromise.catch((error) => progressReady.reject(error));

      await progressReady.promise;

      const response = await this.crebee.publishBatch(job);
      await this.cloud.markAccepted(job.id, response);

      const finalResults = await this.firstFinalResults(finalEventsPromise, this.findRecordResults(job, []), job);
      progressController.abort();
      const recordResults = finalResults.recordResults.length > 0
        ? finalResults.recordResults
        : await this.findRecordResults(job, finalResults.finalEvents);

      await this.cloud.markFinished(
        job.id,
        this.buildFinalItems(job, finalResults.finalEvents, recordResults, response),
      );
    } catch (error) {
      await this.cloud.markFailed(job.id, error.message, {
        name: error.name,
        stack: error.stack,
      });
    }
  }

  findResult(response, task) {
    const results = Array.isArray(response?.results)
      ? response.results
      : (Array.isArray(response?.raw?.results) ? response.raw.results : []);

    return results.find((result) => {
      return result.taskId === task.taskId
        || (result.accountId === task.accountId && result.platform === task.platform);
    });
  }

  async firstFinalResults(eventsPromise, recordsPromise, job) {
    const expectedCount = (job.tasks ?? []).length;
    const isComplete = (items) => expectedCount === 0 || items.length === expectedCount;
    const taggedEvents = eventsPromise.then((events) => ({ type: 'events', events }));
    const taggedRecords = recordsPromise.then((records) => ({ type: 'records', records }));
    const first = await Promise.race([taggedEvents, taggedRecords]);

    if (first.type === 'records') {
      if (isComplete(first.records)) {
        return { finalEvents: [], recordResults: first.records };
      }

      return { finalEvents: await eventsPromise, recordResults: first.records };
    }

    if (isComplete(first.events)) {
      return { finalEvents: first.events, recordResults: [] };
    }

    return { finalEvents: first.events, recordResults: await recordsPromise };
  }

  finalStatus(result) {
    if (!result) {
      return 'failed';
    }
    if (result.type === 'success' || result.status === 'success' || result.publish_status === 'success') {
      return 'success';
    }

    return 'failed';
  }

  async findRecordResults(job, finalEvents) {
    const missingTasks = (job.tasks ?? []).filter((task) => {
      return !finalEvents.some((event) => event.taskId === task.taskId);
    });
    if (missingTasks.length === 0) {
      return [];
    }

    const timeoutMs = this.crebee.config.publishRecordPollTimeoutMs ?? 0;
    const intervalMs = this.crebee.config.publishRecordPollIntervalMs ?? 30000;
    const deadline = Date.now() + timeoutMs;
    const found = new Map();

    do {
      for (const task of missingTasks) {
        if (found.has(task.taskId)) {
          continue;
        }

        const records = await this.crebee.findPublishRecords(task, job);
        const record = this.bestRecord(records);
        if (record) {
          found.set(task.taskId, record);
        }
      }

      if (found.size === missingTasks.length || Date.now() >= deadline) {
        break;
      }

      await this.sleep(intervalMs);
    } while (Date.now() < deadline);

    return [...found.entries()].map(([taskId, record]) => ({ taskId, record }));
  }

  buildFinalItems(job, finalEvents, recordResults, response) {
    return (job.tasks ?? []).map((task) => {
      const event = finalEvents.find((item) => item.taskId === task.taskId);
      const recordResult = recordResults.find((item) => item.taskId === task.taskId)?.record;
      const result = event ?? recordResult ?? this.findResult(response, task);
      const status = this.finalStatus(result);
      const fromRecord = Boolean(recordResult && !event);
      const urlSource = recordResult ?? result;

      return {
        taskId: task.taskId,
        status,
        message: result?.error ?? result?.message ?? (fromRecord ? '已通过发布记录同步最终结果' : (event ? '' : '未收到最终进度事件，已按提交结果兜底')),
        published_url: this.publishedUrl(urlSource),
        raw: result ?? {},
      };
    });
  }

  bestRecord(records) {
    if (!Array.isArray(records) || records.length === 0) {
      return null;
    }

    return records.find((record) => this.finalStatus(record) === 'success') ?? records[0];
  }

  publishedUrl(result) {
    return String(
      result?.published_url
      ?? result?.publish_url
      ?? result?.url
      ?? result?.link
      ?? result?.article_url
      ?? ''
    );
  }

  sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  deferred() {
    let resolve;
    let reject;
    const promise = new Promise((promiseResolve, promiseReject) => {
      resolve = promiseResolve;
      reject = promiseReject;
    });

    return { promise, resolve, reject };
  }
}
