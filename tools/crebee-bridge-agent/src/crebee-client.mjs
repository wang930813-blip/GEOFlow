export class CrebeeClient {
  constructor(config) {
    this.config = config;
    this.token = '';
    this.tokenExpiresAt = 0;
  }

  async status() {
    try {
      await this.getToken();
      return 'online';
    } catch (error) {
      return 'crebee_unavailable';
    }
  }

  async getAccounts() {
    return this.request('/galic/v1/account/getAll', {});
  }

  async publishBatch(job) {
    return this.request('/galic/v1/platform/publish/batch', {
      contentType: job.contentType,
      commonForm: job.commonForm ?? {},
      tasks: job.tasks ?? [],
    });
  }

  async findPublishRecords(task, job = {}) {
    const nowSeconds = Math.floor(Date.now() / 1000);
    const response = await this.request('/galic/v1/publish-record/get-global-publish-record', {
      account: {
        account_id: task.accountId,
        account_platform: task.platform,
      },
      startTime: this.recordStartTime(job, nowSeconds),
      endTime: nowSeconds + 300,
    });
    const records = Array.isArray(response)
      ? response
      : (Array.isArray(response?.raw?.items)
        ? response.raw.items
        : (Array.isArray(response?.items)
          ? response.items
          : (Array.isArray(response?.data) ? response.data : [])));

    return records.filter((record) => this.matchesPublishRecord(record, task, job));
  }

  async listenPublishProgress(taskIds, onEvent, timeoutMs, options = {}) {
    const token = await this.getToken();
    const expected = new Set(taskIds);
    if (expected.size === 0) {
      return [];
    }

    const finalEvents = new Map();
    const controller = new AbortController();
    const externalSignal = options.signal;
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    const abortFromExternalSignal = () => controller.abort();
    if (externalSignal?.aborted) {
      controller.abort();
    } else {
      externalSignal?.addEventListener('abort', abortFromExternalSignal, { once: true });
    }

    try {
      const response = await fetch(`${this.config.crebeeBaseUrl}/galic/v1/sse`, {
        method: 'GET',
        headers: {
          'Accept': 'text/event-stream',
          'Authorization': `Bearer ${token}`,
        },
        signal: controller.signal,
      });

      if (!response.ok || !response.body) {
        throw new Error(`CreBee SSE failed: ${response.status}`);
      }
      options.onReady?.();

      const decoder = new TextDecoder();
      let buffer = '';
      for await (const chunk of response.body) {
        buffer += decoder.decode(chunk, { stream: true });
        const blocks = buffer.split(/\r?\n\r?\n/);
        buffer = blocks.pop() ?? '';

        for (const block of blocks) {
          const event = this.parseSseBlock(block);
          if (!event || event.channel !== 'platform/publish/progress') {
            continue;
          }

          const taskId = String(event.data?.taskId ?? '');
          if (!expected.has(taskId)) {
            continue;
          }

          await onEvent(event.data);
          if (event.data?.type === 'success' || event.data?.type === 'error' || event.data?.type === 'taskCancelled') {
            finalEvents.set(taskId, event.data);
          }
          if (finalEvents.size === expected.size) {
            controller.abort();
            break;
          }
        }

        if (finalEvents.size === expected.size) {
          break;
        }
      }
    } catch (error) {
      if (error.name !== 'AbortError') {
        throw error;
      }
    } finally {
      clearTimeout(timer);
      externalSignal?.removeEventListener('abort', abortFromExternalSignal);
    }

    return [...finalEvents.values()];
  }

  parseSseBlock(block) {
    const lines = block.split(/\r?\n/);
    let eventName = '';
    const dataLines = [];

    for (const line of lines) {
      if (line.startsWith('event:')) {
        eventName = line.slice(6).trim();
      }
      if (line.startsWith('data:')) {
        dataLines.push(line.slice(5).trim());
      }
    }

    if (dataLines.length === 0) {
      return null;
    }

    const rawData = dataLines.join('\n');
    const parsed = JSON.parse(rawData);

    return {
      channel: parsed.channel ?? eventName,
      data: parsed.data ?? parsed,
    };
  }

  async getToken() {
    const now = Date.now();
    if (this.token !== '' && this.tokenExpiresAt > now + 60000) {
      return this.token;
    }

    return this.withTimeout(async (signal) => {
      const response = await fetch(`${this.config.crebeeBaseUrl}/galic/v1/auth/token`, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
        body: '{}',
        signal,
      });

      const text = await response.text();
      const json = text ? JSON.parse(text) : {};
      if (!response.ok || !json.token) {
        throw new Error(`CreBee token failed: ${response.status} ${text}`);
      }

      this.token = json.token;
      this.tokenExpiresAt = json.expiresAt ? Date.parse(json.expiresAt) : now + 3600_000;

      return this.token;
    });
  }

  async request(path, body) {
    const token = await this.getToken();
    return this.withTimeout(async (signal) => {
      const response = await fetch(`${this.config.crebeeBaseUrl}${path}`, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(body),
        signal,
      });

      const text = await response.text();
      const json = text ? JSON.parse(text) : {};
      if (response.status === 401) {
        this.token = '';
      }
      if (!response.ok) {
        throw new Error(`CreBee API failed: ${response.status} ${text}`);
      }

      return json;
    });
  }

  recordStartTime(job, nowSeconds) {
    const candidates = [
      job.submittedAt,
      job.submitted_at,
      job.createdAt,
      job.created_at,
    ];
    for (const value of candidates) {
      const timestamp = Date.parse(value ?? '');
      if (Number.isFinite(timestamp)) {
        return Math.max(0, Math.floor(timestamp / 1000) - 3600);
      }
    }

    return nowSeconds - 86400;
  }

  matchesPublishRecord(record, task, job) {
    const accountId = String(record.account_id ?? record.accountId ?? record.publish_account_id ?? record.account?.account_id ?? '');
    const platform = String(record.platform ?? record.account_platform ?? record.publish_platform ?? record.account?.account_platform ?? '');
    if (accountId !== String(task.accountId) || platform !== String(task.platform)) {
      return false;
    }

    const expectedTitle = String(task.params?.title ?? job.commonForm?.title ?? '').trim();
    const recordTitle = String(record.title ?? record.article_title ?? record.publish_title ?? record.name ?? '').trim();
    if (expectedTitle === '' || recordTitle === '') {
      return true;
    }

    return recordTitle.includes(expectedTitle) || expectedTitle.includes(recordTitle);
  }

  async withTimeout(callback) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.config.httpTimeoutMs);
    try {
      return await callback(controller.signal);
    } finally {
      clearTimeout(timer);
    }
  }
}
