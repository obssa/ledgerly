"use client";

import { useActionState, useState } from "react";
import type { ChartAccount } from "@/lib/api";
import { KIND_LABELS, TYPE_LABELS, type AccountType } from "@/lib/chart";
import type { FormState } from "@/lib/forms";
import { addAccount, changeAccount } from "./actions";

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="mb-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="mb-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

const label = (a: ChartAccount) => `${a.code} ${a.name}`;

/** Add an account: its code, name, type, kind, and the account it sits under, if any. */
export function AddAccount({ accounts, kinds }: { accounts: ChartAccount[]; kinds: Record<string, string[]> }) {
  const [state, action, pending] = useActionState(addAccount, undefined);
  const [type, setType] = useState<AccountType>("expense");
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  const parents = accounts.filter((a) => a.type === type && a.is_active && (a.depth ?? 0) < 3);
  return (
    <form action={action} noValidate className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] items-end gap-3">
      <div className="col-span-full"><Message state={state} /></div>
      <div>
        <label className="field-label" htmlFor="code">Code</label>
        <input className={`field num ${bad("code") ? "!border-bad" : ""}`} id="code" name="code" maxLength={20} placeholder="6150" />
      </div>
      <div>
        <label className="field-label" htmlFor="name">Name</label>
        <input className={`field ${bad("name") ? "!border-bad" : ""}`} id="name" name="name" maxLength={120} />
      </div>
      <div>
        <label className="field-label" htmlFor="type">Type</label>
        <select className="field" id="type" name="type" value={type} onChange={(e) => setType(e.target.value as AccountType)}>
          {(Object.keys(TYPE_LABELS) as AccountType[]).map((t) => <option key={t} value={t}>{TYPE_LABELS[t]}</option>)}
        </select>
      </div>
      <div>
        <label className="field-label" htmlFor="kind">Kind</label>
        <select className={`field ${bad("kind") ? "!border-bad" : ""}`} id="kind" name="kind" key={type} defaultValue="">
          <option value="">General</option>
          {(kinds[type] ?? []).map((k) => <option key={k} value={k}>{KIND_LABELS[k] ?? k}</option>)}
        </select>
      </div>
      <div>
        <label className="field-label" htmlFor="parent_id">Under</label>
        <select className={`field ${bad("parent_id") ? "!border-bad" : ""}`} id="parent_id" name="parent_id" key={`p-${type}`} defaultValue="">
          <option value="">Top level</option>
          {parents.map((a) => <option key={a.id} value={a.id}>{label(a)}</option>)}
        </select>
      </div>
      <div><button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Adding…" : "Add account"}</button></div>
      <p className="col-span-full text-[13px] text-muted">Bank, wallet and cash accounts are opened under Banking and cash.</p>
    </form>
  );
}

/** Change an account: name, code, kind and where it sits; archive it when it holds nothing. */
export function EditAccount({ account, accounts, kinds }: { account: ChartAccount; accounts: ChartAccount[]; kinds: Record<string, string[]> }) {
  const [state, action, pending] = useActionState(changeAccount, undefined);
  const [archiveState, archive, archiving] = useActionState(changeAccount, undefined);
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  const parents = accounts.filter((a) => a.type === account.type && a.is_active && a.id !== account.id && (a.depth ?? 0) < 3);
  return (
    <div className="grid gap-5">
      <form action={action} noValidate className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] items-end gap-3">
        <div className="col-span-full"><Message state={state} /></div>
        <input type="hidden" name="id" value={account.id} />
        <div>
          <label className="field-label" htmlFor="code">Code</label>
          <input className={`field num ${bad("code") ? "!border-bad" : ""}`} id="code" name="code" maxLength={20} defaultValue={account.code} />
        </div>
        <div>
          <label className="field-label" htmlFor="name">Name</label>
          <input className={`field ${bad("name") ? "!border-bad" : ""}`} id="name" name="name" maxLength={120} defaultValue={account.name} />
        </div>
        {!account.is_system && !account.is_money && (
          <div>
            <label className="field-label" htmlFor="kind">Kind</label>
            <select className={`field ${bad("kind") ? "!border-bad" : ""}`} id="kind" name="kind" defaultValue={account.kind ?? ""}>
              <option value="">General</option>
              {(kinds[account.type] ?? []).map((k) => <option key={k} value={k}>{KIND_LABELS[k] ?? k}</option>)}
            </select>
          </div>
        )}
        <div>
          <label className="field-label" htmlFor="parent_id">Under</label>
          <select className={`field ${bad("parent_id") ? "!border-bad" : ""}`} id="parent_id" name="parent_id" defaultValue={account.parent_id ?? ""}>
            <option value="">Top level</option>
            {parents.map((a) => <option key={a.id} value={a.id}>{label(a)}</option>)}
          </select>
        </div>
        <div><button type="submit" className="btn btn-primary" disabled={pending}>Save</button></div>
      </form>
      {!account.is_system && (
        <form action={archive} className="flex flex-wrap items-center gap-3">
          <Message state={archiveState} />
          <input type="hidden" name="id" value={account.id} />
          <input type="hidden" name="is_active" value={account.is_active ? "false" : "true"} />
          <button type="submit" className="btn" disabled={archiving}>{account.is_active ? "Archive account" : "Restore account"}</button>
          {account.is_active && <span className="text-[13px] text-muted">Only an account that holds nothing, with nothing active under it, is archived. Accounts are never deleted.</span>}
        </form>
      )}
    </div>
  );
}
