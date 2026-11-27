"use client";

import { Dt } from "@/components/Dt";

import { useActionState, useMemo, useState } from "react";
import type { Bill } from "@/lib/api";
import { todayIn } from "@/lib/dates";
import { formatAmount, formatMoney } from "@/lib/format";
import { payBatch } from "../actions";

const METHODS: Record<string, string> = { bank_transfer: "Bank transfer", mobile_money: "Mobile money (telebirr, M-PESA …)", cash: "Cash", card: "Card", other: "Other" };

/** The pay run: open bills by supplier, ticked to pay, all or part; tax withheld per supplier with its receipt number. */
export function BatchPayForm({ bills, accounts, currency }: { bills: Bill[]; accounts: { id: string; code: string; name: string }[]; currency: string }) {
  const [state, action, pending] = useActionState(payBatch, undefined);
  const [ticked, setTicked] = useState<Set<string>>(new Set());
  const [amounts, setAmounts] = useState<Record<string, string>>({});
  const today = todayIn();
  const suppliers = useMemo(() => {
    const by = new Map<string, { id: string; name: string; bills: Bill[] }>();
    for (const b of bills) {
      const s = by.get(b.contact.id) ?? { id: b.contact.id, name: b.contact.name, bills: [] };
      s.bills.push(b);
      by.set(b.contact.id, s);
    }
    return [...by.values()].sort((a, b) => a.name.localeCompare(b.name));
  }, [bills]);
  const total = bills.filter((b) => ticked.has(b.id)).reduce((s, b) => s + Number((amounts[b.id] ?? "").replace(/,/g, "") || b.amount_due), 0);
  const toggle = (id: string) => setTicked((t) => {
    const n = new Set(t);
    if (n.has(id)) n.delete(id); else n.add(id);
    return n;
  });

  return (
    <form action={action} noValidate>
      {state?.error && <p role="alert" className="mb-4 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      <input type="hidden" name="currency" value={currency} />
      <input type="hidden" name="suppliers" value={JSON.stringify(Object.fromEntries(bills.map((b) => [b.id, b.contact.id])))} />
      <div className="mb-5 grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-[18px]">
        <div>
          <label className="field-label" htmlFor="account_id">Pay from</label>
          <select className="field" id="account_id" name="account_id" required>
            {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
          </select>
        </div>
        <div><label className="field-label" htmlFor="payment_date">Date</label><input className="field num" id="payment_date" name="payment_date" type="date" max={today} defaultValue={today} /></div>
        <div>
          <label className="field-label" htmlFor="method">Method</label>
          <select className="field" id="method" name="method" defaultValue="bank_transfer">
            {Object.entries(METHODS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </div>
        <div><label className="field-label" htmlFor="reference">Reference (optional)</label><input className="field" id="reference" name="reference" maxLength={100} placeholder="RUN-11" /></div>
      </div>

      {suppliers.length === 0 ? (
        <p className="text-sm text-muted">No bills are open for payment in {currency}.</p>
      ) : suppliers.map((s) => (
        <fieldset key={s.id} className="mb-4 rounded-xl border border-line p-3">
          <legend className="px-1 text-[15px] font-bold">{s.name}</legend>
          <table className="w-full text-sm">
            <thead><tr className="text-left text-[12px] text-muted"><th className="w-8 py-1"><span className="sr-only">Pay</span></th><th className="py-1">Bill</th><th className="py-1">Due</th><th className="py-1 text-right">Owed</th><th className="py-1 text-right">Pay</th></tr></thead>
            <tbody>
              {s.bills.map((b) => (
                <tr key={b.id} className="border-t border-line">
                  <td className="py-1.5"><input type="checkbox" name={`bill.${b.id}`} aria-label={`Pay ${b.number ?? "bill"}`} checked={ticked.has(b.id)} onChange={() => toggle(b.id)} /></td>
                  <td className="py-1.5">{b.number}{b.reference && <span className="ml-1 text-[12px] text-muted">{b.reference}</span>}</td>
                  <td className="num py-1.5"><Dt v={b.due_date} /></td>
                  <td className="num py-1.5 text-right">{formatAmount(b.amount_due)}</td>
                  <td className="py-1.5 text-right">
                    <label className="sr-only" htmlFor={`amount-${b.id}`}>Amount to pay on {b.number}</label>
                    <input className="field num ml-auto w-[120px] text-right" id={`amount-${b.id}`} name={`amount.${b.id}`} inputMode="decimal" placeholder={formatAmount(b.amount_due)}
                      value={amounts[b.id] ?? ""} onChange={(e) => setAmounts((a) => ({ ...a, [b.id]: e.target.value }))} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="mt-2 flex flex-wrap items-end gap-2">
            <div><label className="field-label" htmlFor={`wh-${s.id}`}>Tax withheld (optional)</label><input className="field num w-[120px]" id={`wh-${s.id}`} name={`wh.${s.id}`} inputMode="decimal" /></div>
            <div><label className="field-label" htmlFor={`whcert-${s.id}`}>Withholding receipt</label><input className="field w-[160px]" id={`whcert-${s.id}`} name={`whcert.${s.id}`} maxLength={60} /></div>
          </div>
        </fieldset>
      ))}

      {suppliers.length > 0 && (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm">Ticked: <span className="num font-bold">{formatMoney(total.toFixed(2), currency)}</span> before tax withheld. One payment per supplier; all are made, or none.</p>
          <button type="submit" className="btn btn-primary" disabled={pending || ticked.size === 0}>{pending ? "Paying…" : "Pay the bills ticked"}</button>
        </div>
      )}
    </form>
  );
}
