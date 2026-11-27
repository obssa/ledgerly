import { amountChange, pctChange, shows, type Diff, type Line } from "@/lib/compare";
import { formatAmount } from "@/lib/format";

/**
 * A statement with a column per period (the report's own first, then each comparison) and, when asked, "Change" and
 * "% change" against the first comparison, as QuickBooks lays a comparison out. `extra` adds a column after the
 * report's own period (the profit and loss's % of income).
 */
export function CompareTable({ lines, headers, diff, extra, codes = false }: {
  lines: Line[]; headers: string[]; diff: Diff; extra?: { header: string; value: (l: Line) => string }; codes?: boolean;
}) {
  const compared = headers.length > 1;
  const amount = compared && (diff === "amount" || diff === "both");
  const pct = compared && (diff === "pct" || diff === "both");
  const width = 1 + headers.length + (extra ? 1 : 0) + (amount ? 1 : 0) + (pct ? 1 : 0);
  const v = (x: string | null) => (x === null ? "—" : formatAmount(x));
  return (
    <div className="overflow-x-auto">
      <table className="tbl">
        <thead>
          <tr>
            <th scope="col">Account</th>
            {headers.map((h, i) => <th key={i} scope="col" className="!text-right whitespace-nowrap">{h}</th>)}
            {extra && <th scope="col" className="!text-right">{extra.header}</th>}
            {amount && <th scope="col" className="!text-right">Change</th>}
            {pct && <th scope="col" className="!text-right">% change</th>}
          </tr>
        </thead>
        <tbody>
          {lines.filter(shows).map((l) => l.kind === "heading" ? (
            <tr key={l.key}><th scope="rowgroup" colSpan={width} className="!border-0 !pt-5 !text-[13px] !text-ink">{l.label}</th></tr>
          ) : (
            <tr key={l.key} className={l.kind === "grand" ? "bg-side font-extrabold text-white" : l.kind === "key" ? "bg-[#f0faf7] font-extrabold" : l.kind === "total" ? "font-bold" : ""}>
              <td className={l.kind === "row" ? "pl-7" : ""}>{codes && l.code ? <><span className="num text-muted">{l.code}</span> </> : null}{l.label}</td>
              {l.values.map((x, i) => <td key={i} className="num text-right">{v(x)}</td>)}
              {extra && <td className="num text-right text-muted">{extra.value(l)}</td>}
              {amount && <td className="num text-right">{v(amountChange(l.values))}</td>}
              {pct && <td className="num text-right">{pctChange(l.values)}</td>}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
