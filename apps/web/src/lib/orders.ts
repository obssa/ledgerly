/* Sales orders delivered in parts (067): the form fields of an order's invoice, read for the API. */

/**
 * The deliver_<line id> fields of an order's invoice or bill form (or receive_<line id> of a goods received note, 069),
 * as the API takes them; none means all that is left.
 */
export function deliveries(form: FormData, prefix: "deliver" | "receive" = "deliver"): { line_id: string; quantity: string }[] | undefined {
  const out: { line_id: string; quantity: string }[] = [];
  const field = prefix === "receive" ? /^receive_([0-9a-f-]{36})$/ : /^deliver_([0-9a-f-]{36})$/;
  for (const [key, value] of form.entries()) {
    const m = field.exec(key);
    if (m && typeof value === "string") out.push({ line_id: m[1], quantity: value.trim().replace(/,/g, "") || "0" });
  }
  return out.length > 0 ? out : undefined;
}
