"use client";

import { useState } from "react";
import { recordPrint } from "./print-actions";

/**
 * Module 10: a report as PDF through the browser's print dialog (Save as PDF), from the page as shown, Amharic names
 * included. The export is recorded in the audit log first, with the number of rows printed, like a CSV download.
 */
export function PrintButton({ report, params }: { report: string; params: Record<string, string | undefined> }) {
  const [busy, setBusy] = useState(false);
  return (
    <button type="button" className="btn print:hidden" disabled={busy} onClick={async () => {
      setBusy(true);
      try {
        await recordPrint(report, params, document.querySelectorAll("main table tbody tr").length);
      } finally {
        setBusy(false);
      }
      window.print();
    }}>{busy ? "Preparing…" : "Print or save as PDF"}</button>
  );
}
