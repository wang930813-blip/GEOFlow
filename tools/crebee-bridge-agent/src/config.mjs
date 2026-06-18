import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

function loadDotEnv(filePath) {
  if (!existsSync(filePath)) {
    return;
  }

  const content = readFileSync(filePath, 'utf8');
  for (const line of content.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (trimmed === '' || trimmed.startsWith('#')) {
      continue;
    }

    const index = trimmed.indexOf('=');
    if (index === -1) {
      continue;
    }

    const key = trimmed.slice(0, index).trim();
    const rawValue = trimmed.slice(index + 1).trim();
    if (process.env[key] === undefined) {
      process.env[key] = rawValue.replace(/^["']|["']$/g, '');
    }
  }
}

loadDotEnv(resolve(process.cwd(), '.env'));

function required(name) {
  const value = (process.env[name] ?? '').trim();
  if (value === '') {
    throw new Error(`Missing required env: ${name}`);
  }

  return value;
}

function integer(name, fallback) {
  const value = Number.parseInt(process.env[name] ?? `${fallback}`, 10);

  return Number.isFinite(value) && value > 0 ? value : fallback;
}

export const config = {
  geoflowBaseUrl: required('GEOFLOW_BASE_URL').replace(/\/+$/, ''),
  agentId: required('CREBEE_AGENT_ID'),
  agentSecret: required('CREBEE_AGENT_SECRET'),
  crebeeBaseUrl: (process.env.CREBEE_BASE_URL ?? 'http://127.0.0.1:3456').replace(/\/+$/, ''),
  pollIntervalMs: integer('POLL_INTERVAL_MS', 5000),
  heartbeatIntervalMs: integer('HEARTBEAT_INTERVAL_MS', 30000),
  accountSyncIntervalMs: integer('ACCOUNT_SYNC_INTERVAL_MS', 60000),
  publishEventTimeoutMs: integer('PUBLISH_EVENT_TIMEOUT_MS', 120000),
  publishRecordPollIntervalMs: integer('PUBLISH_RECORD_POLL_INTERVAL_MS', 5000),
  publishRecordPollTimeoutMs: integer('PUBLISH_RECORD_POLL_TIMEOUT_MS', 120000),
  httpTimeoutMs: integer('HTTP_TIMEOUT_MS', 30000),
  tempDir: process.env.TEMP_DIR ?? '.cache',
};
