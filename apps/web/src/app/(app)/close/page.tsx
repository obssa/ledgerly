import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getCloseChecklist } from "@/lib/data";
import { CloseForm } from "./CloseForm";

export const metadata = { title: "Close the books" };

const date = (v: unknown) => (typeof v === "string" && /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : undefined);

/** Module 6: the close checklist for a period end, and closing the books through it. */
export default async function ClosePage({ searchParams }: PageProps<"/close">) {
  const checklist = await getCloseChecklist(date((await searchParams).period_end));

  return (
    <>
      <PageTitle eyebrow="Books" title="Close the books" />
      {!checklist ? <p className="text-[14px] text-muted">Closing the books is for accountants, owners and admins (and not available in the demo).</p> : (
        <>
          <form className="mb-4 flex flex-wrap items-end gap-2.5">
            <div><label className="field-label" htmlFor="period_end">Period ends on</label><input className="field" id="period_end" name="period_end" type="date" defaultValue={checklist.period_end} /></div>
            <button type="submit" className="btn">Check</button>
            <p className="text-[13px] text-muted">
              {checklist.books_closed_until ? <>The books are closed through <span className="num font-semibold text-ink">{checklist.books_closed_until}</span>.</> : "No period is closed yet."}
            </p>
          </form>
          <Card className="mb-4" label="Checklist">
            <h2 className="mb-3 text-[15px] font-bold">Before closing through <span className="num">{checklist.period_end}</span></h2>
            <ul className="grid gap-3">
              {checklist.items.map((i) => (
                <li key={i.key} className="border-t border-line pt-3 first:border-0 first:pt-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <Pill tone={i.status === "ok" ? "good" : "warn"}>{i.status === "ok" ? "Done" : "To do"}</Pill>
                    <span className="font-semibold">{i.label}</span>
                    {i.link && i.status !== "ok" && <Link href={i.link} className="text-[13px] font-semibold text-brand hover:underline">Open</Link>}
                  </div>
                  <p className="mt-1 text-[13px] text-muted">{i.detail}</p>
                  {i.items.length > 0 && (
                    <ul className="mt-1.5 grid gap-1 text-[13px]">
                      {i.items.map((x, n) => <li key={`${i.key}-${n}`}><Link href={x.link} className="hover:underline">{x.label}</Link> <span className="text-muted">— {x.note}</span></li>)}
                    </ul>
                  )}
                </li>
              ))}
            </ul>
          </Card>
          {(!checklist.books_closed_until || checklist.books_closed_until < checklist.period_end) && (
            <Card className="max-w-[900px]" label="Close"><CloseForm periodEnd={checklist.period_end} warnings={checklist.warnings} /></Card>
          )}
          <p className="mt-3 text-[13px] text-muted">Reopening a closed period is done under <Link href="/settings" className="font-semibold text-brand hover:underline">Company settings</Link>, by an owner or admin, with a reason.</p>
        </>
      )}
    </>
  );
}
