import { afterAll, describe, expect, it } from "vitest";
import { spawn, type ChildProcess } from "node:child_process";
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

/**
 * The cPanel startup file (scripts/deploy/cpanel/web-app.js) runs under the host's Node.js loader, which takes over
 * http.Server's listen() and address(): the FIRST server that listens becomes the app, later ones are ignored.
 * On 26 Nov 2026 the startup file's own helper listener was taken for the app and every visitor got a 404. These
 * tests run it under stand-ins for both loaders (LiteSpeed's lsnode.js, which this host uses, and Passenger's
 * node-loader.js), with a stand-in for Next.js that records a wrong address for itself, as Next.js does there.
 */
const WEB_APP = resolve(__dirname, "../../../../scripts/deploy/cpanel/web-app.js");
const dir = mkdtempSync(join(tmpdir(), "web-app-"));
const children: ChildProcess[] = [];
afterAll(() => {
  for (const c of children) c.kill();
  rmSync(dir, { recursive: true, force: true });
});

mkdirSync(join(dir, "root", "current", "web"), { recursive: true });
mkdirSync(join(dir, "root", "shared"), { recursive: true });
writeFileSync(join(dir, "root", "shared", "web.env"), "LEDGERLY_KEEP_WARM_SECONDS=0\n");
// Next.js's standalone server, reduced to what matters: it listens once, then writes the address it believes it has
// into __NEXT_PRIVATE_ORIGIN, and a redirect after a form fetches the next page from there.
writeFileSync(join(dir, "root", "current", "web", "server.js"), `
const http = require("http");
const server = http.createServer(async (req, res) => {
  if (req.url === "/redirect") {
    try { const r = await fetch(process.env.__NEXT_PRIVATE_ORIGIN + "/next?x=1", { headers: { cookie: "s=1" } }); res.end("inner " + r.status + " " + (await r.text())); }
    catch (e) { res.end("inner failed " + (e.cause && e.cause.code)); }
    return;
  }
  res.end("page " + req.url + " cookie=" + (req.headers.cookie || ""));
});
server.on("listening", () => { process.env.__NEXT_PRIVATE_ORIGIN = "http://0.0.0.0:3000"; });
server.listen(3000, "0.0.0.0");
`);

// The loaders' rule, as both implement it: the first http.Server to call listen() gets the public socket (here a
// port, printed for the test), and address() no longer tells the truth.
const LOADERS = {
  lsnode: `
const http = require("http"), net = require("net");
let done = false;
http.Server.prototype.realListen = http.Server.prototype.listen;
http.Server.prototype.listen = function () {
  if (done) { console.error("http.Server.listen() was called more than once, ignore."); return this; }
  done = true;
  this.realListen(0, "127.0.0.1", () => console.log("PUBLIC " + net.Server.prototype.address.call(this).port));
  return this;
};
http.Server.prototype.address = () => "/tmp/lsnode.sock";
require(process.argv[2]);
`,
  passenger: `
const http = require("http"), net = require("net");
let done = false;
http.Server.prototype.originalListen = http.Server.prototype.listen;
http.Server.prototype.listen = function () {
  if (done) throw new Error("http.Server.listen() was called more than once");
  done = true;
  this.originalListen(0, "127.0.0.1", () => console.log("PUBLIC " + net.Server.prototype.address.call(this).port));
  return this;
};
require(process.argv[2]);
`,
};

function start(loader: keyof typeof LOADERS): Promise<number> {
  const file = join(dir, `${loader}.js`);
  writeFileSync(file, LOADERS[loader]);
  const child = spawn(process.execPath, [file, WEB_APP], { env: { ...process.env, LEDGERLY_ROOT: join(dir, "root") } });
  children.push(child);
  return new Promise((ok, fail) => {
    let out = "";
    child.stdout.on("data", (c) => {
      out += c;
      const m = out.match(/PUBLIC (\d+)/);
      if (m) ok(Number(m[1]));
    });
    child.on("exit", (code) => fail(new Error(`exited ${code}`)));
  });
}

describe.each(["lsnode", "passenger"] as const)("web-app.js under %s", (loader) => {
  it("serves the app on the public socket, and redirects fetch the next page through the private listener", async () => {
    const port = await start(loader);
    await new Promise((r) => setTimeout(r, 300)); // the private listener's address goes in after the app's
    expect(await (await fetch(`http://127.0.0.1:${port}/sign-in`)).text()).toBe("page /sign-in cookie=");
    expect(await (await fetch(`http://127.0.0.1:${port}/redirect`)).text()).toBe("inner 200 page /next?x=1 cookie=s=1");
  }, 15_000);
});
