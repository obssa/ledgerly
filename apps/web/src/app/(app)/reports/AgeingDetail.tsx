import Link from "@/components/Link";
import { Card, PageTitle, Pill, Segmented } from "@/components/ui";
import { AsOfFilter } from "@/components/DateRangeFilter";
import { ColumnCustomizer } from "@/components/ColumnCustomizer";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import {
  AGE_COLUMNS, AGE_DEFAULTS, AGE_FOREIGN, AGE_GROUPS, DEFAULT_BUCKETS, ageRows, bucketLabel, groupAgeRows, pickAgeGroup, pickPeriods, sumOf,
  type AgeColumn, type AgeRow,
} from "@/lib/ageing";
import { chooseColumns, rightAligned, withoutUnusedForeign } from "@/lib/columns";
import { getApAgeing, getArAgeing, getReportAsOf } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { asOfLabel } from "@/lib/periods";
import { typeLabel } from "@/lib/transactionColumns";
import { ExportLink } from "./ReportsNav";

type Query = Record<string, string | string[] | undefined>;
const KIND = {
  invoice: { report: "ar-ageing", path: "/reports/ar-ageing", short: "AR", title: "Accounts receivable ageing", eyebrow: "Receivables", party: "/customers",
    doc: "/invoices", partyWord: "Customer", credits: "Credit not used", owes: "owes", none: "No customer owes anything.",
    note: "each invoice at its own rate. The total equals the receivable account in the trial balance." },
  bill: { report: "ap-ageing", path: "/reports/ap-ageing", short: "AP", title: "Accounts payable ageing", eyebrow: "Payables", party: "/suppliers",
    doc: "/bills", partyWord: "Supplier", credits: "Advances and credits not used", owes: "we owe", none: "Nothing is owed to suppliers.",
    note: "each bill at its own rate. The total equals accounts payable in the trial balance." },
} as const;

const tone = (key: string, buckets: string[]): "neutral" | "warn" | "bad" => {
  const i = buckets.indexOf(key);
  return i <= 0 ? "neutral" : i >= buckets.length - 2 ? "bad" : "warn";
};

/**
 * The AR or AP ageing as QuickBooks shows it. Summary: one line per customer (or supplier) by ageing period. Detail:
 * every open invoice (or bill), grouped by ageing period (QuickBooks' way) or by party, with the columns the reader
 * picks (Customize). Both take QuickBooks' periods: days per ageing period and number of periods. Amounts in the
 * company's currency, each document at its own rate; the foreign amounts show for a document in another currency.
 */
export async function AgeingReport({ kind, q }: { kind: "invoice" | "bill"; q: Query }) {
  const k = KIND[kind];
  const detail = q.view === "detail";
  const at = await getReportAsOf(q);
  const periods = pickPeriods(q);
  const ageing = await (kind === "bill" ? getApAgeing : getArAgeing)(at.asOf, periods);
  const rows = ageing ? ageRows(ageing) : [];
  const cols = withoutUnusedForeign(chooseColumns(q, AGE_COLUMNS, AGE_DEFAULTS), rows, q, AGE_FOREIGN);
  const group = pickAgeGroup(q);
  const custom = q.cols || q.customize === "1";
  const keep: Record<string, string | undefined> = {
    as_of: at.asOf, view: detail ? "detail" : undefined,
    interval: periods.interval !== 30 ? String(periods.interval) : undefined, periods: periods.periods !== 4 ? String(periods.periods) : undefined,
    ...(detail ? { group: group !== "age" ? group : undefined, cols: custom ? cols.join(",") : undefined } : {}),
  };
  const href = (extra: Record<string, string | undefined>) =>
    `${k.path}?${new URLSearchParams(Object.entries({ ...keep, ...extra }).filter(([, v]) => v) as [string, string][])}`;
  const title = `${k.title} ${detail ? "detail" : "summary"}`;
  const periodsNote = periods.interval !== 30 || periods.periods !== 4 ? `${periods.periods} periods of ${periods.interval} days` : null;

  return (
    <>
      <PageTitle eyebrow={<><Link href="/reports" className="hover:underline">Reports</Link> / {k.eyebrow}</>} title={`${k.short} ageing ${detail ? "detail" : "summary"}`}
        actions={<ExportLink report={k.report} params={keep} />} />
      {ageing === null ? (
        <Card><p className="text-sm text-muted">Not available in the demo.</p></Card>
      ) : (
        <>
          <Segmented label="View" items={[
            { key: "summary", href: href({ view: undefined, cols: undefined, group: undefined }), label: "Summary", active: !detail },
            { key: "detail", href: href({ view: "detail" }), label: "Detail", active: detail },
          ]} />
          <AsOfFilter picked={at.picked} keep={keep} fields={["interval", "periods"]} label="Days late counted at">
            <div><label className="field-label" htmlFor="interval">Days per ageing period</label>
              <input className="field w-[110px]" id="interval" name="interval" type="number" min={1} max={365} defaultValue={periods.interval} /></div>
            <div><label className="field-label" htmlFor="periods">Number of periods</label>
              <input className="field w-[110px]" id="periods" name="periods" type="number" min={2} max={12} defaultValue={periods.periods} /></div>
          </AsOfFilter>
          {detail && (
            <ColumnCustomizer defs={AGE_COLUMNS} cols={cols} keep={keep} moveHref={(next) => href({ cols: next.join(",") })}
              resetHref={href({ cols: undefined, group: undefined })} summary="Customize: columns and grouping">
              <div><label className="field-label" htmlFor="group">Group by</label>
                <select className="field" id="group" name="group" defaultValue={group}>{AGE_GROUPS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></div>
            </ColumnCustomizer>
          )}
          <ReportHeader title={title} period={asOfLabel(at.asOf, at.ctx.calendar)}
            filters={[periodsNote, detail ? `Grouped by ${group === "age" ? "ageing period" : k.partyWord.toLowerCase()}` : null]} />
          {detail ? <Detail kind={kind} rows={rows} cols={cols} group={group} ageing={ageing} /> : <Summary kind={kind} ageing={ageing} />}
          <ReportFooter basis={null} currency={ageing.currency ?? undefined} />
          <p className="mt-3 text-[13px] text-muted">Amounts in the company&apos;s currency, {k.note}</p>
        </>
      )}
    </>
  );
}

function Summary({ kind, ageing }: { kind: "invoice" | "bill"; ageing: NonNullable<Awaited<ReturnType<typeof getArAgeing>>> }) {
  const k = KIND[kind];
  const buckets = ageing.buckets ?? DEFAULT_BUCKETS;
  const columns: [string, string][] = [...buckets.map((b) => [b, bucketLabel(b)] as [string, string]), ["credits", kind === "bill" ? "Advances" : "Credit not used"], ["total", kind === "bill" ? "We owe" : "Owes"]];
  const last = buckets[buckets.length - 1];
  return (
    <Card className="!p-2" label={`${k.short} ageing`}>
      <div className="overflow-x-auto">
        <table className="tbl">
          <thead><tr><th scope="col">{k.partyWord}</th>{columns.map(([c, label]) => <th key={c} scope="col" className="!text-right">{label}</th>)}</tr></thead>
          <tbody>
            {ageing.rows.length === 0 && <tr><td colSpan={columns.length + 1} className="text-muted">{k.none}</td></tr>}
            {ageing.rows.map((r) => (
              <tr key={r.contact_id}>
                <td className="font-semibold"><Link href={`${k.party}/${encodeURIComponent(r.contact_id)}/statement`} className="hover:underline">{r.name}</Link></td>
                {columns.map(([c]) => (
                  <td key={c} className={`num text-right ${c === "total" ? "font-semibold" : ""} ${c === last && r[c] !== "0.00" ? "text-bad" : ""}`}>
                    {!r[c] || r[c] === "0.00" ? "—" : formatAmount(r[c])}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
          {ageing.rows.length > 0 && (
            <tfoot><tr className="border-t-2 border-ink font-bold"><td>Total</td>{columns.map(([c]) => <td key={c} className="num text-right">{formatAmount(ageing.totals[c] ?? "0.00")}</td>)}</tr></tfoot>
          )}
        </table>
      </div>
    </Card>
  );
}

const MONEY = new Set<AgeColumn>(["amount", "open_balance", "foreign_amount", "foreign_open_balance"]);

/** A period's subtotal of an amount column (none for the credits, which have only what is left). */
function groupSum(key: string, rows: AgeRow[], c: AgeColumn): string {
  if (key === "credits" || (c !== "amount" && c !== "foreign_amount" && c !== "foreign_open_balance")) return "";
  const sum = sumOf(rows, c);
  return sum === null ? "" : formatAmount(sum);
}

function Detail({ kind, rows, cols, group, ageing }: { kind: "invoice" | "bill"; rows: AgeRow[]; cols: AgeColumn[]; group: "age" | "name"; ageing: NonNullable<Awaited<ReturnType<typeof getArAgeing>>> }) {
  const k = KIND[kind];
  const buckets = ageing.buckets ?? DEFAULT_BUCKETS;
  const blocks = groupAgeRows(ageing, rows, group, k.credits);
  const align = (c: AgeColumn) => (rightAligned(AGE_COLUMNS.find((d) => d.key === c)!.kind) ? "num text-right" : c === "date" || c === "due_date" ? "num" : "");
  const label = (c: AgeColumn) => AGE_COLUMNS.find((d) => d.key === c)!.label;
  // A line of only an amount (credit not used, a party's total): the label across the columns before Open balance.
  const at = Math.max(cols.indexOf("open_balance"), 0) || cols.length - 1;
  const amountLine = (text: string, amount: string, className = "") => (
    <tr className={className}>
      <td colSpan={Math.max(at, 1)} className={at === 0 ? "" : "text-right"}>{text}</td>
      <td className="num text-right">{formatAmount(amount)}</td>
      {cols.length - Math.max(at, 1) - 1 > 0 && <td colSpan={cols.length - Math.max(at, 1) - 1} />}
    </tr>
  );
  const cell = (c: AgeColumn, r: AgeRow) => {
    const v = r[c];
    if (v === null || v === "") return "";
    if (c === "type") return r.type === "credit" ? k.credits : typeLabel(String(v));
    if (c === "age") return <Pill tone={tone(String(v), buckets)}>{bucketLabel(String(v))}</Pill>;
    if (c === "number") return <Link href={`${k.doc}/${encodeURIComponent(r.id)}`} className="font-semibold text-ink hover:underline">{String(v)}</Link>;
    if (c === "name") return <Link href={`${k.party}/${encodeURIComponent(r.contact_id)}/statement`} className="hover:underline">{String(v)}</Link>;
    if (c === "days_past_due") return <span className={Number(v) > 90 ? "font-semibold text-bad" : ""}>{String(v)}</span>;
    if (MONEY.has(c)) return formatAmount(String(v));
    return String(v);
  };

  return (
    <Card className="!p-2" label={`${k.short} ageing detail`}>
      <div className="overflow-x-auto" tabIndex={0} role="region" aria-label={`${k.short} ageing detail`}>
        <table className="tbl">
          <thead><tr>{cols.map((c) => <th key={c} scope="col" className={align(c).includes("text-right") ? "!text-right" : ""}>{label(c)}</th>)}</tr></thead>
          {blocks.length === 0 && <tbody><tr><td colSpan={cols.length} className="text-muted">Nothing is outstanding.</td></tr></tbody>}
          {blocks.map((b) => (
            <tbody key={b.key}>
              <tr className="!bg-[#f8faf9]">
                <th scope="rowgroup" colSpan={cols.length} className="!bg-transparent !normal-case !tracking-normal !text-[13.5px] !font-bold !text-ink">
                  {b.contactId ? <Link href={`${k.party}/${encodeURIComponent(b.contactId)}/statement`} className="text-ink hover:underline">{b.label}</Link> : b.label}
                </th>
              </tr>
              {b.rows.map((r) => (
                r.type === "credit" ? <tr key={r.id}><td colSpan={Math.max(at, 1)}>{r.name}</td><td className="num text-right">{formatAmount(r.open_balance)}</td>
                  {cols.length - Math.max(at, 1) - 1 > 0 && <td colSpan={cols.length - Math.max(at, 1) - 1} />}</tr>
                  : <tr key={r.id}>{cols.map((c) => <td key={c} className={align(c)}>{cell(c, r)}</td>)}</tr>
              ))}
              {b.credits && amountLine(k.credits, b.credits, "text-muted")}
              {b.total !== null && (group === "name"
                ? amountLine(`${b.label}: ${k.owes}`, b.total, "font-semibold")
                : <tr className="font-semibold">{cols.map((c, i) => (
                  <td key={c} className={MONEY.has(c) ? "num text-right" : ""}>
                    {i === 0 ? `Total ${b.label}` : c === "open_balance" ? formatAmount(b.total!) : groupSum(b.key, b.rows, c)}
                  </td>))}</tr>)}
            </tbody>
          ))}
          {blocks.length > 0 && (
            <tfoot>
              <tr className="border-t-2 border-ink font-bold">
                {cols.map((c, i) => (
                  <td key={c} className={MONEY.has(c) ? "num text-right" : ""}>
                    {i === 0 ? "Total" : c === "open_balance" ? formatAmount(ageing.totals.total ?? "0.00") : c === "amount" ? formatAmount(sumOf(rows, "amount") ?? "0.00") : ""}
                  </td>
                ))}
              </tr>
            </tfoot>
          )}
        </table>
      </div>
    </Card>
  );
}
