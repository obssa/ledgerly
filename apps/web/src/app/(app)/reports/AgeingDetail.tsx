import Link from "next/link";
import { Card, Pill, Segmented } from "@/components/ui";
import type { ArAgeing } from "@/lib/api";
import { formatAmount } from "@/lib/format";

const BUCKET: Record<string, [string, "neutral" | "warn" | "bad"]> = {
  current: ["Not yet due", "neutral"], days_1_30: ["1–30 days", "warn"], days_31_60: ["31–60 days", "warn"], days_61_90: ["61–90 days", "bad"], over_90: ["Over 90 days", "bad"],
};

/** Summary (one line per customer or supplier) or Detail (every open invoice or bill): the same figures, two views. */
export function AgeingViews({ base, asOf, detail }: { base: string; asOf?: string; detail: boolean }) {
  const q = asOf ? `as_of=${asOf}` : "";
  return (
    <Segmented label="View" items={[
      { key: "summary", href: `${base}${q ? `?${q}` : ""}`, label: "Summary", active: !detail },
      { key: "detail", href: `${base}?view=detail${q ? `&${q}` : ""}`, label: "Detail", active: detail },
    ]} />
  );
}

/**
 * Ageing detail: every open invoice (or bill) under its customer (or supplier), with its dates, how many days late it
 * is, and what is still due; a subtotal per party and a grand total. Amounts in the company's currency, each document
 * at its own rate; the document's own currency is shown when it differs.
 */
export function AgeingDetail({ ageing, kind, baseCurrency }: { ageing: ArAgeing; kind: "invoice" | "bill"; baseCurrency: string }) {
  const docs = ageing.documents ?? [];
  const href = kind === "bill" ? "/bills" : "/invoices";
  const party = kind === "bill" ? "/suppliers" : "/customers";
  const groups = ageing.rows.map((r) => ({ row: r, docs: docs.filter((d) => d.contact_id === r.contact_id) })).filter((g) => g.docs.length > 0 || g.row.credits !== "0.00");
  const openTotal = (["current", "days_1_30", "days_31_60", "days_61_90", "over_90"] as const);

  return (
    <Card className="!p-2" label="Ageing detail">
      <div className="overflow-x-auto">
        <table className="tbl">
          <thead>
            <tr>
              <th scope="col">{kind === "bill" ? "Bill" : "Invoice"}</th><th scope="col">Date</th><th scope="col">Due</th><th scope="col" className="!text-right">Days late</th>
              <th scope="col">Age</th><th scope="col" className="!text-right">Total</th><th scope="col" className="!text-right">Still due ({baseCurrency})</th>
            </tr>
          </thead>
          {groups.length === 0 && <tbody><tr><td colSpan={7} className="text-muted">Nothing is outstanding.</td></tr></tbody>}
          {groups.map(({ row, docs: list }) => (
            <tbody key={row.contact_id}>
              <tr className="!bg-[#f8faf9]">
                <th scope="rowgroup" colSpan={7} className="!bg-transparent !normal-case !tracking-normal !text-[13.5px] !font-bold !text-ink">
                  <Link href={`${party}/${encodeURIComponent(row.contact_id)}`} className="text-ink hover:underline">{row.name}</Link>
                </th>
              </tr>
              {list.map((d) => (
                <tr key={d.id}>
                  <td className="num font-semibold"><Link href={`${href}/${encodeURIComponent(d.id)}`} className="text-ink hover:underline">{d.number ?? d.reference ?? "—"}</Link>
                    {kind === "bill" && d.reference && d.number && <span className="ml-1.5 text-[12px] font-normal text-muted">{d.reference}</span>}</td>
                  <td className="num">{d.issue_date}</td>
                  <td className="num">{d.due_date ?? "—"}</td>
                  <td className={`num text-right ${d.days_late > 90 ? "font-semibold text-bad" : ""}`}>{d.days_late > 0 ? d.days_late : "—"}</td>
                  <td><Pill tone={BUCKET[d.bucket]?.[1] ?? "neutral"}>{BUCKET[d.bucket]?.[0] ?? d.bucket}</Pill></td>
                  <td className="num text-right text-muted">{d.currency} {formatAmount(d.total)}</td>
                  <td className="num text-right font-semibold">{formatAmount(d.amount)}
                    {d.currency !== baseCurrency && <span className="block text-[12px] font-normal text-muted">{d.currency} {formatAmount(d.amount_due)}</span>}</td>
                </tr>
              ))}
              {row.credits !== "0.00" && (
                <tr><td colSpan={6} className="text-muted">{kind === "bill" ? "Advances and credits not used" : "Credit not used (payments and credit notes)"}</td>
                  <td className="num text-right">{formatAmount(row.credits)}</td></tr>
              )}
              <tr className="font-semibold">
                <td colSpan={6} className="!border-b-line text-right">{row.name}: {kind === "bill" ? "we owe" : "owes"}</td>
                <td className="num !border-b-line text-right">{formatAmount(row.total)}</td>
              </tr>
            </tbody>
          ))}
          {groups.length > 0 && (
            <tfoot>
              <tr className="border-t-2 border-ink font-bold">
                <td colSpan={6}>Total{" "}
                  <span className="ml-2 text-[12px] font-normal text-muted">
                    {openTotal.map((k) => `${BUCKET[k][0]} ${formatAmount(ageing.totals[k])}`).join(" · ")}
                  </span></td>
                <td className="num text-right">{formatAmount(ageing.totals.total)}</td>
              </tr>
            </tfoot>
          )}
        </table>
      </div>
    </Card>
  );
}
