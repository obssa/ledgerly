import Link from "@/components/Link";
import type { PaymentTerm } from "@/lib/api";
import { describeTerm } from "@/lib/terms";

/**
 * A customer's or supplier's usual term (QuickBooks' Terms, 109): the active terms, plus the one they have if it was
 * turned off since. Their invoices or bills take it, and it sets the due date. One saved before the Terms list without
 * a term of its days keeps its days ("None").
 */
export function TermsSelect({ terms, value, days, invalid }: { terms: PaymentTerm[]; value?: string | null; days?: number; invalid?: boolean }) {
  const shown = terms.filter((t) => t.is_active || t.id === value);
  const fallback = value ? "" : shown.find((t) => t.kind === "days" && t.days === (days ?? 30))?.id ?? "";
  return (
    <div>
      <label className="field-label" htmlFor="payment_terms_id">Terms</label>
      <select className={`field ${invalid ? "!border-bad" : ""}`} id="payment_terms_id" name="payment_terms_id" defaultValue={value ?? fallback} aria-describedby="payment_terms_hint">
        {!value && !fallback && <option value="">None ({days ?? 30} days)</option>}
        {shown.map((t) => <option key={t.id} value={t.id} title={describeTerm(t)}>{t.name}{t.is_active ? "" : " (turned off)"}</option>)}
      </select>
      <p id="payment_terms_hint" className="mt-1.5 text-[13px] text-muted">
        Sets the due date on their documents · <Link href="/settings#terms" className="font-semibold text-primary hover:underline">Terms list</Link>
      </p>
    </div>
  );
}

/** How a contact's terms read in a list: the term's name, else its days. */
export function termsLabel(terms: PaymentTerm[], id: string | null | undefined, days: number): string {
  return terms.find((t) => t.id === id)?.name ?? (days === 0 ? "On receipt" : `${days} days`);
}
