/**
 * The visitor's address, as the server in front of this app saw it (SECURITY.md S16).
 *
 * Only a header written by a server we run can be believed: anything else came from the visitor.
 * - Behind Cloudflare (LEDGERLY_BEHIND_CLOUDFLARE=true), Cloudflare writes CF-Connecting-IP and overwrites whatever
 *   the visitor sent under that name.
 * - Otherwise the web server in front of this app (LiteSpeed on the cPanel host) adds the address it was connected
 *   from to the END of X-Forwarded-For. Everything before it is whatever the visitor chose to send, so the first
 *   entry can be any address at all, and taking it would let one person use a new address for every sign-in attempt.
 *   CF-Connecting-IP is ignored here for the same reason: without Cloudflare in front, the visitor wrote it.
 */
export function clientAddress(header: (name: string) => string | null | undefined, behindCloudflare: boolean): string | undefined {
  if (behindCloudflare) return header("cf-connecting-ip")?.trim() || undefined;
  const entries = (header("x-forwarded-for") ?? "").split(",").map((e) => e.trim()).filter(Boolean);
  return entries.at(-1);
}
