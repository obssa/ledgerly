import { describe, expect, it } from "vitest";
import { existsSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";

/**
 * A click must show the next page's outline at once (components/PageLoading.tsx). Next.js does that only below a
 * loading.tsx in the folder whose child changes, so every folder of the app with pages below it needs one.
 */
const APP = join(__dirname, "..", "app", "(app)");

function folders(dir: string): string[] {
  return readdirSync(dir).map((n) => join(dir, n)).filter((p) => statSync(p).isDirectory()).flatMap((p) => [p, ...folders(p)]);
}
const hasPageBelow = (dir: string) => folders(dir).some((d) => existsSync(join(d, "page.tsx")));

describe("loading screens", () => {
  it("every folder with pages below it shows one at once", () => {
    const missing = [APP, ...folders(APP)].filter((d) => hasPageBelow(d) && !existsSync(join(d, "loading.tsx")));
    expect(missing.map((d) => d.slice(APP.length) || "/")).toEqual([]);
  });
});
