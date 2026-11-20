/**
 * After a release, a tab opened before it can ask for script files of the old build. When one is missing the
 * error is a failed chunk load; reloading the page fetches the new build and the page then works. This decides
 * whether an error is that, and reloads once (a second failure within a minute shows the error instead, so a
 * real outage never loops).
 */
export function isStaleBuild(error: unknown): boolean {
  const e = error as { name?: string; message?: string } | null;
  const text = `${e?.name ?? ""} ${e?.message ?? ""}`;
  return /ChunkLoadError|Loading chunk|Failed to load chunk|dynamically imported module|Importing a module script failed|error loading dynamically imported/i.test(text);
}

const KEY = "ledgerly:stale-reload";

/** Whether a reload for a stale build is still allowed (none in the last minute). Reads only. */
export function staleReloadAllowed(storage: Pick<Storage, "getItem"> | null, now = Date.now()): boolean {
  try {
    return now - Number(storage?.getItem(KEY) ?? 0) >= 60_000;
  } catch {
    return true;
  }
}

/** Reloads once for a stale build; returns false when it already tried in the last minute. */
export function reloadOnceForStaleBuild(storage: Pick<Storage, "getItem" | "setItem"> | null, reload: () => void, now = Date.now()): boolean {
  try {
    const last = Number(storage?.getItem(KEY) ?? 0);
    if (now - last < 60_000) return false;
    storage?.setItem(KEY, String(now));
  } catch {
    // Storage blocked: still reload once; the next failure in this page shows the error.
  }
  reload();
  return true;
}
