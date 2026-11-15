"use client";

import { useActionState, useState } from "react";
import type { Account, OpeningBalances } from "@/lib/api";
import { formatAmount } from "@/lib/format";
import { isBlank, openingTotals } from "@/lib/opening";
import { saveOpeningBalances } from "./actions";

const GROUPS: { type: Account["type"]; label: string }[] = [
  { type: "asset", label: "Assets" },
  { type: "liability", label: "Liabilities" },
  { type: "equity", label: "Equity" },
  { type: "income", label: "Income (year to date)" },
  { type: "expense", label: "Expenses (year to date)" },
];

type Values = Record<string, { debit: string; credit: string }>;

/** One row per account with a debit and a credit field; totals and the difference update while typing. */
export function OpeningBalancesForm({ opening, accounts }: { opening: OpeningBalances; accounts: Account[] }) {
  const [state, action, pending] = useActionState(saveOpeningBalances, undefined);
  const [values, setValues] = useState<Values>(() =>
    Object.fromEntries(
      opening.lines.map((l) => [l.account_id, { debit: l.debit === "0.00" ? "" : l.debit, credit: l.credit === "0.00" ? "" : l.credit }]),
    ),
  );
  const totals = openingTotals(Object.values(values));
  const readOnly = !opening.can.enter;
  const set = (id: string, side: "debit" | "credit", value: string) =>
    setValues((v) => ({ ...v, [id]: { debit: v[id]?.debit ?? "", credit: v[id]?.credit ?? "", [side]: value } }));
  // The API names errors by the position of the line sent; lines are sent in table order, without blank rows.
  const ordered = GROUPS.flatMap((g) => accounts.filter((a) => a.type === g.type));
  const sent = ordered.filter((a) => !isBlank(values[a.id]?.debit ?? "") || !isBlank(values[a.id]?.credit ?? "")).map((a) => a.id);
  const invalid = (id: string) => {
    const index = sent.indexOf(id);
    return index >= 0 && Boolean(state?.fields && Object.keys(state.fields).some((k) => k.startsWith(`lines.${index}.`)));
  };

  return (
    <form action={action} className="mt-5" noValidate>
      {state?.error && <p role="alert" className="mb-4 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="mb-4 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}

      <div className="overflow-x-auto">
        <table className="w-full min-w-[560px] text-sm">
          <thead>
            <tr className="text-left text-[12px] uppercase tracking-wide text-muted">
              <th className="py-2 font-bold">Account</th>
              <th className="w-[160px] py-2 text-right font-bold">Debit</th>
              <th className="w-[160px] py-2 text-right font-bold">Credit</th>
            </tr>
          </thead>
          {GROUPS.map((group) => {
            const rows = accounts.filter((a) => a.type === group.type);
            if (rows.length === 0) return null;
            return (
              <tbody key={group.type}>
                <tr><th colSpan={3} className="pb-1 pt-4 text-left text-[13px] font-bold">{group.label}</th></tr>
                {rows.map((a) => {
                  const bad = invalid(a.id);
                  return (
                    <tr key={a.id} className={`border-t border-line ${bad ? "bg-bad-tint" : ""}`}>
                      <td className="py-1.5"><span className="num mr-2 text-muted">{a.code}</span>{a.name}</td>
                      {(["debit", "credit"] as const).map((side) => (
                        <td key={side} className="py-1.5 pl-2">
                          <input
                            className="field num text-right"
                            name={`${side}:${a.id}`}
                            inputMode="decimal"
                            aria-label={`${a.name} ${side}`}
                            value={values[a.id]?.[side] ?? ""}
                            onChange={(e) => set(a.id, side, e.target.value)}
                            readOnly={readOnly}
                          />
                        </td>
                      ))}
                    </tr>
                  );
                })}
              </tbody>
            );
          })}
          <tfoot>
            <tr className="border-t-2 border-ink font-bold">
              <td className="py-2">Total</td>
              <td className="num py-2 text-right">{formatAmount(totals.debit)}</td>
              <td className="num py-2 text-right">{formatAmount(totals.credit)}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <p role="status" className={`mt-3 text-sm font-semibold ${totals.difference === "0.00" ? "text-good" : "text-warn"}`}>
        {totals.difference === "0.00"
          ? "Debits equal credits."
          : `Difference ${formatAmount(totals.difference)}: it will go to Opening balance equity and be flagged.`}
      </p>

      {readOnly ? (
        <p className="mt-4 text-sm text-muted">Only an accountant, owner or admin can enter opening balances.</p>
      ) : (
        <button type="submit" className="btn btn-primary mt-4 min-w-[200px] justify-center" disabled={pending}>
          {pending ? "Posting…" : opening.journal_entry ? "Replace opening balances" : "Post opening balances"}
        </button>
      )}
    </form>
  );
}
