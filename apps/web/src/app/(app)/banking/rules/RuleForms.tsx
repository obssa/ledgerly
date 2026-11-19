"use client";

import { useActionState } from "react";
import type { BankRule } from "@/lib/api";
import { formatAmount } from "@/lib/format";
import { bankRuleStep, saveBankRule } from "../actions";

const DIRECTION = { any: "Money in or out", in: "Money in", out: "Money out" } as const;

/** 076: a new bank rule; from a statement line, its words, account and direction come filled in. */
export function BankRuleForm({ money, targets, contains, account, direction }: {
  money: { id: string; label: string }[]; targets: { id: string; label: string }[]; contains?: string; account?: string; direction?: string;
}) {
  const [state, action, pending] = useActionState(saveBankRule, undefined);
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  return (
    <form action={action} noValidate className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3">
      {state?.error && <p role="alert" className="col-span-full rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="col-span-full rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      <div>
        <label className="field-label" htmlFor="rule-name">Name</label>
        <input className={`field ${bad("name") ? "!border-bad" : ""}`} id="rule-name" name="name" maxLength={120} placeholder="Monthly service charge" />
      </div>
      <div>
        <label className="field-label" htmlFor="rule-contains">When the description has</label>
        <input className={`field ${bad("contains") ? "!border-bad" : ""}`} id="rule-contains" name="contains" maxLength={120} defaultValue={contains ?? ""} placeholder="SERVICE CHARGE" />
      </div>
      <div>
        <label className="field-label" htmlFor="rule-direction">For</label>
        <select className="field" id="rule-direction" name="direction" defaultValue={direction === "in" || direction === "out" ? direction : "any"}>
          {Object.entries(DIRECTION).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
      </div>
      <div>
        <label className="field-label" htmlFor="rule-bank">On</label>
        <select className={`field ${bad("bank_account_id") ? "!border-bad" : ""}`} id="rule-bank" name="bank_account_id" defaultValue={account ?? ""}>
          <option value="">Any money account</option>
          {money.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
        </select>
      </div>
      <div>
        <label className="field-label" htmlFor="rule-min">Amount from (optional)</label>
        <input className={`field num ${bad("amount_min") ? "!border-bad" : ""}`} id="rule-min" name="amount_min" inputMode="decimal" />
      </div>
      <div>
        <label className="field-label" htmlFor="rule-max">Amount up to (optional)</label>
        <input className={`field num ${bad("amount_max") ? "!border-bad" : ""}`} id="rule-max" name="amount_max" inputMode="decimal" />
      </div>
      <div>
        <label className="field-label" htmlFor="rule-target">Categorise to</label>
        <select className={`field ${bad("target_account_id") ? "!border-bad" : ""}`} id="rule-target" name="target_account_id" defaultValue="">
          <option value="" disabled>Choose…</option>
          {targets.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
        </select>
      </div>
      <div>
        <label className="field-label" htmlFor="rule-memo">Memo (optional)</label>
        <input className="field" id="rule-memo" name="memo" maxLength={255} placeholder="The line's own words" />
      </div>
      <div>
        <label className="field-label" htmlFor="rule-priority">Priority</label>
        <input className="field num" id="rule-priority" name="priority" inputMode="numeric" defaultValue="100" />
      </div>
      <div className="col-span-full"><button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Saving…" : "Add rule"}</button></div>
    </form>
  );
}

/** One rule in the list, with archive, restore and delete for those who review statements. */
export function BankRuleRow({ rule, manage }: { rule: BankRule; manage: boolean }) {
  const [state, action, pending] = useActionState(bankRuleStep, undefined);
  const range = rule.amount_min || rule.amount_max
    ? `${rule.amount_min ? formatAmount(rule.amount_min) : "0"} – ${rule.amount_max ? formatAmount(rule.amount_max) : "any"}` : "Any";
  return (
    <tr className={rule.is_active ? "" : "text-muted"}>
      <td className="font-semibold">{rule.name}{!rule.is_active && <span className="ml-1 font-normal">(archived)</span>}</td>
      <td className="num">&ldquo;{rule.contains}&rdquo; <span className="text-muted">· {DIRECTION[rule.direction]}</span></td>
      <td>{rule.bank_account ?? "Any"}</td>
      <td className="num">{range}</td>
      <td><span className="num">{rule.target_code}</span> {rule.target_name}{rule.contact_name ? ` · ${rule.contact_name}` : ""}</td>
      <td className="num text-right">{rule.priority}</td>
      <td>
        {manage && (
          <form action={action} className="flex gap-1">
            <input type="hidden" name="id" value={rule.id} />
            <button type="submit" name="step" value={rule.is_active ? "archive" : "restore"} className="btn !px-2 !py-1 text-[13px]" disabled={pending}>
              {rule.is_active ? "Archive" : "Restore"}
            </button>
            <button type="submit" name="step" value="delete" className="btn !px-2 !py-1 text-[13px]" disabled={pending}>Delete</button>
          </form>
        )}
        {state?.error && <p role="alert" className="mt-1 text-[12px] text-bad">{state.error}</p>}
      </td>
    </tr>
  );
}
