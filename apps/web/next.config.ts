import type { NextConfig } from "next";

/**
 * The Content-Security-Policy (SECURITY.md S6). Pages are prerendered and streamed (cacheComponents), so no per-request
 * nonce can reach their scripts, and the streamed parts are put in place by small inline scripts: inline scripts are
 * therefore allowed. Everything else is shut: scripts, images and connections come only from this site,
 * every script file is checked by its hash (experimental.sri below), no eval, no plugins, no framing, no <base> or
 * form posts elsewhere. Styles allow inline because React sets style attributes; the one other origin is Google Fonts.
 */
const contentSecurityPolicy = [
  "default-src 'self'",
  "script-src 'self' 'unsafe-inline'",
  "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",   // the Manrope and IBM Plex Mono stylesheet
  "img-src 'self' data: blob:",
  "font-src 'self' https://fonts.gstatic.com",
  "connect-src 'self'",
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'none'",
].join("; ");

/** Sent with every page: no framing (click-jacking), no type sniffing, no referrer leaks, and the policy above. */
const securityHeaders = [
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" },
  { key: "Strict-Transport-Security", value: "max-age=31536000; includeSubDomains" },
  { key: "Content-Security-Policy", value: contentSecurityPolicy },
];

const nextConfig: NextConfig = {
  poweredByHeader: false,
  output: "standalone", // self-contained server for the production image (Dockerfile)
  async headers() {
    return [{ source: "/:path*", headers: securityHeaders }];
  },
  /* config options here */
  cacheComponents: true,
  experimental: {
    sri: { algorithm: "sha256" },   // each script file carries its hash, so a changed file is refused (S6)
  },
  partialPrefetching: true,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
