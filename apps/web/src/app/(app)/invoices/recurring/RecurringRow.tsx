"use client";

import { Dt } from "@/components/Dt";

import { useActionState } from "react";
import type { RecurringInvoice } from "@/lib/api";
import { recurringInvoiceStep } from "../actions";

const EVERY = { weekly: "Week", monthly: "Month", quarterly: "Quarter", yearly: "Year" } as const;

/** One recurring invoice, with pause, resume and "make what is due" for those who write invoices. */
export function RecurringRow({ row, manage }: { row: RecurringInvoice; manage: boolean }) {
  const [state, action, pending] = useActionState(recurringInvoiceStep, undefined);
  return (
    <tr className={row.is_active ? "" : "text-muted"}>
      <td className="font-semibold">{row.name}{!row.is_active && <span className="ml-1 font-normal">(paused)</span>}
        {row.last_error && <div className="text-[12px] font-normal text-bad">{row.last_error}</div>}</td>
      <td>{row.customer}</td>
      <td>{EVERY[row.frequency]}</td>
      <td className="num"><Dt v={row.next_on} /></td>
      <td className="num"><Dt v={row.ends_on} /></td>
      <td>{row.post ? "Posted invoices" : "Drafts"}</td>
      <td className="num text-right">{row.made_count}</td>
      <td>
        {manage && (
          <form action={action} className="flex flex-wrap gap-1">
            <input type="hidden" name="id" value={row.id} />
            {row.is_active ? (
              <>
                <button type="submit" name="step" value="make-due" className="btn !px-2 !py-1 text-[13px]" disabled={pending}>Make what&apos;s due</button>
                <button type="submit" name="step" value="pause" className="btn !px-2 !py-1 text-[13px]" disabled={pending}>Pause</button>
              </>
            ) : (
              <button type="submit" name="step" value="resume" className="btn !px-2 !py-1 text-[13px]" disabled={pending}>Resume</button>
            )}
          </form>
        )}
        {state?.error && <p role="alert" className="mt-1 text-[12px] text-bad">{state.error}</p>}
        {state?.notice && <p role="status" className="mt-1 text-[12px] text-good">{state.notice}</p>}
      </td>
    </tr>
  );
}
