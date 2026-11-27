import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getSavedReports, getDateFormatter } from "@/lib/data";
import { isDate } from "@/lib/reports";
import { SAVEABLE, SCHEDULES, savedReportUrl, OPTION_KEYS } from "@/lib/saved-reports";
import { ReportsNav } from "../ReportsNav";
import { RemoveSavedReport, SavedReportForm } from "./SavedReportForms";

export const metadata = { title: "Saved reports" };

// A word, a column list ("date,number,name"), a whole number (the ageing periods) or an id; the API checks each against its list.
const OPTION = /^[a-z_,]{1,400}$|^[0-9]{1,3}$|^[0-9a-f-]{36}$/;

/** Module 10: your saved reports, each opened for its period today, and the ones emailed to you weekly or monthly. */
export default async function SavedReportsPage({ searchParams }: PageProps<"/reports/saved">) {
  const { date: fmtDate } = await getDateFormatter();
  const q = await searchParams;
  const one = (k: string) => (typeof q[k] === "string" ? (q[k] as string) : undefined);
  const options = Object.fromEntries(OPTION_KEYS.map((k) => [k, one(k)]).filter(([, v]) => v && OPTION.test(v))) as Record<string, string>;
  const asOf = one("as_of");
  const initial = { report: one("report"), range: one("range"), from: isDate(one("from")) ? one("from") : undefined, to: isDate(one("to")) ? one("to") : isDate(asOf) ? asOf : undefined, options };
  const saved = await getSavedReports();
  const schedule = Object.fromEntries(SCHEDULES);

  return (
    <>
      <PageTitle eyebrow="Reports / Saved" title="Saved and scheduled reports" />
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/saved" />
        {saved === null ? <p className="text-[14px] text-muted">Not available in the demo, or for your role.</p> : (
          <div className="min-w-0 flex-[4_1_560px]">
            <Card className="mb-4" label={initial.report ? "Save this report" : "Save a report"}>
              <SavedReportForm initial={initial} />
            </Card>
            <Card className="!p-2" label="Your saved reports">
              <div className="overflow-x-auto">
                <table className="tbl">
                  <thead><tr><th scope="col">Name</th><th scope="col">Report</th><th scope="col">Period</th><th scope="col">Emailed</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
                  <tbody>
                    {saved.length === 0 && <tr><td colSpan={5} className="text-muted">Nothing saved yet. Use Save on any report, or the form above.</td></tr>}
                    {saved.map((r) => (
                      <tr key={r.id}>
                        <td><Link href={savedReportUrl(r)} className="font-semibold hover:underline">{r.name}</Link></td>
                        <td className="text-[13px]">{SAVEABLE[r.report]?.label ?? r.report}</td>
                        <td className="text-[13px]">{r.period === "custom" ? (r.from_date ? `${fmtDate(r.from_date)} to ${fmtDate(r.to_date)}` : `At ${fmtDate(r.to_date)}`) : r.period_label}
                          <span className="block text-[11px] text-muted">{r.covers.as_of ? `at ${fmtDate(r.covers.as_of)}` : `${fmtDate(r.covers.from)} to ${fmtDate(r.covers.to)}`}</span></td>
                        <td className="text-[13px]">{r.schedule === "none" ? <span className="text-muted">No</span> : <><Pill tone="good">{schedule[r.schedule]}</Pill>
                          <span className="block text-[11px] text-muted">next <Dt v={r.next_send_on} /></span></>}</td>
                        <td className="whitespace-nowrap text-right"><RemoveSavedReport id={r.id} name={r.name} /></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
          </div>
        )}
      </div>
    </>
  );
}
