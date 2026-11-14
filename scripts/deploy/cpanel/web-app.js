// Entry point of the cPanel "Setup Node.js App" application (Application startup file: app.js, in ~/ledgerly-web).
// Installed by provision.sh. It stays the same across releases: it reads ~/ledgerly/shared/web.env and starts the
// Next.js server of whatever release ~/ledgerly/current points at. release.sh restarts it after each switch.
"use strict";

const fs = require("fs");
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

process.env.NODE_ENV = "production";
// The real path, so the server's __dirname is the release folder and a later switch needs only a restart.
require(fs.realpathSync(path.join(root, "current", "web", "server.js")));
