import { getDimensions } from "@/lib/data";

/**
 * A document's class and location (when tracked for the whole document) and its custom fields (111), in the same
 * label-and-value rows as its dates and terms. Nothing when it has none.
 */
export async function DocumentFields({ doc }: { doc: { class_id?: string | null; location_id?: string | null; custom_fields?: Record<string, string> | null } }) {
  const custom = Object.entries(doc.custom_fields ?? {}).filter(([, v]) => v !== "");
  if (!doc.class_id && !doc.location_id && custom.length === 0) return null;
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
    </>
  );
}
