"use client";

import { useActionState } from "react";
import type { ProjectGrant } from "@/lib/api";
import { saveGrant } from "./actions";

type Option = { id: string; code: string; name: string };

const BLANK_ROWS = 4;

/** 093: set a project up as a donor's grant: the donor, the award, and its budget, one line per expense account. */
export function GrantForm({ projectId, grant, donors, accounts }: { projectId: string; grant: ProjectGrant | null; donors: { id: string; name: string }[]; accounts: Option[] }) {
  const [state, action, pending] = useActionState(saveGrant, undefined);
  const rows = [...(grant?.lines ?? []).map((l) => ({ account_id: l.account_id, amount: l.amount })), ...Array.from({ length: BLANK_ROWS }, () => ({ account_id: "", amount: "" }))];
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  return (
    <form action={action} noValidate className="grid gap-3">
      {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      <input type="hidden" name="id" value={projectId} />
      <div className="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-3">
        <div>
          <label className="field-label" htmlFor="grant-donor">Donor</label>
          <select className="field" id="grant-donor" name="donor_id" defaultValue={grant?.donor?.id ?? ""}>
            <option value="">—</option>
            {donors.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
          </select>
        </div>
        <div><label className="field-label" htmlFor="grant-award">Award</label><input className={`field num ${bad("award") ? "!border-bad" : ""}`} id="grant-award" name="award" inputMode="decimal" defaultValue={grant?.award ?? ""} /></div>
        <div><label className="field-label" htmlFor="grant-ref">Donor&rsquo;s reference</label><input className="field" id="grant-ref" name="reference" maxLength={100} defaultValue={grant?.reference ?? ""} /></div>
      </div>
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" name="restricted" defaultChecked={grant?.restricted ?? true} />
        Restricted: costs tagged to this project post only on its budget lines
      </label>
      <table className="tbl">
        <thead><tr><th scope="col">Budget line (expense account)</th><th scope="col" className="!text-right">Budget</th></tr></thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i}>
              <td>
                <select className={`field ${bad(`lines.${i}.account_id`) ? "!border-bad" : ""}`} name={`lines.${i}.account_id`} defaultValue={r.account_id} aria-label={`Budget line ${i + 1} account`}>
                  <option value="">—</option>
                  {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
                </select>
              </td>
              <td><input className="field num text-right" name={`lines.${i}.amount`} inputMode="decimal" defaultValue={r.amount} aria-label={`Budget line ${i + 1} amount`} /></td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="text-[13px] text-muted">Save to add more lines. A line left without an account is dropped.</p>
      <button type="submit" className="btn btn-primary self-start" disabled={pending}>{pending ? "Saving…" : grant ? "Save the grant" : "Set up as a grant"}</button>
    </form>
  );
}
