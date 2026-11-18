"use client";

import { useActionState, useState } from "react";
import { enterOpeningDocument } from "./actions";

type Option = { id: string; name: string };

/** One open invoice or bill from the old books: who, its number, dates, currency and the amount still open. */
export function OpeningDocumentForm({ conversionDate, customers, suppliers, currencies }: {
  conversionDate: string; customers: Option[]; suppliers: Option[]; currencies: string[];
}) {
  const [state, action, pending] = useActionState(enterOpeningDocument, undefined);
  const [type, setType] = useState<"invoice" | "bill">("invoice");
  const bad = (name: string) => (state?.fields?.[name] ? "!border-bad" : "");
  const people = type === "bill" ? suppliers : customers;
  const who = type === "bill" ? "supplier_id" : "customer_id";

  return (
    <form action={action} className="mt-4 grid gap-3 rounded-xl border border-line p-3">
      {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      {state?.fields && (
        <ul role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] text-bad">
          {Object.entries(state.fields).map(([k, m]) => <li key={k}>{m.join(" ")}</li>)}
        </ul>
      )}
      <fieldset className="flex flex-wrap gap-4 text-sm">
        <legend className="field-label">Bring in</legend>
        {(["invoice", "bill"] as const).map((t) => (
          <label key={t} className="flex items-center gap-2">
            <input type="radio" name="type" value={t} checked={type === t} onChange={() => setType(t)} className="accent-brand" />
            {t === "invoice" ? "An invoice a customer still owed" : "A bill still owed to a supplier"}
          </label>
        ))}
      </fieldset>
      <div className="flex flex-wrap gap-2.5">
        <div className="min-w-[220px] flex-1">
          <label className="field-label" htmlFor={who}>{type === "bill" ? "Supplier" : "Customer"}</label>
          <select className={`field ${bad(who)}`} id={who} name={who} key={type}>
            <option value="">Choose…</option>
            {people.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </div>
        {type === "bill" && (
          <div>
            <label className="field-label" htmlFor="reference">Supplier&apos;s invoice number</label>
            <input className={`field ${bad("reference")}`} id="reference" name="reference" maxLength={60} />
          </div>
        )}
        <div>
          <label className="field-label" htmlFor="number">{type === "bill" ? "Our number (if numbered by hand)" : "Invoice number (from the old books)"}</label>
          <input className={`field num ${bad("number")}`} id="number" name="number" maxLength={40} placeholder={type === "bill" ? "" : "OLD-0042"} />
        </div>
      </div>
      <div className="flex flex-wrap gap-2.5">
        <div>
          <label className="field-label" htmlFor="issue_date">Issued on</label>
          <input className={`field ${bad("issue_date")}`} id="issue_date" name="issue_date" type="date" max={conversionDate} />
        </div>
        <div>
          <label className="field-label" htmlFor="due_date">Due on</label>
          <input className={`field ${bad("due_date")}`} id="due_date" name="due_date" type="date" />
        </div>
        <div>
          <label className="field-label" htmlFor="currency">Currency</label>
          <select className="field" id="currency" name="currency" defaultValue={currencies[0]}>
            {currencies.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
        <div>
          <label className="field-label" htmlFor="amount">Still open on {conversionDate}</label>
          <input className={`field num ${bad("amount")}`} id="amount" name="amount" inputMode="decimal" />
        </div>
      </div>
      <div>
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Bringing in…" : type === "bill" ? "Bring in the bill" : "Bring in the invoice"}</button>
      </div>
    </form>
  );
}
