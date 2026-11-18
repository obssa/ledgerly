/* Sales orders delivered in parts (067): the form fields of an order's invoice, read for the API. */

/** The deliver_<line id> fields of an order's invoice form, as the API takes them; none means all that is left. */
export function deliveries(form: FormData): { line_id: string; quantity: string }[] | undefined {
  const out: { line_id: string; quantity: string }[] = [];
  for (const [key, value] of form.entries()) {
    const m = /^deliver_([0-9a-f-]{36})$/.exec(key);
    if (m && typeof value === "string") out.push({ line_id: m[1], quantity: value.trim().replace(/,/g, "") || "0" });
  }
  return out.length > 0 ? out : undefined;
}
