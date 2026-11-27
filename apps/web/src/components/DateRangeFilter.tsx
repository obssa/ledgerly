import { DateInput } from "./DateInput";
import { AS_OF_PRESETS, RANGE_PRESETS, type AsOfPreset, type RangePreset } from "@/lib/periods";

type Keep = Record<string, string | string[] | undefined>;

/** Fields the period form sets itself, and paging (a new period starts at the first page). */
const OWN = ["range", "shown", "from", "to", "as_of", "page", "earlier", "before_id"];

/**
 * The page's other choices (account, basis, comparison …) ride along, so changing the period keeps them. Fields the
 * form shows itself (`fields`, e.g. a warehouse select passed as children) are not repeated.
 */
function Kept({ keep, fields = [] }: { keep?: Keep; fields?: string[] }) {
  return <>{Object.entries(keep ?? {}).filter(([k, v]) => typeof v === "string" && v !== "" && !OWN.includes(k) && !fields.includes(k))
    .map(([k, v]) => <input key={k} type="hidden" name={k} value={v as string} />)}</>;
}

/**
 * The report period, as QuickBooks offers it: a preset (All dates, This month to date, Last fiscal year, Since 30
 * days ago …) or Custom dates. A plain GET form, so it works without JavaScript; `shown` tells the server which preset
 * the page was showing, so typed dates count as Custom and a newly picked preset wins (lib/periodParams.ts).
 */
export function DateRangeFilter({ picked, keep, fields, allowed, label = "Report period", button = "Run report", children }: {
  picked: { preset: RangePreset; from: string | null; to: string | null };
  keep?: Keep;
  /** Names of the fields passed as children, so they are not also kept as hidden inputs. */
  fields?: string[];
  allowed?: readonly RangePreset[];
  label?: string;
  button?: string;
  children?: React.ReactNode;
}) {
  const presets = RANGE_PRESETS.filter((p) => !allowed || allowed.includes(p.key));
  const groups = [...new Set(presets.map((p) => p.group))];
  return (
    <form className="mb-4 flex flex-wrap items-end gap-2.5 print:hidden" aria-label={label}>
      <Kept keep={keep} fields={fields} />
      <input type="hidden" name="shown" value={picked.preset} />
      <div>
        <label className="field-label" htmlFor="range">{label}</label>
        <select className="field" id="range" name="range" defaultValue={picked.preset}>
          {groups.map((g) => {
            const options = presets.filter((p) => p.group === g).map((p) => <option key={p.key} value={p.key}>{p.label}</option>);
            return g ? <optgroup key={g} label={g}>{options}</optgroup> : options;
          })}
        </select>
      </div>
      <div><label className="field-label" htmlFor="from">From</label><DateInput plain className="field !w-[150px]" id="from" name="from" defaultValue={picked.from ?? ""} /></div>
      <div><label className="field-label" htmlFor="to">To</label><DateInput plain className="field !w-[150px]" id="to" name="to" defaultValue={picked.to ?? ""} /></div>
      {children}
      <button type="submit" className="btn">{button}</button>
    </form>
  );
}

/** A report at one date: Today, End of last month, End of last fiscal year … or a date of your own. */
export function AsOfFilter({ picked, keep, fields, label = "As of", button = "Run report", children }: {
  picked: { preset: AsOfPreset; asOf: string };
  keep?: Keep;
  fields?: string[];
  label?: string;
  button?: string;
  children?: React.ReactNode;
}) {
  return (
    <form className="mb-4 flex flex-wrap items-end gap-2.5 print:hidden" aria-label={label}>
      <Kept keep={keep} fields={fields} />
      <input type="hidden" name="shown" value={picked.preset} />
      <div>
        <label className="field-label" htmlFor="range">{label}</label>
        <select className="field" id="range" name="range" defaultValue={picked.preset}>
          {AS_OF_PRESETS.map((p) => <option key={p.key} value={p.key}>{p.label}</option>)}
        </select>
      </div>
      <div><label className="field-label" htmlFor="as_of">Date</label><DateInput plain className="field !w-[150px]" id="as_of" name="as_of" defaultValue={picked.asOf} /></div>
      {children}
      <button type="submit" className="btn">{button}</button>
    </form>
  );
}
