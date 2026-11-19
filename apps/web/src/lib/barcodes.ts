/**
 * 085: what a barcode scanned or typed names, among the items a form offers: an item (in its own unit) or one of its
 * other units. Within a company a barcode names one thing, so the first match is the only one.
 */
export function findBarcode(
  items: { id: string; barcode?: string | null; units?: { id: string; barcode?: string | null }[] }[],
  code: string,
): { itemId: string; unitId: string | null } | null {
  const c = code.trim();
  if (c === "") return null;
  for (const item of items) {
    if (item.barcode === c) return { itemId: item.id, unitId: null };
    const unit = (item.units ?? []).find((u) => u.barcode === c);
    if (unit) return { itemId: item.id, unitId: unit.id };
  }
  return null;
}
