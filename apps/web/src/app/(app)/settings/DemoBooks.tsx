"use client";

import { startTransition, useActionState, useState } from "react";
import { Card } from "@/components/ui";
import { demoFill, demoStartFresh } from "./actions";

/**
 * Two buttons: empty books (a new company, the old one archived) or the sample books put in. For the demo's owner, and
 * for the owners of a test company (real books the team tries Ledgerly out on).
 */
export function DemoBooks({ kind }: { kind: "demo" | "test" }) {
  const [fresh, startFresh, starting] = useActionState(demoStartFresh, undefined);
  const [fill, fillIt, filling] = useActionState(demoFill, undefined);
  const [confirming, setConfirming] = useState(false);
  const run = (action: (data: FormData) => void) => startTransition(() => action(new FormData()));

  return (
    <Card label={kind === "demo" ? "Demo data" : "Test company"}>
      <h2 className="text-[19px] font-bold">{kind === "demo" ? "Demo data" : "Test company"}</h2>
      <p className="mt-1.5 text-sm text-muted">
        {kind === "test" && <>These are the team&apos;s test books. </>}
        Start with empty books, or fill them with the sample company&apos;s customers, invoices, bills and stock.
        {kind === "test" && <> Starting fresh keeps the current books, archived and read-only, and brings every member along.</>}
      </p>
      {(fresh?.error || fill?.error) && <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{fresh?.error ?? fill?.error}</p>}
      {fill?.notice && <p role="status" className="mt-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{fill.notice}</p>}
      <div className="mt-3 flex flex-wrap gap-2">
        {confirming ? (
          <>
            <button type="button" className="btn border-bad bg-bad text-white hover:bg-[#912018]" disabled={starting} onClick={() => run(startFresh)}>
              {starting ? "Starting fresh…" : "Yes, start fresh"}
            </button>
            <button type="button" className="btn" disabled={starting} onClick={() => setConfirming(false)}>Cancel</button>
          </>
        ) : (
          <>
            <button type="button" className="btn" onClick={() => setConfirming(true)}>Start fresh</button>
            <button type="button" className="btn btn-primary" disabled={filling || Boolean(fill?.notice)} onClick={() => run(fillIt)}>
              {filling ? "Filling…" : kind === "demo" ? "Fill with demo data" : "Fill with sample data"}
            </button>
          </>
        )}
      </div>
    </Card>
  );
}
