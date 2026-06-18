export class CloudClient {
  constructor(config) {
    this.config = config;
  }

  async heartbeat(status, meta = {}) {
    return this.request('/api/v1/crebee-agent/heartbeat', {
      method: 'POST',
      body: {
        version: '0.1.0',
        crebee_status: status,
        meta,
      },
    });
  }

  async syncAccounts(accounts) {
    return this.request('/api/v1/crebee-agent/accounts/sync', {
      method: 'POST',
      body: { accounts },
    });
  }

  async nextJob() {
    const response = await this.request('/api/v1/crebee-agent/jobs/next', {
      method: 'GET',
    });

    return response.data?.job ?? null;
  }

  async markAccepted(jobId, raw) {
    return this.request(`/api/v1/crebee-agent/jobs/${jobId}/accepted`, {
      method: 'POST',
      body: { raw },
    });
  }

  async recordEvents(jobId, events) {
    return this.request(`/api/v1/crebee-agent/jobs/${jobId}/events`, {
      method: 'POST',
      body: { events },
    });
  }

  async markFinished(jobId, items) {
    return this.request(`/api/v1/crebee-agent/jobs/${jobId}/finished`, {
      method: 'POST',
      body: { items },
    });
  }

  async markFailed(jobId, message, raw = {}) {
    return this.request(`/api/v1/crebee-agent/jobs/${jobId}/failed`, {
      method: 'POST',
      body: { message, raw },
    });
  }

  async request(path, options) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.config.httpTimeoutMs);
    const headers = {
      'Accept': 'application/json',
      'X-CreBee-Agent-Id': this.config.agentId,
      'X-CreBee-Agent-Secret': this.config.agentSecret,
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
    };

    try {
      const response = await fetch(`${this.config.geoflowBaseUrl}${path}`, {
        method: options.method,
        headers,
        body: options.body ? JSON.stringify(options.body) : undefined,
        signal: controller.signal,
      });

      const text = await response.text();
      const json = text ? JSON.parse(text) : {};
      if (!response.ok || json.success === false) {
        const code = json.error?.code ?? response.status;
        const message = json.error?.message ?? response.statusText;
        throw new Error(`GEOFlow API failed: ${code} ${message}`);
      }

      return json;
    } finally {
      clearTimeout(timer);
    }
  }
}
