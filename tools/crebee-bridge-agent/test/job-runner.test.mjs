import assert from 'node:assert/strict';
import test from 'node:test';

import { JobRunner } from '../src/job-runner.mjs';

function jobFixture(overrides = {}) {
  return {
    id: 7,
    contentType: 'article',
    commonForm: { title: 'Test Article', content: '<p>body</p>', covers: [] },
    tasks: [
      {
        taskId: 'bilibili-task-001',
        accountId: 'bilibili-account-001',
        platform: 'bilibili',
        contentType: 'article',
        params: { title: 'Test Article' },
      },
    ],
    ...overrides,
  };
}

function cloudFixture(calls = []) {
  return {
    async markAccepted(jobId, raw) {
      calls.push(['accepted', jobId, raw]);
    },
    async recordEvents(jobId, events) {
      calls.push(['events', jobId, events]);
    },
    async markFinished(jobId, items) {
      calls.push(['finished', jobId, items]);
    },
    async markFailed(jobId, message) {
      calls.push(['failed', jobId, message]);
    },
  };
}

test('job runner falls back to publish records when SSE has no final events', async () => {
  const calls = [];
  const cloud = cloudFixture(calls);
  const crebee = {
    config: { publishEventTimeoutMs: 10, publishRecordPollIntervalMs: 1, publishRecordPollTimeoutMs: 10 },
    async publishBatch() {
      return {
        total: 1,
        success: 1,
        failed: 0,
        results: [{ accountId: 'bilibili-account-001', platform: 'bilibili', status: 'success' }],
      };
    },
    async listenPublishProgress(taskIds, onEvent, timeoutMs, options = {}) {
      options.onReady?.();

      return [];
    },
    async findPublishRecords(task) {
      assert.equal(task.platform, 'bilibili');

      return [
        {
          account_id: 'bilibili-account-001',
          platform: 'bilibili',
          title: 'Test Article',
          publish_status: 'success',
          published_url: 'https://www.bilibili.com/read/cv123',
          published_at: '2026-06-18T13:25:00+08:00',
        },
      ];
    },
  };
  const runner = new JobRunner(cloud, crebee);

  await runner.runJob(jobFixture());

  assert.equal(calls[0][0], 'accepted');
  assert.equal(calls[1][0], 'finished');
  assert.equal(calls[1][1], 7);
  assert.equal(calls[1][2][0].taskId, 'bilibili-task-001');
  assert.equal(calls[1][2][0].status, 'success');
  assert.equal(calls[1][2][0].published_url, 'https://www.bilibili.com/read/cv123');
  assert.equal(calls[1][2][0].raw.publish_status, 'success');
});

test('job runner starts progress listener before publishing', async () => {
  const order = [];
  let resolveProgress;
  const progress = new Promise((resolve) => {
    resolveProgress = resolve;
  });
  const cloud = {
    async markAccepted() {
      order.push('accepted');
    },
    async recordEvents() {},
    async markFinished() {
      order.push('finished');
    },
    async markFailed() {
      order.push('failed');
    },
  };
  const crebee = {
    config: { publishEventTimeoutMs: 100, publishRecordPollIntervalMs: 1, publishRecordPollTimeoutMs: 10 },
    async publishBatch() {
      order.push('publish');
      resolveProgress([
        { taskId: 'bilibili-task-001', type: 'success', progress: 100, message: 'ok' },
      ]);

      return { raw: { results: [{ accountId: 'bilibili-account-001', platform: 'bilibili', status: 'success' }] } };
    },
    async listenPublishProgress(taskIds, onEvent, timeoutMs, options = {}) {
      assert.deepEqual(taskIds, ['bilibili-task-001']);
      order.push('listen');
      options.onReady?.();

      return progress;
    },
    async findPublishRecords() {
      return [];
    },
  };
  const runner = new JobRunner(cloud, crebee);

  await runner.runJob(jobFixture());

  assert.deepEqual(order.slice(0, 2), ['listen', 'publish']);
  assert.equal(order.includes('failed'), false);
});

test('job runner does not wait for SSE timeout when publish records have final result', async () => {
  let resolveProgress;
  let finished = false;
  const progress = new Promise((resolve) => {
    resolveProgress = resolve;
  });
  const cloud = {
    async markAccepted() {},
    async recordEvents() {},
    async markFinished() {
      finished = true;
    },
    async markFailed() {},
  };
  const crebee = {
    config: { publishEventTimeoutMs: 5000, publishRecordPollIntervalMs: 1, publishRecordPollTimeoutMs: 50 },
    async publishBatch() {
      return { raw: { results: [{ accountId: 'bilibili-account-001', platform: 'bilibili', status: 'success' }] } };
    },
    async listenPublishProgress(taskIds, onEvent, timeoutMs, options = {}) {
      options.onReady?.();

      return progress;
    },
    async findPublishRecords() {
      return [
        {
          publish_account_id: 'bilibili-account-001',
          publish_platform: 'bilibili',
          publish_title: 'Test Article',
          publish_status: 'success',
          publish_url: 'https://www.bilibili.com/read/cv50674350',
        },
      ];
    },
  };
  const runner = new JobRunner(cloud, crebee);
  const run = runner.runJob(jobFixture());

  try {
    const finishedBeforeSse = await Promise.race([
      new Promise((resolve) => {
        const startedAt = Date.now();
        const timer = setInterval(() => {
          if (finished || Date.now() - startedAt > 100) {
            clearInterval(timer);
            resolve(finished);
          }
        }, 5);
      }),
      new Promise((resolve) => setTimeout(() => resolve(false), 120)),
    ]);

    assert.equal(finishedBeforeSse, true);
  } finally {
    resolveProgress([]);
    await run;
  }
});

test('job runner uses nested raw publish results as final fallback', async () => {
  const calls = [];
  const cloud = cloudFixture(calls);
  const crebee = {
    config: { publishEventTimeoutMs: 1, publishRecordPollIntervalMs: 1, publishRecordPollTimeoutMs: 1 },
    async publishBatch() {
      return {
        code: 0,
        raw: {
          total: 1,
          success: 1,
          failed: 0,
          results: [
            { accountId: 'bilibili-account-001', platform: 'bilibili', status: 'success' },
          ],
        },
        message: 'success',
      };
    },
    async listenPublishProgress(taskIds, onEvent, timeoutMs, options = {}) {
      options.onReady?.();

      return [];
    },
    async findPublishRecords() {
      return [];
    },
  };
  const runner = new JobRunner(cloud, crebee);

  await runner.runJob(jobFixture());

  assert.equal(calls[1][0], 'finished');
  assert.equal(calls[1][2][0].status, 'success');
  assert.equal(calls[1][2][0].raw.status, 'success');
});

test('job runner publishes empty task list without waiting for progress listener', async () => {
  const calls = [];
  const cloud = cloudFixture(calls);
  const crebee = {
    config: { publishEventTimeoutMs: 5000, publishRecordPollIntervalMs: 1, publishRecordPollTimeoutMs: 1 },
    async publishBatch(job) {
      assert.deepEqual(job.tasks, []);

      return { raw: { results: [] } };
    },
    async listenPublishProgress() {
      throw new Error('progress listener should not run without task ids');
    },
    async findPublishRecords() {
      return [];
    },
  };
  const runner = new JobRunner(cloud, crebee);

  await runner.runJob(jobFixture({ tasks: [] }));

  assert.equal(calls[0][0], 'accepted');
  assert.deepEqual(calls[1], ['finished', 7, []]);
});
