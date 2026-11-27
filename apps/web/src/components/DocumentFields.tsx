import { Dt } from "@/components/Dt";
import { getDimensions } from "@/lib/data";

/**
 * A document's class and location (when tracked for the whole document) and its custom fields (111), in the same
 * label-and-value rows as its dates and terms. Nothing when it has none.
 */
type Doc = {
  class_id?: string | null; location_id?: string | null; custom_fields?: Record<string, string> | null;
  billing_address?: string | null; shipping_address?: string | null; email_to?: string | null; email_cc?: string | null; email_bcc?: string | null;
  ship_via?: string | null; ship_date?: string | null; tracking_number?: string | null; message_on_statement?: string | null;
};

export async function DocumentFields({ doc }: { doc: Doc }) {
  const custom = Object.entries(doc.custom_fields ?? {}).filter(([, v]) => v !== "");
  // 112: who it goes to and where, and how it ships.
  const text: [string, string | null | undefined][] = [["Billing address", doc.billing_address], ["Ship to", doc.shipping_address], ["Email", doc.email_to],
    ["Cc", doc.email_cc], ["Bcc", doc.email_bcc], ["Ship via", doc.ship_via], ["Tracking no.", doc.tracking_number], ["Message on statement", doc.message_on_statement]];
  const shown = text.filter(([, v]) => v);
  if (!doc.class_id && !doc.location_id && custom.length === 0 && shown.length === 0 && !doc.ship_date) return null;
  const dims = doc.class_id || doc.location_id ? await getDimensions() : [];
  const name = (id: string | null | undefined) => {
    const d = dims.find((x) => x.id === id);
    return d ? `${d.code} ${d.name}` : null;
  };
  return (
    <>
      {name(doc.location_id) && <div><span className="text-muted">Location</span> {name(doc.location_id)}</div>}
      {name(doc.class_id) && <div><span className="text-muted">Class</span> {name(doc.class_id)}</div>}
      {custom.map(([k, v]) => <div key={k}><span className="text-muted">{k}</span> {v}</div>)}
      {shown.map(([k, v]) => <div key={k}><span className="text-muted">{k}</span> <span className="whitespace-pre-line">{v}</span></div>)}
      {doc.ship_date && <div><span className="text-muted">Shipping date</span> <span className="num"><Dt v={doc.ship_date} /></span></div>}
    </>
  );
}
