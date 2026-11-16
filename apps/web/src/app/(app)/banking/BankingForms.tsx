"use client";

import { useActionState, useState } from "react";
import type { BankAccount } from "@/lib/api";
import type { FormState } from "@/lib/forms";
import { createBankAccount, depositReceipts, saveBankAccount, transferMoney } from "./actions";
import { todayIn } from "@/lib/dates";

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="mb-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="mb-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

function Field({ name, label, type = "text", defaultValue = "", hint, invalid }: { name: string; label: string; type?: string; defaultValue?: string; hint?: string; invalid?: boolean }) {
  return (
    <div>
      <label className="field-label" htmlFor={name}>{label}</label>
      <input className={`field ${invalid ? "!border-bad" : ""}`} id={name} name={name} type={type} defaultValue={defaultValue} aria-describedby={hint ? `${name}-hint` : undefined} />
      {hint && <p id={`${name}-hint`} className="mt-1.5 text-[13px] text-muted">{hint}</p>}
    </div>
  );
}

const KINDS: Record<string, string> = { bank: "Bank account", mobile_money: "Mobile-money wallet", cash: "Cash box or petty cash" };

export function NewBankAccount({ currencies }: { currencies: string[] }) {
  const [state, action, pending] = useActionState(createBankAccount, undefined);
  const bad = (n: string) => Boolean(state?.fields && Object.keys(state.fields).some((k) => k === n || k.startsWith(`${n}.`)));
  return (
    <form action={action} noValidate className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
      <div className="col-span-full"><Message state={state} /></div>
      <Field name="name" label="Name" invalid={bad("name")} hint="For example: CBE Bole current account" />
      <div>
        <label className="field-label" htmlFor="kind">Kind</label>
        <select className="field" id="kind" name="kind">{Object.entries(KINDS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
      </div>
      <div>
        <label className="field-label" htmlFor="currency">Currency</label>
        <select className={`field ${bad("currency") ? "!border-bad" : ""}`} id="currency" name="currency">{currencies.map((c) => <option key={c} value={c}>{c}</option>)}</select>
      </div>
      <Field name="bank_details.bank_name" label="Bank" />
      <Field name="bank_details.account_number" label="Account number" invalid={bad("bank_details")} />
      <Field name="bank_details.branch" label="Branch" />
      <Field name="bank_details.provider" label="Wallet provider" hint="telebirr, M-PESA, CBE Birr" />
      <Field name="bank_details.wallet_number" label="Wallet number" />
      <div className="col-span-full"><button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Opening…" : "Open account"}</button></div>
    </form>
  );
}

export function EditBankAccount({ account }: { account: BankAccount }) {
  const [state, action, pending] = useActionState(saveBankAccount, undefined);
  const d = account.bank_details ?? {};
  return (
    <form action={action} noValidate className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
      <div className="col-span-full"><Message state={state} /></div>
      <input type="hidden" name="id" value={account.id} />
      <Field name="name" label="Name" defaultValue={account.name} />
      <Field name="bank_details.bank_name" label="Bank" defaultValue={d.bank_name ?? ""} />
      <Field name="bank_details.account_number" label="Account number" defaultValue={d.account_number ?? ""} />
      <Field name="bank_details.branch" label="Branch" defaultValue={d.branch ?? ""} />
      <Field name="bank_details.provider" label="Wallet provider" defaultValue={d.provider ?? ""} />
      <Field name="bank_details.wallet_number" label="Wallet number" defaultValue={d.wallet_number ?? ""} />
      <p className="col-span-full text-[13px] text-muted">A change of bank details is recorded in the audit log with the old and new details.</p>
      <div className="col-span-full"><button type="submit" className="btn" disabled={pending}>Save</button></div>
    </form>
  );
}

export function ArchiveBankAccount({ account }: { account: BankAccount }) {
  const [state, action, pending] = useActionState(saveBankAccount, undefined);
  return (
    <form action={action}>
      <Message state={state} />
      <input type="hidden" name="id" value={account.id} />
      <input type="hidden" name="is_active" value={account.is_active ? "false" : "true"} />
      <button type="submit" className="btn" disabled={pending}>{account.is_active ? "Archive account" : "Restore account"}</button>
    </form>
  );
}

/** A transfer between two of the company's money accounts. Across currencies: what arrived, at the day's rate unless given. */
export function TransferMoney({ accounts, base }: { accounts: BankAccount[]; base: string }) {
  const [state, action, pending] = useActionState(transferMoney, undefined);
  const today = todayIn();
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  const [fromId, setFromId] = useState(accounts[0]?.id ?? "");
  const [toId, setToId] = useState(accounts[1]?.id ?? "");
  const from = accounts.find((a) => a.id === fromId);
  const to = accounts.find((a) => a.id === toId);
  const across = Boolean(from && to && from.currency !== to.currency);
  const foreign = [from?.currency, to?.currency].find((c) => c && c !== base);
  const label = (a: BankAccount) => `${a.code} ${a.name}${a.currency !== base ? ` (${a.currency})` : ""}`;
  return (
    <form action={action} noValidate className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] items-end gap-3">
      <div className="col-span-full"><Message state={state} /></div>
      <div>
        <label className="field-label" htmlFor="from_account_id">From</label>
        <select className={`field ${bad("from_account_id") ? "!border-bad" : ""}`} id="from_account_id" name="from_account_id" value={fromId} onChange={(e) => setFromId(e.target.value)}>
          {accounts.map((a) => <option key={a.id} value={a.id}>{label(a)}</option>)}
        </select>
      </div>
      <div>
        <label className="field-label" htmlFor="to_account_id">To</label>
        <select className={`field ${bad("to_account_id") ? "!border-bad" : ""}`} id="to_account_id" name="to_account_id" value={toId} onChange={(e) => setToId(e.target.value)}>
          {accounts.map((a) => <option key={a.id} value={a.id}>{label(a)}</option>)}
        </select>
      </div>
      <Field name="amount" label={`Amount sent${from ? ` (${from.currency})` : ""}`} invalid={bad("amount")} />
      {across && <Field name="amount_received" label={`Amount received${to ? ` (${to.currency})` : ""}`} invalid={bad("amount_received")} />}
      {(across || foreign) && <Field name="fx_rate" label={`Rate (${base} per ${foreign ?? ""})`} invalid={bad("fx_rate")} hint="Leave empty for the day's rate" />}
      <Field name="fee" label={`Fee${from ? ` (${from.currency})` : ""}`} />
      <Field name="date" label="Date" type="date" defaultValue={today} invalid={bad("date")} />
      <Field name="memo" label="Memo" />
      {across && <p className="col-span-full text-[13px] text-muted">Both sides are valued at the rate of the day; what the bank kept in its rate is booked as an exchange gain or loss.</p>}
      <button type="submit" className="btn" disabled={pending}>{pending ? "Posting…" : "Transfer"}</button>
    </form>
  );
}

export function DepositReceipts({ banks }: { banks: BankAccount[] }) {
  const [state, action, pending] = useActionState(depositReceipts, undefined);
  const today = todayIn();
  return (
    <form action={action} noValidate className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] items-end gap-3">
      <div className="col-span-full"><Message state={state} /></div>
      <div>
        <label className="field-label" htmlFor="bank_account_id">Into</label>
        <select className="field" id="bank_account_id" name="bank_account_id">{banks.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}</select>
      </div>
      <div>
        <label className="field-label" htmlFor="receipts">Receipts on the slip</label>
        <textarea className="field min-h-[42px]" id="receipts" name="receipts" placeholder={"1,200.00\n350.00"} />
      </div>
      <Field name="slip_number" label="Deposit slip" />
      <Field name="fee" label="Bank fee" />
      <Field name="date" label="Date" type="date" defaultValue={today} />
      <button type="submit" className="btn" disabled={pending}>{pending ? "Posting…" : "Deposit"}</button>
    </form>
  );
}
