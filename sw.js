// ── Music App Service Worker ──────────────────────────────────────────────────
// Checks once daily whether previously-missed catalog searches now have results.
// Config + misses are pushed here from the main page via postMessage whenever
// they change, and stored in a small IDB key-value store.

const SW_DB_NAME = 'jb-sw';
const SW_DB_VER  = 1;
const MIN_INTERVAL = 60 * 60 * 1000; // rate-limit: no more than once per hour

// ── IDB helpers ───────────────────────────────────────────────────────────────
function openDB() {
  return new Promise((res, rej) => {
    const req = indexedDB.open(SW_DB_NAME, SW_DB_VER);
    req.onupgradeneeded = e => {
      if (!e.target.result.objectStoreNames.contains('kv')) {
        e.target.result.createObjectStore('kv');
      }
    };
    req.onsuccess = e => res(e.target.result);
    req.onerror   = () => rej(req.error);
  });
}
async function idbGet(key) {
  const db = await openDB();
  return new Promise((res, rej) => {
    const req = db.transaction('kv', 'readonly').objectStore('kv').get(key);
    req.onsuccess = () => res(req.result ?? null);
    req.onerror   = () => rej(req.error);
  });
}
async function idbSet(key, val) {
  const db = await openDB();
  return new Promise((res, rej) => {
    const req = db.transaction('kv', 'readwrite').objectStore('kv').put(val, key);
    req.onsuccess = () => res();
    req.onerror   = () => rej(req.error);
  });
}

// ── Core check ────────────────────────────────────────────────────────────────
async function checkMisses() {
  const [cfgData, misses, lastCheck] = await Promise.all([
    idbGet('cfg'),
    idbGet('misses'),
    idbGet('lastCheck'),
  ]);

  // Rate-limit (periodic sync enforces 24h minimum, but 'check-now' could run more)
  if (lastCheck && Date.now() - lastCheck < MIN_INTERVAL) return;
  await idbSet('lastCheck', Date.now());

  if (!cfgData?.url || !cfgData?.apiKey || !Array.isArray(misses) || !misses.length) return;

  const apiBase = cfgData.url.replace(/\/$/, '');
  let allSongs;
  try {
    const resp = await fetch(`${apiBase}/api.php?action=library`, {
      headers: { 'X-API-Key': cfgData.apiKey },
    });
    if (!resp.ok) return;
    const data = await resp.json();
    allSongs = data.songs || [];
  } catch {
    return; // network unavailable — try again next time
  }

  if (!allSongs.length) return;

  // Check each miss against the updated catalog
  const resolved = misses.filter(miss => {
    const lq = miss.query.toLowerCase();
    return allSongs.some(s =>
      (s.title        || '').toLowerCase().includes(lq) ||
      (s.artist       || '').toLowerCase().includes(lq) ||
      (s.album        || '').toLowerCase().includes(lq) ||
      (s.album_artist || '').toLowerCase().includes(lq)
    );
  });

  if (!resolved.length) return;

  const remaining = misses.filter(m => !resolved.some(r => r.query === m.query));
  await idbSet('misses', remaining);

  // Notify the user for each resolved miss
  for (const miss of resolved) {
    const tag = 'miss-' + miss.query.toLowerCase().replace(/[^a-z0-9]+/g, '-');
    await self.registration.showNotification('Now in your catalog', {
      body: `"${miss.query}" is now available — tap to browse.`,
      icon: '/icon-192.png',
      badge: '/icon-192.png',
      tag,
      data: { query: miss.query },
      renotify: true,
    });
  }

  // Tell any open app windows to refresh their miss list from storage
  const clients = await self.clients.matchAll({ type: 'window' });
  for (const client of clients) {
    client.postMessage({ type: 'misses-updated', misses: remaining });
  }
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────
self.addEventListener('install',  ()  => self.skipWaiting());
self.addEventListener('activate', e   => e.waitUntil(self.clients.claim()));

// ── Periodic Background Sync (Chrome 80+, requires site engagement score) ────
self.addEventListener('periodicsync', e => {
  if (e.tag === 'check-search-misses') {
    e.waitUntil(checkMisses());
  }
});

// ── Messages from the main page ───────────────────────────────────────────────
self.addEventListener('message', e => {
  if (!e.data) return;
  switch (e.data.type) {
    case 'sync-data':
      // Main page pushing fresh config + misses into SW's IDB
      Promise.all([
        idbSet('cfg',   e.data.cfg),
        idbSet('misses', e.data.misses),
      ]).catch(console.error);
      break;

    case 'check-now':
      // Fallback: page is asking SW to run a check (respects rate limit)
      e.waitUntil(checkMisses());
      break;
  }
});

// ── Notification clicks ───────────────────────────────────────────────────────
self.addEventListener('notificationclick', e => {
  e.notification.close();
  const query = e.notification.data?.query || '';
  e.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
      // Focus an existing app tab and tell it to open the catalog
      if (list.length) {
        const target = list[0];
        target.focus();
        target.postMessage({ type: 'open-catalog', query });
        return;
      }
      // No open tab — open the app and pass the query in the URL
      return self.clients.openWindow(
        self.registration.scope + (query ? '?catalog=' + encodeURIComponent(query) : '')
      );
    })
  );
});
