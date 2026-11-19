/**
 * Module 3: batch payments. Turns the pay-run form into the API's body: each bill ticked (`bill.<id>`), at the amount
 * typed (`amount.<id>`, or all it still owes when left empty), and the tax withheld from a supplier (`wh.<contactId>`)
 * with its receipt number (`whcert.<contactId>`), only for a supplier with a bill ticked.
 */
export function batchBody(form: FormData, billSuppliers: Record<string, string>): {
  bills: { bill_id: string; amount?: string }[];
  withholding: { contact_id: string; amount: string; certificate: string }[];
} {
  const value = (name: string) => {
    const v = form.get(name);
    return typeof v === "string" ? v.trim() : "";
  };
  const bills: { bill_id: string; amount?: string }[] = [];
  const paid = new Set<string>();
  for (const [name] of form.entries()) {
    if (!name.startsWith("bill.")) continue;
    const id = name.slice("bill.".length);
    if (!(id in billSuppliers)) continue;
    const amount = value(`amount.${id}`).replace(/[,\s]/g, "");
    bills.push(amount === "" ? { bill_id: id } : { bill_id: id, amount });
    paid.add(billSuppliers[id]);
  }
  const withholding: { contact_id: string; amount: string; certificate: string }[] = [];
  for (const contactId of paid) {
    const amount = value(`wh.${contactId}`).replace(/[,\s]/g, "");
    if (amount === "" || Number(amount) === 0) continue;
    withholding.push({ contact_id: contactId, amount, certificate: value(`whcert.${contactId}`) });
  }
  return { bills, withholding };
}
