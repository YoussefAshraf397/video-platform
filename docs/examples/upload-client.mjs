#!/usr/bin/env node
// Reference upload client for the Video Platform API (docs/CLIENT_UPLOAD_GUIDE.md).
// No dependencies: Node 20+ has fetch, the same API browsers use. In a browser, replace the
// file reads with Blob.slice() and use XMLHttpRequest if you need per-part progress events.
//
//   API=http://localhost:8000 TOKEN=… node upload-client.mjs <file> <video-id> [state-file]
//
// Re-run the same command after a crash, Ctrl-C or network loss: it resumes from state-file.

import { open, readFile, writeFile, stat, rm } from 'node:fs/promises';
import { randomUUID } from 'node:crypto';

const API = process.env.API ?? 'http://localhost:8000';
const TOKEN = process.env.TOKEN;
const CONCURRENCY = Number(process.env.CONCURRENCY ?? 4);   // 3-6; 2-3 on mobile networks
const STOP_AFTER = Number(process.env.STOP_AFTER ?? 0);     // test hook: simulate losing the network

const [file, videoId, stateFile = `${file}.upload.json`] = process.argv.slice(2);
if (!file || !videoId || !TOKEN) {
  console.error('usage: API=… TOKEN=… node upload-client.mjs <file> <video-id> [state-file]');
  process.exit(2);
}

// --- API calls -------------------------------------------------------------------------------

async function api(method, path, body, { idempotencyKey } = {}) {
  const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey;
  const res = await fetch(API + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
  const json = res.status === 204 ? null : await res.json();
  if (!res.ok) throw Object.assign(new Error(`${method} ${path}: ${res.status} ${json?.code}`), { status: res.status, problem: json });
  return json;
}

// --- Retry with exponential backoff and full jitter ------------------------------------------

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const retryable = (status) => status === undefined || status === 408 || status === 429 || status >= 500;

async function withRetry(what, attempt, { tries = 8, base = 1000, cap = 30000 } = {}) {
  for (let i = 1; ; i++) {
    try {
      return await attempt();
    } catch (err) {
      if (i >= tries || !retryable(err.status)) throw err;
      const delay = Math.random() * Math.min(cap, base * 2 ** (i - 1));   // full jitter
      console.warn(`${what}: ${err.message}; retry ${i}/${tries - 1} in ${Math.round(delay)} ms`);
      await sleep(delay);
    }
  }
}

// --- Upload ----------------------------------------------------------------------------------

async function main() {
  const { size } = await stat(file);
  let state = await readFile(stateFile, 'utf8').then(JSON.parse).catch(() => null);
  if (state && state.size !== size) state = null;   // a different file: start over

  // 1. Create the upload session, or resume the saved one.
  let session;
  if (state) {
    session = await api('GET', `/v1/uploads/${state.uploadId}`);
    if (session.status !== 'initiated' && session.status !== 'in_progress') {
      if (session.status === 'completed') return console.log('already completed');
      session = null;   // aborted, expired or failed: start a new upload
    } else {
      console.log(`resuming upload ${session.id}: ${session.uploaded_parts.length}/${session.total_parts} parts already in S3`);
    }
  }
  if (!session) {
    const key = randomUUID();   // one key per *attempt to create*, reused for its retries
    session = await withRetry('create upload', () =>
      api('POST', `/v1/videos/${videoId}/uploads`, { size_bytes: size, content_type: 'video/mp4' }, { idempotencyKey: key }));
    state = { uploadId: session.id, size, etags: {} };
    await writeFile(stateFile, JSON.stringify(state));
    console.log(`upload ${session.id}: ${session.total_parts} parts of ${session.part_size_bytes} bytes`);
  }

  // What S3 already has wins over our own notes (it's the source of truth for parts).
  for (const p of session.uploaded_parts) state.etags[p.part_number] = p.etag;
  const urls = new Map(session.next_parts.map((p) => [p.part_number, p]));
  const todo = [];
  for (let n = 1; n <= session.total_parts; n++) if (!state.etags[n]) todo.push(n);

  // Fresh URLs in batches: before they run out, and whenever S3 says one has expired (403).
  async function urlFor(n, { refresh = false } = {}) {
    const known = urls.get(n);
    if (!refresh && known && Date.parse(known.expires_at) - Date.now() > 2 * 60_000) return known;
    const batch = [n, ...todo.filter((m) => m > n && !state.etags[m])].slice(0, 100);
    const { parts } = await withRetry('sign parts', () => api('POST', `/v1/uploads/${session.id}/parts:sign`, { part_numbers: batch }));
    for (const p of parts) urls.set(p.part_number, p);
    return urls.get(n);
  }

  // 2. PUT the parts, CONCURRENCY at a time. Each part is the exact byte range the server planned.
  const fh = await open(file, 'r');
  let uploaded = 0;
  async function uploadPart(n) {
    const offset = (n - 1) * session.part_size_bytes;
    const length = Math.min(session.part_size_bytes, size - offset);
    const body = Buffer.alloc(length);
    await fh.read(body, 0, length, offset);

    let refresh = false;
    const etag = await withRetry(`part ${n}`, async () => {
      const { url } = await urlFor(n, { refresh });
      const res = await fetch(url, { method: 'PUT', body });   // no Authorization header: the URL is the credential
      if (res.status === 403) { refresh = true; throw Object.assign(new Error('URL expired'), { status: 503 }); }
      if (!res.ok) throw Object.assign(new Error(`S3 ${res.status}`), { status: res.status });
      return res.headers.get('ETag');   // exposed by the bucket's CORS rule
    });
    state.etags[n] = etag;
    await writeFile(stateFile, JSON.stringify(state));   // so a restart doesn't re-send it
    uploaded++;
    console.log(`part ${n}/${session.total_parts} done`);
    if (STOP_AFTER && uploaded >= STOP_AFTER) { console.log('simulating a lost connection'); process.exit(3); }
  }
  const queue = [...todo];
  await Promise.all(Array.from({ length: Math.min(CONCURRENCY, queue.length) }, async () => {
    for (let n; (n = queue.shift()) !== undefined;) await uploadPart(n);
  }));
  await fh.close();

  // 3. Complete. One Idempotency-Key for this completion, reused for every retry of it.
  const parts = Object.entries(state.etags).map(([n, etag]) => ({ part_number: Number(n), etag }));
  const key = state.completeKey ??= randomUUID();
  await writeFile(stateFile, JSON.stringify(state));
  const done = await withRetry('complete', async () => {
    try {
      return await api('POST', `/v1/uploads/${session.id}:complete`, { parts }, { idempotencyKey: key });
    } catch (err) {
      if (err.problem?.code === 'UPLOAD_COMPLETING') throw Object.assign(err, { status: 503 });   // in progress elsewhere: retry
      throw err;   // PARTS_MISSING, UPLOAD_FAILED, UPLOAD_EXPIRED: not fixed by retrying as-is
    }
  });
  await rm(stateFile, { force: true });
  console.log(`completed: upload ${done.status}, video ${done.video_status}`);
}

main().catch((err) => {
  console.error(err.message, err.problem ?? '');
  process.exit(1);
});
