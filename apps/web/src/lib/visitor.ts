import "server-only";
import { headers } from "next/headers";
import { clientAddress } from "./clientAddress";

/**
 * Tells the API which visitor a call is made for (SECURITY.md S16). The web server makes every API call, so
 * without this the API's sign-in limits would count all visitors as one address, and every signed-in device
 * would look like the web server. The API believes the address and browser only together with
 * LEDGERLY_WEB_KEY (its WEB_SERVER_KEY). Which header holds an address the visitor cannot choose: clientAddress.ts.
 */
export async function visitorHeaders(): Promise<Record<string, string>> {
  const key = process.env.LEDGERLY_WEB_KEY;
  if (!key) return {};
  const incoming = await headers();
  const address = clientAddress((name) => incoming.get(name), process.env.LEDGERLY_BEHIND_CLOUDFLARE === "true");
  const agent = incoming.get("user-agent")?.slice(0, 500);
  if (!address && !agent) return {};
  return {
    "X-Ledgerly-Web-Key": key,
    ...(address ? { "X-Ledgerly-Client-Address": address } : {}),
    ...(agent ? { "X-Ledgerly-Client-Agent": agent } : {}),
  };
}
