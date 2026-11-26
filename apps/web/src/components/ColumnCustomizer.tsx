import Link from "@/components/Link";
import type { ReactNode } from "react";
import type { ColumnDef } from "@/lib/columns";

/**
 * QuickBooks' Customize, Columns, for any report with a column catalogue (lib/columns.ts): tick the fields to show,
 * move each left or right, plus the report's own choices (sort, group, periods) as children. A plain GET form and
 * links, so it works without JavaScript; everything else on the page (period, filters) rides along as hidden fields.
 */
export function ColumnCustomizer<K extends string>({ defs, cols, keep, moveHref, resetHref, summary = "Customize: columns", children }: {
  defs: readonly ColumnDef<K>[];
  cols: K[];
  /** The page's other choices (period, filters), kept as they are. */
  keep: Record<string, string | undefined>;
  /** The page with these columns, in this order. */
  moveHref: (cols: K[]) => string;
  /** The page with the report's standard columns. */
  resetHref: string;
  summary?: string;
  children?: ReactNode;
}) {
  const label = (k: K) => defs.find((c) => c.key === k)?.label ?? k;
  const move = (i: number, by: -1 | 1) => {
    const next = [...cols];
    [next[i], next[i + by]] = [next[i + by], next[i]];
    return moveHref(next);
  };
  const hidden = Object.entries(keep).filter(([k, v]) => v && !["cols", "col", "customize"].includes(k)) as [string, string][];

  return (
    <details className="mb-4 rounded-[14px] border border-line bg-surface p-4 print:hidden">
      <summary className="cursor-pointer text-sm font-bold">{summary}</summary>
      <form className="mt-3">
        {hidden.map(([k, v]) => <input key={k} type="hidden" name={k} value={v} />)}
        <input type="hidden" name="cols" value={cols.join(",")} />
        <input type="hidden" name="customize" value="1" />
        <fieldset className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-x-6 gap-y-3">
          <legend className="mb-2 text-[13px] font-semibold text-muted">Columns (ticked ones show; the order is below)</legend>
          {[...new Set(defs.map((c) => c.group))].map((g) => (
            <div key={g}>
              <div className="mb-1 text-[12px] font-bold text-muted">{g}</div>
              {defs.filter((c) => c.group === g).map((c) => (
                <label key={c.key} className="flex items-center gap-2 py-0.5 text-sm">
                  <input type="checkbox" name="col" value={c.key} defaultChecked={cols.includes(c.key)} /> {c.label}
                </label>
              ))}
            </div>
          ))}
        </fieldset>
        <div className="mt-4 flex flex-wrap items-end gap-2.5">
          {children}
          <button type="submit" className="btn btn-primary">Apply</button>
          <Link href={resetHref} className="btn">Back to the standard columns</Link>
        </div>
      </form>
      <div className="mt-4">
        <div className="mb-1 text-[13px] font-semibold text-muted">Column order</div>
        <ol className="flex flex-wrap gap-2">
          {cols.map((c, i) => (
            <li key={c} className="flex items-center gap-1 rounded-lg border border-line px-2 py-1 text-[13px]">
              {i > 0 && <Link aria-label={`Move ${label(c)} left`} href={move(i, -1)} className="px-1 font-bold text-brand">‹</Link>}
              {label(c)}
              {i < cols.length - 1 && <Link aria-label={`Move ${label(c)} right`} href={move(i, 1)} className="px-1 font-bold text-brand">›</Link>}
            </li>
          ))}
        </ol>
      </div>
    </details>
  );
}
