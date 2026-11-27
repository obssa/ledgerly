"use client";

import { Dt } from "@/components/Dt";

import Link from "@/components/Link";
import { useActionState } from "react";
import { Card } from "@/components/ui";
import type { LateInterestPreview } from "@/lib/api";
import { todayIn } from "@/lib/dates";
import { formatAmount } from "@/lib/format";
import { chargeInterest } from "../actions";

/**
 * Module 2: interest on late payment (079). At the company's rate, simple interest on what this overdue invoice still
 * owes, from its due date (or the end of the last charge), charged on a new draft invoice to the customer.
 */
export function InterestPanel({ invoiceId, interest }: { invoiceId: string; interest: LateInterestPreview }) {
  const [state, charge, charging] = useActionState(chargeInterest, undefined);
  const today = todayIn();
  const canCharge = interest.can.charge && interest.rate !== null && Number(interest.amount) > 0;
  return (
    <Card label="Interest on late payment">
      <h2 className="text-[17px] font-bold">Interest on late payment</h2>
      {state?.error && <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {interest.charged.length > 0 && (
        <ul className="mt-2 flex flex-col gap-1 text-[13px]">
          {interest.charged.map((c) => (
            <li key={c.id}>
              <Link href={`/invoices/${encodeURIComponent(c.id)}`} className="font-semibold underline">{c.number ?? "Draft"}</Link>{" "}
              <span className="num">{formatAmount(c.total)}</span> for <span className="num"><Dt v={c.from} /></span> to <span className="num"><Dt v={c.through} /></span>
              {c.status === "void" ? " (void)" : c.status === "draft" ? " (draft)" : ""}
            </li>
          ))}
        </ul>
      )}
      {canCharge && (
        <>
          <p className="mt-2 text-[13px] text-muted">
            {interest.days} days from <span className="num"><Dt v={interest.from} /></span> at {interest.rate}% a year on <span className="num">{formatAmount(interest.owed)}</span> owed:{" "}
            <span className="num font-semibold text-ink">{formatAmount(interest.amount)} {interest.currency}</span>.
          </p>
          <form action={charge} className="mt-3 flex flex-wrap items-end gap-2">
            <input type="hidden" name="id" value={invoiceId} />
            <div><label className="field-label" htmlFor="li-through">Up to</label><input className="field" id="li-through" name="through" type="date" min={interest.from ?? undefined} max={today} defaultValue={today} /></div>
            <button type="submit" className="btn" disabled={charging}>{charging ? "Charging…" : "Charge interest"}</button>
          </form>
          <p className="mt-2 text-[12px] text-muted">Makes a draft invoice to the customer; nothing posts until it is approved.</p>
        </>
      )}
    </Card>
  );
}
