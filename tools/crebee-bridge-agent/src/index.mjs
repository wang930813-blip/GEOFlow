import { config } from './config.mjs';
import { CloudClient } from './cloud-client.mjs';
import { CrebeeClient } from './crebee-client.mjs';
import { JobRunner } from './job-runner.mjs';

const cloud = new CloudClient(config);
const crebee = new CrebeeClient(config);
const runner = new JobRunner(cloud, crebee);

function log(message, extra = {}) {
  const suffix = Object.keys(extra).length > 0 ? ` ${JSON.stringify(extra)}` : '';
  console.log(`[${new Date().toISOString()}] ${message}${suffix}`);
}

async function safeLoop(name, callback) {
  try {
    await callback();
  } catch (error) {
    log(`${name} failed`, { message: error.message });
  }
}

async function heartbeat() {
  const status = await crebee.status();
  await cloud.heartbeat(status, {
    crebeeBaseUrl: config.crebeeBaseUrl,
  });
  log('heartbeat sent', { status });
}

async function syncAccounts() {
  const accounts = await crebee.getAccounts();
  const normalized = normalizeAccounts(accounts);
  await cloud.syncAccounts(normalized);
  log('accounts synced', { count: normalized.length });
}

function normalizeAccounts(response) {
  const list = Array.isArray(response)
    ? response
    : (Array.isArray(response?.raw?.items)
      ? response.raw.items
      : (Array.isArray(response?.data) ? response.data : (Array.isArray(response?.accounts) ? response.accounts : [])));

  return list.map((account) => ({
    account_id: String(account.account_id ?? account.accountID ?? account.id ?? ''),
    account_platform: String(account.account_platform ?? account.appAlias ?? account.platform ?? ''),
    nickname: String(account.nickname ?? account.name ?? account.account_name ?? account.account_alias ?? ''),
    avatar: String(account.avatar ?? account.avatar_url ?? account.account_avatar ?? ''),
    raw: account,
  })).filter((account) => account.account_id !== '' && account.account_platform !== '');
}

log('CreBee Bridge Agent starting', {
  geoflowBaseUrl: config.geoflowBaseUrl,
  crebeeBaseUrl: config.crebeeBaseUrl,
  agentId: config.agentId,
});

process.on('uncaughtException', (error) => {
  log('uncaught exception', { message: error.message, stack: error.stack });
});

process.on('unhandledRejection', (error) => {
  log('unhandled rejection', { message: error?.message ?? String(error), stack: error?.stack });
});

safeLoop('heartbeat', heartbeat);
safeLoop('account sync', syncAccounts);

setInterval(() => safeLoop('heartbeat', heartbeat), config.heartbeatIntervalMs);
setInterval(() => safeLoop('account sync', syncAccounts), config.accountSyncIntervalMs);
setInterval(() => safeLoop('job poll', () => runner.tick()), config.pollIntervalMs);
