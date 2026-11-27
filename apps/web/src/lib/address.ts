import type { Address } from "./api";

/** An address as printed on a document, one part to a line: street, then city and region, then postal code and country. */
export function addressText(a: Address | null | undefined): string {
  if (!a) return "";
  return [a.line1, a.line2, [a.city, a.region].filter(Boolean).join(", "), [a.postal_code, a.country].filter(Boolean).join(" ")]
    .map((l) => (l ?? "").trim()).filter((l) => l !== "").join("\n");
}
