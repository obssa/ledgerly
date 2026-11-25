import Link from "next/link";
import { Card } from "@/components/ui";
import { actionLabel, changesOf, type DocumentHistory } from "@/lib/history";

/**
 * Who did what to a document, newest first: created, changed, posted, corrected (with the reason and every field and
 * line that changed), voided, emailed… Each correction can open the version it replaced. From the append-only audit
 * log and saved versions, so nothing here can be edited.
 */
export function DocumentHistoryPanel({ history, timeZone, auditHref }: { history: DocumentHistory | null; timeZone: string; auditHref?: string }) {
  if (!history) return null;
  const when = (iso: string) => {
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleString("en-GB", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit", timeZone });
  };
  const versionFor = (after: unknown) => {
    try {
      const v = (typeof after === "string" ? JSON.parse(after) : after) as { version_saved?: number } | null;
      return v?.version_saved ? history.versions.find((x) => x.version === v.version_saved) : undefined;
    } catch {
      return undefined;
    }
  };

  return (
    <Card label="History">
      <div className="flex items-center justify-between gap-2">
        <h2 className="text-[17px] font-bold">History</h2>
        {auditHref && <Link href={auditHref} className="text-[12.5px] font-semibold text-brand no-underline hover:underline">In the audit log</Link>}
      </div>
      <p className="mt-0.5 text-[12.5px] text-muted">Who did what and when. Kept for good: nothing here can be changed or deleted.</p>
      {history.audit.length === 0 ? (
        <p className="mt-3 text-sm text-muted">Nothing recorded yet.</p>
      ) : (
        <ol className="relative mt-3 border-l border-line pl-4">
          {history.audit.map((row) => {
            const changes = changesOf(row);
            const version = row.action === "edited_after_posting" ? versionFor(row.after) : undefined;
            const tone = row.action === "voided" ? "bg-bad" : row.action === "edited_after_posting" ? "bg-[#d97706]" : row.action === "posted" ? "bg-brand" : "bg-[#9fb0a9]";
            return (
              <li key={row.id} className="relative pb-4 last:pb-0">
                <span aria-hidden="true" className={`absolute -left-[21px] top-1.5 size-2.5 rounded-full ring-2 ring-surface ${tone}`} />
                <div className="text-[13.5px] font-semibold">{actionLabel(row.action)}{version ? <span className="font-normal text-muted"> · version {version.version} kept</span> : null}</div>
                <div className="text-[12.5px] text-muted">
                  {row.user_name ?? (row.actor_type === "system" || !row.user_id ? "Evenbooks" : "A former member")} · <time dateTime={row.created_at}>{when(row.created_at)}</time>
                </div>
                {row.reason && <p className="mt-1 rounded-md bg-canvas px-2 py-1 text-[12.5px]"><span className="text-muted">Why: </span>{row.reason}</p>}
                {changes.length > 0 && (
                  <ul className="mt-1.5 grid gap-0.5 text-[12.5px]">
                    {changes.map((c, i) => c.kind === "field" ? (
                      <li key={i}><span className="text-muted">{c.label}:</span> <span className="num line-through decoration-faint">{c.from}</span> → <span className="num font-semibold">{c.to}</span></li>
                    ) : (
                      <li key={i} className={c.change === "added" ? "text-good" : "text-bad"}>
                        <span className="sr-only">{c.change === "added" ? "Line added: " : "Line removed: "}</span>
                        <span aria-hidden="true">{c.change === "added" ? "+ " : "− "}</span><span className="num">{c.text}</span>
                      </li>
                    ))}
                  </ul>
                )}
                {version && (
                  <details className="mt-1.5 text-[12.5px]">
                    <summary className="cursor-pointer font-semibold text-brand">Show version {version.version} as it was</summary>
                    <VersionSnapshot snapshot={version.snapshot} />
                  </details>
                )}
              </li>
            );
          })}
        </ol>
      )}
    </Card>
  );
}

function VersionSnapshot({ snapshot }: { snapshot: Record<string, unknown> }) {
  const lines = Array.isArray(snapshot.lines) ? (snapshot.lines as Record<string, unknown>[]) : [];
  const f = (k: string) => (snapshot[k] === null || snapshot[k] === undefined || snapshot[k] === "" ? "—" : String(snapshot[k]));
  return (
    <div className="mt-1.5 rounded-lg border border-line-soft p-2">
      <dl className="grid grid-cols-2 gap-x-3 gap-y-0.5">
        <dt className="text-muted">Date</dt><dd className="num">{f("issue_date").slice(0, 10)}</dd>
        <dt className="text-muted">Due</dt><dd className="num">{f("due_date").slice(0, 10)}</dd>
        <dt className="text-muted">Total</dt><dd className="num font-semibold">{f("currency")} {f("total")}</dd>
      </dl>
      {lines.length > 0 && (
        <ul className="mt-1.5 border-t border-line-soft pt-1.5">
          {lines.map((l, i) => (
            <li key={i} className="flex justify-between gap-2"><span>{String(l.description ?? "")}</span><span className="num">{String(l.quantity ?? "").replace(/\.?0+$/, "")} × {String(l.unit_price ?? "")}</span></li>
          ))}
        </ul>
      )}
    </div>
  );
}
