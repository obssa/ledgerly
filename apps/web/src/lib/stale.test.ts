import { describe, expect, it, vi } from "vitest";
import { isStaleBuild, reloadOnceForStaleBuild, staleReloadAllowed } from "./stale";

describe("stale build after a release", () => {
  it("recognises a missing script file", () => {
    expect(isStaleBuild(Object.assign(new Error("Loading chunk 123 failed."), { name: "ChunkLoadError" }))).toBe(true);
    expect(isStaleBuild(new Error("Failed to load chunk /_next/static/chunks/0abc.js from module 42"))).toBe(true);
    expect(isStaleBuild(new TypeError("Failed to fetch dynamically imported module: https://x/_next/a.js"))).toBe(true);
    // A form opened before a release (Next.js's own message when its Server Action is gone).
    expect(isStaleBuild(new Error('Server Action "60b7a112a17f8668689dedf752209b5599476eb3b8" was not found on the server. \nRead more: https://nextjs.org/docs/messages/failed-to-find-server-action'))).toBe(true);
    expect(isStaleBuild(new Error("The API answered 500"))).toBe(false);
    expect(isStaleBuild(null)).toBe(false);
  });

  it("reloads once, then lets the error show", () => {
    const store = new Map<string, string>();
    const storage = { getItem: (k: string) => store.get(k) ?? null, setItem: (k: string, v: string) => void store.set(k, v) };
    const reload = vi.fn();
    expect(staleReloadAllowed(storage, 1_000_000)).toBe(true);
    expect(reloadOnceForStaleBuild(storage, reload, 1_000_000)).toBe(true);
    expect(staleReloadAllowed(storage, 1_030_000)).toBe(false);
    expect(reloadOnceForStaleBuild(storage, reload, 1_030_000)).toBe(false);   // again within a minute: no loop
    expect(reloadOnceForStaleBuild(storage, reload, 1_100_000)).toBe(true);    // a later release: once more
    expect(reload).toHaveBeenCalledTimes(2);
  });
});
