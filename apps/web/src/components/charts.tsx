/**
 * Charts drawn on the server as plain SVG: nothing to download, they print, and every value is also in the table
 * under each chart. Numbers here only place marks; every amount shown as text comes from the API's decimal strings.
 * Colours are the --color-series-* tokens and the ageing ramp, checked for colour-blind separation.
 */

const W = 640;

/** Round axis steps: 0, 5K, 10K… */
function niceTicks(min: number, max: number, count = 4): number[] {
  const lo = Math.min(0, min);
  const hi = Math.max(0, max);
  if (hi === lo) return [0, 1];
  const raw = (hi - lo) / count;
  const mag = 10 ** Math.floor(Math.log10(raw));
  const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= raw) ?? raw;
  const ticks: number[] = [];
  for (let t = Math.floor(lo / step) * step; t <= hi + step * 0.001; t += step) ticks.push(Math.round(t * 1e6) / 1e6);
  if (ticks[ticks.length - 1] < hi) ticks.push(ticks[ticks.length - 1] + step);
  return ticks;
}

/** 12,500 → 12.5K; 3,400,000 → 3.4M. Axis ticks only. */
export function compact(n: number): string {
  const a = Math.abs(n);
  const s = n < 0 ? "−" : "";
  if (a >= 1e9) return `${s}${trim(a / 1e9)}B`;
  if (a >= 1e6) return `${s}${trim(a / 1e6)}M`;
  if (a >= 1e4) return `${s}${trim(a / 1e3)}K`;
  return `${s}${Math.round(a).toLocaleString("en-US")}`;
}
const trim = (n: number) => (n >= 100 ? Math.round(n).toString() : n.toFixed(1).replace(/\.0$/, ""));

/** A column whose data end is rounded (4px) and whose baseline end is square. */
function column(x: number, w: number, y0: number, y1: number): string {
  const h = Math.abs(y0 - y1);
  if (h < 0.5) return "";
  const r = Math.min(4, w / 2, h);
  if (y1 < y0) return `M${x},${y0}V${y1 + r}Q${x},${y1} ${x + r},${y1}H${x + w - r}Q${x + w},${y1} ${x + w},${y1 + r}V${y0}Z`;
  return `M${x},${y0}V${y1 - r}Q${x},${y1} ${x + r},${y1}H${x + w - r}Q${x + w},${y1} ${x + w},${y1 - r}V${y0}Z`;
}

function Legend({ items }: { items: { label: string; color: string }[] }) {
  return (
    <ul className="flex flex-wrap gap-x-4 gap-y-1 text-[12.5px] font-medium text-muted">
      {items.map((i) => (
        <li key={i.label} className="flex items-center gap-1.5">
          <span aria-hidden="true" className="size-2.5 rounded-[3px]" style={{ background: i.color }} />
          {i.label}
        </li>
      ))}
    </ul>
  );
}

function TableView({ caption, head, rows }: { caption: string; head: string[]; rows: (string | number)[][] }) {
  return (
    <details className="mt-3 text-sm print:hidden">
      <summary className="cursor-pointer text-[12.5px] font-semibold text-brand">Show the figures</summary>
      <div className="mt-2 overflow-x-auto">
        <table className="tbl">
          <caption className="sr-only">{caption}</caption>
          <thead><tr>{head.map((h, i) => <th key={h} scope="col" className={i ? "!text-right" : ""}>{h}</th>)}</tr></thead>
          <tbody>{rows.map((r, i) => <tr key={i}>{r.map((c, j) => <td key={j} className={j ? "num text-right" : ""}>{c}</td>)}</tr>)}</tbody>
        </table>
      </div>
    </details>
  );
}

export type ColumnPoint = { label: string; a: number; b: number; aText: string; bText: string };

/** Two measures in the same currency, side by side for each period (income and spending by month). */
export function PairedColumns({ title, points, a, b }: { title: string; points: ColumnPoint[]; a: string; b: string }) {
  const H = 220, top = 12, bottom = 26, left = 46, right = 8;
  const values = points.flatMap((p) => [p.a, p.b]);
  const ticks = niceTicks(Math.min(...values, 0), Math.max(...values, 0));
  const lo = ticks[0], hi = ticks[ticks.length - 1];
  const y = (v: number) => top + (H - top - bottom) * (1 - (v - lo) / (hi - lo));
  const band = (W - left - right) / points.length;
  const barW = Math.min(24, (band - 18) / 2);
  return (
    <figure className="m-0">
      <div className="mb-2 flex justify-end"><Legend items={[{ label: a, color: "var(--color-series-1)" }, { label: b, color: "var(--color-series-2)" }]} /></div>
      <div className="-mx-1 overflow-x-auto px-1"><svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={title} className="h-auto w-full min-w-[480px] overflow-visible">
        {ticks.map((t) => (
          <g key={t}>
            <line x1={left} x2={W - right} y1={y(t)} y2={y(t)} stroke={t === 0 ? "#c9d3ce" : "#edf1ef"} strokeWidth={1} />
            <text x={left - 8} y={y(t)} dy="0.32em" textAnchor="end" className="fill-faint text-[11px] [font-variant-numeric:tabular-nums]">{compact(t)}</text>
          </g>
        ))}
        {points.map((p, i) => {
          const cx = left + band * i + band / 2;
          return (
            <g key={p.label} tabIndex={0} aria-label={`${p.label}: ${a} ${p.aText}, ${b} ${p.bText}`} className="group outline-none">
              <title>{`${p.label}\n${a}: ${p.aText}\n${b}: ${p.bText}`}</title>
              <rect x={cx - band / 2 + 2} y={top} width={band - 4} height={H - top - bottom} rx={6} className="fill-transparent group-hover:fill-[#0f766e0b] group-focus:fill-[#0f766e12]" />
              <path d={column(cx - barW - 1, barW, y(0), y(p.a))} fill="var(--color-series-1)" />
              <path d={column(cx + 1, barW, y(0), y(p.b))} fill="var(--color-series-2)" />
              <text x={cx} y={H - 8} textAnchor="middle" className="fill-muted text-[11.5px] font-medium">{p.label}</text>
            </g>
          );
        })}
      </svg></div>
      <TableView caption={title} head={["Month", a, b]} rows={points.map((p) => [p.label, p.aText, p.bText])} />
    </figure>
  );
}

export type LinePoint = { label: string; value: number; text: string };

/** One balance over time (the cash forecast): a 2px line over a light wash, the end labelled, and zero marked when it dips. */
export function BalanceLine({ title, points, tableHead }: { title: string; points: LinePoint[]; tableHead: [string, string] }) {
  const H = 200, top = 16, bottom = 26, left = 46, right = 64;
  const values = points.map((p) => p.value);
  const ticks = niceTicks(Math.min(...values), Math.max(...values));
  const lo = ticks[0], hi = ticks[ticks.length - 1];
  const y = (v: number) => top + (H - top - bottom) * (1 - (v - lo) / (hi - lo));
  const step = (W - left - right) / Math.max(1, points.length - 1);
  const x = (i: number) => left + step * i;
  const line = points.map((p, i) => `${i ? "L" : "M"}${x(i).toFixed(1)},${y(p.value).toFixed(1)}`).join("");
  const base = y(Math.max(lo, Math.min(0, hi)));
  const area = `${line}L${x(points.length - 1)},${base}L${x(0)},${base}Z`;
  const last = points[points.length - 1];
  const dips = Math.min(...values) < 0;
  return (
    <figure className="m-0">
      <div className="-mx-1 overflow-x-auto px-1"><svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={title} className="h-auto w-full min-w-[480px] overflow-visible">
        {ticks.map((t) => (
          <g key={t}>
            <line x1={left} x2={W - right} y1={y(t)} y2={y(t)} stroke={t === 0 ? (dips ? "#b42318" : "#c9d3ce") : "#edf1ef"} strokeWidth={1} />
            <text x={left - 8} y={y(t)} dy="0.32em" textAnchor="end" className="fill-faint text-[11px] [font-variant-numeric:tabular-nums]">{compact(t)}</text>
          </g>
        ))}
        <path d={area} fill="var(--color-series-1)" opacity={0.1} />
        <path d={line} fill="none" stroke="var(--color-series-1)" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />
        {points.map((p, i) => (
          <g key={p.label} tabIndex={0} aria-label={`${p.label}: ${p.text}`} className="group outline-none">
            <title>{`${p.label}: ${p.text}`}</title>
            <rect x={x(i) - step / 2} y={top} width={step} height={H - top - bottom} className="fill-transparent" />
            <line x1={x(i)} x2={x(i)} y1={top} y2={H - bottom} stroke="#9fb0a9" strokeWidth={1} className="opacity-0 group-hover:opacity-100 group-focus:opacity-100" />
            <circle cx={x(i)} cy={y(p.value)} r={4} fill="var(--color-series-1)" stroke="#fff" strokeWidth={2} className="opacity-0 group-hover:opacity-100 group-focus:opacity-100" />
            {(i === 0 || i === points.length - 1 || i % 3 === 0) && (
              <text x={x(i)} y={H - 8} textAnchor="middle" className="fill-muted text-[11.5px] font-medium">{p.label}</text>
            )}
          </g>
        ))}
        <circle cx={x(points.length - 1)} cy={y(last.value)} r={4} fill="var(--color-series-1)" stroke="#fff" strokeWidth={2} />
        <text x={x(points.length - 1) + 10} y={y(last.value)} dy="0.32em" className="fill-ink text-[12px] font-semibold [font-variant-numeric:tabular-nums]">{compact(last.value)}</text>
      </svg></div>
      <TableView caption={title} head={tableHead} rows={points.map((p) => [p.label, p.text])} />
    </figure>
  );
}

/** Ageing buckets, newest to oldest: one light-to-dark ramp (validated as an ordered ramp). */
const AGE_FILL = ["#f59e0b", "#ea7f0c", "#d95f0e", "#b8420f", "#80300f"];
const AGE_TEXT = ["#0b0b0b", "#0b0b0b", "#0b0b0b", "#ffffff", "#ffffff"];

export type Part = { label: string; value: number; text: string };

/** One whole split into its parts (what customers owe, by how late): a tall bar, its legend in the same order. */
export function AgeingBar({ title, parts, total }: { title: string; parts: Part[]; total: number }) {
  const shown = parts.map((p, i) => ({ ...p, i, share: total > 0 ? Math.max(0, p.value) / total : 0 }));
  return (
    <figure className="m-0">
      <div role="img" aria-label={`${title}: ${shown.map((p) => `${p.label} ${p.text}`).join(", ")}`} className="flex h-7 w-full gap-[2px] overflow-hidden rounded-[4px] bg-line-soft">
        {shown.filter((p) => p.share > 0).map((p) => (
          <div key={p.label} title={`${p.label}: ${p.text} (${Math.round(p.share * 100)}%)`} className="flex h-full min-w-[3px] items-center rounded-[4px] px-2"
            style={{ width: `${p.share * 100}%`, background: AGE_FILL[p.i], color: AGE_TEXT[p.i] }}>
            {p.share >= 0.12 && <span className="text-[11.5px] font-semibold">{Math.round(p.share * 100)}%</span>}
          </div>
        ))}
      </div>
      <ul className="mt-3 grid gap-x-4 gap-y-2 text-[13px] sm:grid-cols-2">
        {shown.map((p) => (
          <li key={p.label} className="flex items-center justify-between gap-2 border-b border-line-soft pb-1.5">
            <span className="flex items-center gap-2 text-muted"><span aria-hidden="true" className="size-2.5 rounded-[3px]" style={{ background: AGE_FILL[p.i] }} />{p.label}</span>
            <span className="num font-semibold">{p.text}</span>
          </li>
        ))}
      </ul>
    </figure>
  );
}
