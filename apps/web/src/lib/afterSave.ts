import { ledgerly, type TagKind } from "./api";
import type { FormState } from "./forms";

/**
 * What a new invoice, bill or expense brings with it from its form (QuickBooks: tags and attachments while creating,
 * 112): its tags, set once it is saved; and, when files were chosen, the page it opens on once the browser has sent
 * them to the upload route (a file is not sent through a Server Function, so its 10 MB pass as they are).
 * A tag the role may not set is left for the document's page; the document itself is saved either way.
 */
export async function afterSave(kind: TagKind & ("invoice" | "bill" | "expense"), id: string, form: FormData, ctx: Parameters<typeof ledgerly.setTransactionTags>[3], href: string): Promise<FormState | null> {
  const tags = form.getAll("tag_ids").filter((t): t is string => typeof t === "string" && /^[0-9a-f-]{36}$/i.test(t));
  if (tags.length > 0) {
    try {
      await ledgerly.setTransactionTags(kind, id, tags, ctx);
    } catch {
      // Kept for the document's page, where the tags panel says why.
    }
  }
  return form.get("attach_pending") === "1" ? { next: { kind, id, href } } : null;
}
