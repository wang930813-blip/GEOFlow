import assert from 'node:assert/strict';
import test from 'node:test';

import { CrebeeClient } from '../src/crebee-client.mjs';

test('matches CreBee publish records that use publish_* field names', () => {
  const client = new CrebeeClient({});

  assert.equal(client.matchesPublishRecord({
    publish_account_id: 'douyin-account-001',
    publish_platform: 'douyin',
    publish_title: '测试文章',
    publish_status: 'success',
    publish_url: 'https://www.iesdouyin.com/share/video/123',
  }, {
    accountId: 'douyin-account-001',
    platform: 'douyin',
    params: { title: '测试文章' },
  }, {
    commonForm: { title: '测试文章' },
  }), true);
});

test('calls progress listener ready callback after SSE response is open', async () => {
  const originalFetch = globalThis.fetch;
  let ready = false;
  globalThis.fetch = async (url) => {
    if (String(url).endsWith('/galic/v1/auth/token')) {
      return new Response(JSON.stringify({
        token: 'test-token',
        expiresAt: new Date(Date.now() + 3600_000).toISOString(),
      }), { status: 200 });
    }

    return new Response(new ReadableStream({
      start(controller) {
        controller.close();
      },
    }), {
      status: 200,
      headers: { 'content-type': 'text/event-stream' },
    });
  };

  try {
    const client = new CrebeeClient({ crebeeBaseUrl: 'http://127.0.0.1:3456' });
    await client.listenPublishProgress(['task-001'], async () => {}, 10, {
      onReady: () => {
        ready = true;
      },
    });

    assert.equal(ready, true);
  } finally {
    globalThis.fetch = originalFetch;
  }
});
