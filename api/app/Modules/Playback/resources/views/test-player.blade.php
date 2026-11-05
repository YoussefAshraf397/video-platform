<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Test player</title>
<style>
  :root {
    --bg: #f6f7f9; --panel: #ffffff; --text: #1d2330; --muted: #5b6475; --line: #dde1e8;
    --accent: #2457d6; --accent-text: #ffffff; --good: #18794e; --bad: #c4321c; --mark: #e8efff;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #12151b; --panel: #1b1f27; --text: #e6e9ef; --muted: #9aa3b2; --line: #2c323d;
      --accent: #6d95ff; --accent-text: #0d1220; --good: #4cc38a; --bad: #ff7a66; --mark: #22304f;
    }
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
  header { padding: 12px 16px; border-bottom: 1px solid var(--line); background: var(--panel); display: flex; gap: 12px; align-items: baseline; flex-wrap: wrap; }
  header h1 { font-size: 16px; margin: 0; }
  header p { margin: 0; color: var(--muted); }
  main { display: grid; gap: 16px; padding: 16px; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); max-width: 1400px; margin: 0 auto; }
  @media (max-width: 900px) { main { grid-template-columns: minmax(0, 1fr); } }
  section { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 12px; min-width: 0; }
  h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); margin: 0 0 8px; }
  form { display: flex; gap: 8px; flex-wrap: wrap; align-items: end; }
  label { display: flex; flex-direction: column; gap: 2px; font-size: 12px; color: var(--muted); }
  select { font: inherit; padding: 6px 8px; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--text); }
  input { font: inherit; padding: 6px 8px; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--text); min-width: 0; }
  button { font: inherit; padding: 6px 12px; border-radius: 6px; border: 1px solid var(--accent); background: var(--accent); color: var(--accent-text); cursor: pointer; }
  button.secondary { background: transparent; color: var(--accent); }
  button:disabled { opacity: .5; cursor: default; }
  :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
  video { width: 100%; aspect-ratio: 16 / 9; background: #000; border-radius: 6px; display: block; }
  dl { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 4px 12px; margin: 0; }
  dt { color: var(--muted); }
  dd { margin: 0; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
  table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
  th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid var(--line); }
  th { color: var(--muted); font-weight: 500; font-size: 12px; }
  tr.playing { background: var(--mark); font-weight: 600; }
  .status { margin: 8px 0 0; min-height: 1.45em; }
  .error { color: var(--bad); }
  .ok { color: var(--good); }
  .stack { display: grid; gap: 16px; align-content: start; min-width: 0; }
  ol#log { margin: 0; padding: 0 0 0 18px; max-height: 280px; overflow: auto; font: 12px/1.5 ui-monospace, SFMono-Regular, Menlo, monospace; }
  ol#log li { overflow-wrap: anywhere; }
  .muted { color: var(--muted); }
</style>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.7.4/dist/hls.min.js" integrity="sha384-p9vgU/7IYJWd3I4STrtqxqR/2cAQlhIX7D/HrTnIMEjjcUox165b7se7sxmm3zDg" crossorigin="anonymous"></script>
</head>
<body>
<header>
  <h1>Test player</h1>
  <p>Internal, dev only (S4-07). Calls <code>GET /v1/videos/{id}/playback</code> like a real client.</p>
</header>
<main>
  <div class="stack">
    <section aria-labelledby="load-h">
      <h2 id="load-h">Video</h2>
      <form id="load-form">
        <label>Video id <input id="video-id" name="v" required pattern="[A-Za-z0-9_\-]{11}" maxlength="11" autocomplete="off" placeholder="y4S2byb8ju0"></label>
        <button type="submit">Load &amp; play</button>
      </form>
      <p id="load-status" class="status" role="status"></p>
    </section>
    <section aria-label="Player">
      <video id="video" controls playsinline crossorigin="use-credentials"></video>
    </section>
    <section aria-labelledby="renditions-h">
      <h2 id="renditions-h">Renditions</h2>
      <table>
        <thead><tr><th scope="col">Use</th><th scope="col">Resolution</th><th scope="col">Bitrate</th><th scope="col">Frame rate</th><th scope="col">Codecs</th></tr></thead>
        <tbody id="levels"><tr><td colspan="5" class="muted">Load a video to see its renditions.</td></tr></tbody>
      </table>
      <p class="muted">"Auto" lets hls.js pick by bandwidth and buffer. Picking a rendition switches at once (the buffer is flushed). The playing rendition is highlighted.</p>
    </section>
    <section aria-labelledby="captions-h">
      <h2 id="captions-h">Captions</h2>
      <form id="captions-form">
        <label>Track <select id="captions" disabled><option value="-1">Off</option></select></label>
      </form>
      <p id="captions-note" class="muted">The tracks come from the master playlist (S4-06). The video's own CC menu offers the same ones.</p>
    </section>
  </div>
  <div class="stack">
    <section aria-labelledby="signin-h">
      <h2 id="signin-h">Viewer</h2>
      <form id="signin-form">
        <label>Email <input id="email" type="email" autocomplete="username" required></label>
        <label>Password <input id="password" type="password" autocomplete="current-password" required></label>
        <button type="submit">Sign in</button>
        <button type="button" id="signout" class="secondary" disabled>Sign out</button>
      </form>
      <p id="viewer" class="status" role="status">Anonymous: public and unlisted published videos only. Sign in to preview your own.</p>
    </section>
    <section aria-labelledby="access-h">
      <h2 id="access-h">Access</h2>
      <dl>
        <dt>Title</dt><dd id="i-title">–</dd>
        <dt>Duration</dt><dd id="i-duration">–</dd>
        <dt>Source size</dt><dd id="i-size">–</dd>
        <dt>Stream</dt><dd id="i-hls">–</dd>
        <dt>Cookies</dt><dd id="i-cookies">–</dd>
        <dt>Expire</dt><dd id="i-expires">–</dd>
        <dt>Refreshes</dt><dd id="i-refreshes">0</dd>
      </dl>
    </section>
    <section aria-labelledby="stats-h">
      <h2 id="stats-h">Stats</h2>
      <dl>
        <dt>Playing</dt><dd id="s-level">–</dd>
        <dt>Mode</dt><dd id="s-mode">–</dd>
        <dt>Bandwidth est.</dt><dd id="s-bw">–</dd>
        <dt>Buffer ahead</dt><dd id="s-buffer">–</dd>
        <dt>Dropped frames</dt><dd id="s-dropped">–</dd>
        <dt>Caption on screen</dt><dd id="s-cue">–</dd>
      </dl>
    </section>
    <section aria-labelledby="log-h">
      <h2 id="log-h">Events</h2>
      <ol id="log" reversed aria-live="polite"></ol>
    </section>
  </div>
</main>
@verbatim
<script>
(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const video = $('video');
  const state = { token: null, videoId: null, hls: null, expiresAt: null, refreshTimer: null, refreshes: 0, lastRefresh: 0 };

  // ---- helpers (API data only ever goes into textContent) -------------------------------
  const kbps = (bps) => bps >= 1e6 ? (bps / 1e6).toFixed(2) + ' Mbps' : Math.round(bps / 1e3) + ' kbps';
  const set = (id, text, cls) => { const el = $(id); el.textContent = text; el.className = el.className.replace(/\b(ok|error)\b/g, '').trim() + (cls ? ' ' + cls : ''); };
  function log(message, cls) {
    const li = document.createElement('li');
    li.textContent = new Date().toLocaleTimeString() + '  ' + message;
    if (cls) li.className = cls;
    const list = $('log');
    list.prepend(li);
    while (list.children.length > 200) list.lastChild.remove();
  }

  /** Calls the API (same origin). Throws an Error carrying the problem details on failure. */
  async function api(path, options = {}) {
    const headers = { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}) };
    if (state.token) headers.Authorization = 'Bearer ' + state.token;
    const response = await fetch(path, { ...options, headers });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(`${response.status} ${body.code || ''} ${body.title || response.statusText}${body.detail ? ': ' + body.detail : ''}`.replace(/\s+/g, ' '));
      error.status = response.status;
      error.retryAfter = response.headers.get('Retry-After');
      throw error;
    }
    return body;
  }

  // ---- viewer ------------------------------------------------------------------------------
  $('signin-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      const body = await api('/v1/auth/login', { method: 'POST', body: JSON.stringify({ email: $('email').value, password: $('password').value }) });
      state.token = body.access_token;   // kept in memory only: a reload signs out
      $('password').value = '';
      $('signout').disabled = false;
      set('viewer', 'Signed in as ' + body.user.email + ' (token in memory, expires in ' + Math.round(body.expires_in / 60) + ' min).', 'ok');
      log('signed in as ' + body.user.email);
    } catch (error) {
      set('viewer', error.message, 'error');
    }
  });
  $('signout').addEventListener('click', () => {
    state.token = null;
    $('signout').disabled = true;
    set('viewer', 'Anonymous: public and unlisted published videos only. Sign in to preview your own.');
    log('signed out (token forgotten)');
  });

  // ---- playback authorization ----------------------------------------------------------------
  /** Asks the API for playback; its response sets the signed cookies (HttpOnly) for the stream. */
  async function authorize(reason) {
    state.lastRefresh = Date.now();
    const body = await api('/v1/videos/' + encodeURIComponent(state.videoId) + '/playback');
    state.expiresAt = new Date(body.auth.expires_at);
    set('i-title', body.video.title);
    set('i-duration', body.video.duration_ms === null ? 'unknown (still processing)' : (body.video.duration_ms / 1000).toFixed(1) + ' s');
    set('i-size', body.video.width && body.video.height ? body.video.width + '×' + body.video.height : '–');
    set('i-hls', body.streams.hls);
    set('i-cookies', Object.keys(body.auth.cookies).join(', ') + ' (set by the response, HttpOnly)');
    if (reason !== 'load') {
      state.refreshes += 1;
      set('i-refreshes', String(state.refreshes));
      log('cookies refreshed (' + reason + ')', 'ok');
      set('load-status', 'Playing. Cookies refreshed, valid until ' + state.expiresAt.toLocaleTimeString() + '.', 'ok');
    }
    scheduleRefresh();
    return body;
  }

  /** Refresh 2 minutes before the cookies expire (design doc §13.4). */
  function scheduleRefresh() {
    clearTimeout(state.refreshTimer);
    const delay = Math.max(10_000, state.expiresAt - Date.now() - 120_000);
    state.refreshTimer = setTimeout(() => authorize('near expiry').catch((error) => log('refresh failed: ' + error.message, 'error')), delay);
  }

  // ---- player ------------------------------------------------------------------------------
  function reset() {
    clearTimeout(state.refreshTimer);
    if (state.hls) { state.hls.destroy(); state.hls = null; }
    video.removeAttribute('src');
    video.removeAttribute('poster');
    video.load();
    state.refreshes = 0;
    set('i-refreshes', '0');
    renderLevels([]);
    renderCaptions([]);
  }

  $('load-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    reset();
    state.videoId = $('video-id').value.trim();
    history.replaceState(null, '', '?v=' + encodeURIComponent(state.videoId));
    set('load-status', 'Authorizing…');
    let body;
    try {
      body = await authorize('load');
    } catch (error) {
      set('load-status', error.message + (error.retryAfter ? ` (retry in ${error.retryAfter} s)` : ''), 'error');
      log('playback refused: ' + error.message, 'error');
      return;
    }
    const poster = (body.thumbnails.primary || []).filter((t) => t.format === 'jpg').sort((a, b) => b.width - a.width)[0];
    if (poster) video.poster = poster.url;
    set('load-status', 'Authorized until ' + state.expiresAt.toLocaleTimeString() + '. Loading the stream…', 'ok');
    log('authorized; cookies expire ' + state.expiresAt.toISOString());
    play(body.streams.hls);
  });

  function play(url) {
    if (window.Hls && Hls.isSupported()) {
      // Send the signed cookies to the media domain (cross-origin): the CDN's CORS must allow
      // this origin with credentials.
      const hls = new Hls({ xhrSetup: (xhr) => { xhr.withCredentials = true; } });
      state.hls = hls;
      hls.on(Hls.Events.MANIFEST_PARSED, (_, data) => {
        log('master playlist: ' + data.levels.length + ' renditions');
        set('load-status', 'Playing. Cookies valid until ' + state.expiresAt.toLocaleTimeString() + '.', 'ok');
        renderLevels(data.levels);
        video.play().catch(() => log('autoplay blocked: press play'));
      });
      hls.on(Hls.Events.LEVEL_SWITCHING, (_, data) => log('switching to ' + describe(hls.levels[data.level])));
      hls.on(Hls.Events.LEVEL_SWITCHED, (_, data) => { log('now playing ' + describe(hls.levels[data.level]), 'ok'); markPlaying(data.level); });
      hls.on(Hls.Events.FRAG_LOADED, (_, data) => { if (data.frag.sn === 'initSegment' || data.frag.sn % 5 === 0) log('loaded ' + data.frag.relurl + ' (' + describe(hls.levels[data.frag.level]) + ')'); });
      hls.on(Hls.Events.SUBTITLE_TRACKS_UPDATED, (_, data) => renderCaptions(data.subtitleTracks));
      hls.on(Hls.Events.SUBTITLE_TRACK_SWITCH, (_, data) => {
        $('captions').value = String(data.id);
        if (data.id < 0) set('s-cue', '–');
        log(data.id < 0 ? 'captions off' : 'captions: ' + describeTrack(hls.subtitleTracks[data.id]), 'ok');
      });
      hls.on(Hls.Events.ERROR, (_, data) => onError(data));
      hls.loadSource(url);
      hls.attachMedia(video);
    } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
      // Safari / iOS: native HLS sends the cookies itself; rendition choice is the browser's.
      video.src = url;
      renderLevels([]);
      $('levels').firstElementChild.firstElementChild.textContent = 'Native HLS player: renditions are chosen by the browser.';
      set('captions-note', 'Native HLS player: use the video\'s own CC menu.');
      video.play().catch(() => log('autoplay blocked: press play'));
    } else {
      set('load-status', 'This browser can play neither hls.js (MSE) nor native HLS.', 'error');
    }
  }

  async function onError(data) {
    const status = data.response && data.response.code;
    log(`${data.fatal ? 'fatal ' : ''}${data.type} / ${data.details}${status ? ' (HTTP ' + status + ')' : ''}`, 'error');
    // 403 from the CDN: the cookies expired or were never accepted. Ask the API again, at most every 10 s.
    if (status === 403 && Date.now() - state.lastRefresh > 10_000) {
      try {
        await authorize('CDN answered 403');
        state.hls.startLoad();
      } catch (error) {
        set('load-status', 'Access ended: ' + error.message, 'error');
        state.hls.stopLoad();
      }
      return;
    }
    if (status === 403) log('403 within 10 s of authorizing: not asking again (fresh cookies were refused)', 'error');
    if (!data.fatal) return;
    if (data.type === Hls.ErrorTypes.NETWORK_ERROR) state.hls.startLoad();
    else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) state.hls.recoverMediaError();
    else { set('load-status', 'Playback stopped: ' + data.details, 'error'); state.hls.destroy(); state.hls = null; }
  }

  // ---- renditions & stats ------------------------------------------------------------------
  const describe = (level) => level ? level.height + 'p @ ' + kbps(level.bitrate) : 'unknown';

  function renderLevels(levels) {
    const tbody = $('levels');
    tbody.replaceChildren();
    if (!levels.length) {
      const row = tbody.insertRow();
      const cell = row.insertCell();
      cell.colSpan = 5;
      cell.className = 'muted';
      cell.textContent = 'Load a video to see its renditions.';
      return;
    }
    const choices = [{ index: -1, label: 'Auto' }, ...levels.map((level, index) => ({ index, level }))];
    for (const choice of choices) {
      const row = tbody.insertRow();
      row.dataset.level = String(choice.index);
      const radio = document.createElement('input');
      radio.type = 'radio';
      radio.name = 'level';
      radio.value = String(choice.index);
      radio.checked = choice.index === -1;
      radio.setAttribute('aria-label', choice.level ? 'Use ' + describe(choice.level) : 'Automatic rendition choice');
      radio.addEventListener('change', () => {
        state.hls.currentLevel = choice.index;
        log(choice.index === -1 ? 'mode: auto' : 'mode: forced ' + describe(choice.level));
      });
      row.insertCell().append(radio);
      if (choice.level) {
        const { width, height, bitrate, frameRate, videoCodec, audioCodec } = choice.level;
        for (const text of [width + '×' + height, kbps(bitrate), frameRate ? String(frameRate) : '–', [videoCodec, audioCodec].filter(Boolean).join(', ')]) {
          row.insertCell().textContent = text;
        }
      } else {
        const cell = row.insertCell();
        cell.colSpan = 4;
        cell.textContent = 'Auto (adaptive)';
      }
    }
  }

  // ---- captions ------------------------------------------------------------------------------
  const describeTrack = (t) => t.name + ' (' + t.lang + (t.characteristics ? ', captions' : '') + ')';

  function renderCaptions(tracks) {
    const select = $('captions');
    select.replaceChildren(new Option('Off', '-1'));
    tracks.forEach((t, i) => select.append(new Option(describeTrack(t), String(i))));   // Option() sets text, not HTML
    select.disabled = tracks.length === 0;
    if (state.hls) log(tracks.length + ' caption track' + (tracks.length === 1 ? '' : 's') + ' in the master playlist');
  }

  $('captions').addEventListener('change', (event) => {
    const id = Number(event.target.value);
    state.hls.subtitleDisplay = id >= 0;
    state.hls.subtitleTrack = id;
  });

  // What the viewer sees now, from whichever text track is showing.
  video.textTracks.addEventListener('addtrack', ({ track }) => {
    track.addEventListener('cuechange', () => {
      if (track.mode !== 'showing') return;
      const cues = [...(track.activeCues || [])].map((c) => c.getCueAsHTML().textContent);   // as rendered: tags applied, &amp; decoded
      set('s-cue', cues.length ? cues.join(' / ') : '–');
    });
  });

  function markPlaying(index) {
    for (const row of $('levels').rows) row.classList.toggle('playing', row.dataset.level === String(index));
    // hls.js can leave a forced rendition on its own (e.g. after a load error): keep the choice honest.
    const choice = state.hls && state.hls.autoLevelEnabled ? '-1' : String(index);
    for (const radio of document.querySelectorAll('input[name=level]')) radio.checked = radio.value === choice;
  }

  setInterval(() => {
    if (state.expiresAt) {
      const seconds = Math.round((state.expiresAt - Date.now()) / 1000);
      set('i-expires', state.expiresAt.toLocaleTimeString() + (seconds > 0 ? ` (in ${Math.floor(seconds / 60)} min ${seconds % 60} s)` : ' (expired)'));
    }
    const hls = state.hls;
    if (!hls) return;
    set('s-level', hls.currentLevel >= 0 ? describe(hls.levels[hls.currentLevel]) : '–');
    set('s-mode', hls.autoLevelEnabled ? 'auto' : 'forced');
    set('s-bw', hls.bandwidthEstimate ? kbps(hls.bandwidthEstimate) : '–');
    let ahead = 0;
    for (let i = 0; i < video.buffered.length; i++) {
      if (video.buffered.start(i) <= video.currentTime && video.currentTime <= video.buffered.end(i)) ahead = video.buffered.end(i) - video.currentTime;
    }
    set('s-buffer', ahead.toFixed(1) + ' s');
    const quality = video.getVideoPlaybackQuality ? video.getVideoPlaybackQuality() : null;
    set('s-dropped', quality ? quality.droppedVideoFrames + ' of ' + quality.totalVideoFrames : '–');
  }, 1000);

  // ?v=<id> loads straight away.
  const fromUrl = new URLSearchParams(location.search).get('v');
  if (fromUrl) { $('video-id').value = fromUrl; $('load-form').requestSubmit(); }
  if (!window.Hls) log('hls.js did not load (offline, or the CDN is blocked)', 'error');
})();
</script>
@endverbatim
</body>
</html>
