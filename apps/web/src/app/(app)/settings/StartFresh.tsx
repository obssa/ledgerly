"use client";

import { startTransition, useActionState, useState } from "react";
import { Card } from "@/components/ui";
import { startFresh } from "./actions";

/**
 * "Start fresh": a new, empty company with the same name and details, and this one archived (read-only, out of the
 * company switcher, nothing deleted). For owners and admins; the company's name is typed to confirm.
 */
export function StartFresh({ companyName }: { companyName: string }) {
  const [state, action, pending] = useActionState(startFresh, undefined);
  const [open, setOpen] = useState(false);
  const [typed, setTyped] = useState("");

  return (
    <Card label="Start fresh" className="border-bad/25">
      <h2 className="text-[19px] font-bold">Start fresh</h2>
      <p className="mt-1.5 text-sm text-muted">
        Begin again with empty books: a new company with the same name, country, currency and details, and no customers,
        invoices or entries. This company is archived, not deleted: it stays read-only and leaves the company switcher.
        Team members are not copied; invite them again.
      </p>
      {!open ? (
        <button type="button" className="btn mt-3 border-bad/40 text-bad hover:border-bad hover:bg-bad-tint" onClick={() => setOpen(true)}>Start fresh…</button>
      ) : (
        <form onSubmit={(e) => { e.preventDefault(); const data = new FormData(e.currentTarget); startTransition(() => action(data)); }} className="mt-3 grid gap-2">
          {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
          <label className="field-label" htmlFor="confirm-name">To confirm, type the company&apos;s name: <span className="text-ink">{companyName}</span></label>
          <input className="field" id="confirm-name" name="confirm" autoComplete="off" value={typed} onChange={(e) => setTyped(e.target.value)} />
          <div className="flex flex-wrap gap-2">
            <button type="submit" className="btn border-bad bg-bad text-white hover:bg-[#912018]" disabled={pending || typed.trim() !== companyName}>
              {pending ? "Starting fresh…" : "Archive this company and start fresh"}
            </button>
            <button type="button" className="btn" onClick={() => { setOpen(false); setTyped(""); }} disabled={pending}>Cancel</button>
          </div>
        </form>
      )}
    </Card>
  );
}
