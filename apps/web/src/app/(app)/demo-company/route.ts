import { ledgerly } from "@/lib/api";
import { getSession, selectCompany } from "@/lib/session";

/** A demo session moves to the demo company in use now (after its owner started the demo fresh). */
export async function GET(request: Request): Promise<Response> {
  const { token, demo } = await getSession();
  if (!token) return Response.redirect(new URL("/sign-in", request.url), 303);
  if (demo) {
    const { data } = await ledgerly.companies(token);
    const current = data.find((c) => c.status !== "archived");
    if (current) await selectCompany(current.id);
  }
  return new Response(null, { status: 303, headers: { Location: "/" } });
}
