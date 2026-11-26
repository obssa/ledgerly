// Entry point of the cPanel "Setup Node.js App" application (Application startup file: app.js, in ~/ledgerly-web).
// Installed by provision.sh and copied again by every deploy. It reads ~/ledgerly/shared/web.env and starts the
// Next.js server of whatever release ~/ledgerly/current points at. release.sh restarts it after each switch.
//
// Three things here are about speed on this kind of host (docs/runbook/cpanel.md, "Speed"):
//   pinApiAddress   the web server's calls to the API stay on this server instead of going out to Cloudflare and back
//   privateDoor     a form that moves to another page answers in one round trip, not two
//   keepApiWarm     PHP keeps its compiled code between clicks
"use strict";

const crypto = require("crypto");
const dns = require("dns");
const fs = require("fs");
const http = require("http");
const net = require("net");
const os = require("os");
const path = require("path");

const root = process.env.LEDGERLY_ROOT || path.join(os.homedir(), "ledgerly");

try {
  for (const line of fs.readFileSync(path.join(root, "shared", "web.env"), "utf8").split(/\r?\n/)) {
    const m = line.match(/^([A-Z_][A-Z0-9_]*)=(.*)$/);
    if (m && process.env[m[1]] === undefined) process.env[m[1]] = m[2];
  }
} catch (e) {
  console.error(`ledgerly-web: could not read ${root}/shared/web.env (${e.message}); starting in demo mode`);
}

/**
 * Every page's API calls are made from this server. With the domain behind Cloudflare, public DNS answers with
 * Cloudflare's addresses, so each call would leave the server, reach Cloudflare and come back. LEDGERLY_API_ADDRESS
 * (this account's own address, written by release.sh from cPanel's record of the API's domain) is used for the API's
 * name instead. Only the address changes: the connection is still HTTPS to the API's name, and its certificate is
 * still checked. Without the setting, the API is looked up as any other name.
 */
function pinApiAddress() {
  const address = process.env.LEDGERLY_API_ADDRESS;
  const family = net.isIP(address || "");
  let host = "";
  try { host = new URL(process.env.LEDGERLY_API_URL || "").hostname; } catch { /* no API: demo mode */ }
  if (!host || family === 0) return;
  const lookup = dns.lookup;
  dns.lookup = function (name, options, callback) {
    if (typeof options === "function") { callback = options; options = {}; }
    if (name !== host) return lookup.call(this, name, options, callback);
    const all = typeof options === "object" && options !== null && options.all;
    process.nextTick(() => (all ? callback(null, [{ address, family }]) : callback(null, address, family)));
    return undefined;
  };
}

/**
 * When a form's server action ends in a redirect, Next.js fetches the next page from itself, so the browser gets it
 * in the same answer. It fetches from the address it thinks it listens on. Under the host's Node.js loader that
 * address is wrong: the loader (LiteSpeed's lsnode.js on this host, Passenger's node-loader.js on others) takes over
 * http.Server's listen() and address() and gives the server a socket of its own, so Next.js records 0.0.0.0:3000,
 * nothing answers there, and every such click costs the visitor a second round trip (and a stack trace in
 * stderr.log: "failed to get redirect response ... ECONNREFUSED 0.0.0.0:3000").
 *
 * So a second listener on 127.0.0.1 hands its requests to the same server, and Next.js is told to fetch from it.
 * Other accounts on a shared server can reach 127.0.0.1 as well, so it answers only under a path made of random
 * bytes that never leave this process; anything else gets a 404. Behind it is the same app the public reaches.
 *
 * The door listens through net.Server's own listen() and address(), which neither loader replaces. Through
 * http.Server's, the loader would take the door for the app (it installs the FIRST server that listens, and
 * ignores the rest): on 26 Nov 2026 that answered every visitor with the door's 404. Anything that goes wrong
 * here leaves the app as it would be without the door.
 */
function privateDoor() {
  const listen = http.Server.prototype.listen;
  http.Server.prototype.listen = function (...args) {
    http.Server.prototype.listen = listen; // the first server to listen is Next.js's own; leave any later one alone
    try {
      openDoor(this);
    } catch (e) {
      console.error(`ledgerly-web: no private listener (${e.message}); redirects take two round trips`);
    }
    return listen.apply(this, args);
  };
}

function openDoor(app) {
  const secret = Buffer.from(`/${crypto.randomBytes(24).toString("hex")}`);
  let origin;
  const door = http.createServer((req, res) => {
      const head = Buffer.from(req.url.slice(0, secret.length));
      const next = req.url.charAt(secret.length);
    if (head.length !== secret.length || !crypto.timingSafeEqual(head, secret) || (next !== "" && next !== "/" && next !== "?")) {
      res.statusCode = 404;
      res.end();
      return;
    }
    req.url = req.url.slice(secret.length) || "/";
    if (req.url.startsWith("?")) req.url = `/${req.url}`;
    app.emit("request", req, res);
  });
  door.on("error", (e) => console.error(`ledgerly-web: the private listener failed (${e.message}); redirects take two round trips`));
  const use = () => { if (origin) process.env.__NEXT_PRIVATE_ORIGIN = origin; };
  net.Server.prototype.listen.call(door, 0, "127.0.0.1", () => {
    origin = `http://127.0.0.1:${net.Server.prototype.address.call(door).port}${secret.toString()}`;
    use();
  });
  // Next.js writes its own (wrong) origin when its server starts listening: ours goes in after it.
  app.once("listening", () => setImmediate(use));
}

/**
 * This host stops PHP, and drops the code it has compiled, after 60 seconds without a request (LSAPI_PGRP_MAX_IDLE=60).
 * The first click after a pause then waited about a second for Laravel to be compiled again, in every worker the page
 * used. A health check every 30 seconds keeps PHP running. Cron can't do it: the host delays each cron job by up to a
 * minute. LEDGERLY_KEEP_WARM_SECONDS=0 turns it off.
 */
function keepApiWarm() {
  const seconds = Number(process.env.LEDGERLY_KEEP_WARM_SECONDS || 30);
  let url;
  try { url = new URL("/up", process.env.LEDGERLY_API_URL || ""); } catch { return; }
  if (!(seconds > 0)) return;
  const ping = () => fetch(url, { signal: AbortSignal.timeout(10_000) }).then((r) => r.body?.cancel()).catch(() => {});
  setInterval(ping, seconds * 1000).unref();
}

process.env.NODE_ENV = "production";
pinApiAddress();
privateDoor();
keepApiWarm();
// The real path, so the server's __dirname is the release folder and a later switch needs only a restart.
require(fs.realpathSync(path.join(root, "current", "web", "server.js")));
