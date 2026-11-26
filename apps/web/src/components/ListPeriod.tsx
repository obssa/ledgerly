import Link from "@/components/Link";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { periodQuery, type PickedRange } from "@/lib/periodParams";

/**
 * A list's period, as QuickBooks' lists have one: every date preset (All dates by default), in the company's calendar.
 * A plain GET form, so it works without JavaScript; the page's other choices ride along.
 */
export function ListPeriod({ picked, keep }: { picked: PickedRange; keep?: Record<string, string | undefined> }) {
  return <DateRangeFilter picked={picked} keep={keep} label="Dates" button="Show" />;
}

/** Newer and older pages of a list, keeping its period (the API's cursor; 50 a page). */
export function ListPager({ path, picked, next, prev, keep = {} }: {
  path: string; picked: PickedRange; next: string | null; prev: string | null; keep?: Record<string, string | undefined>;
}) {
  if (!next && !prev) return null;
  const href = (cursor: string) => `${path}?${new URLSearchParams(Object.entries({ ...keep, ...periodQuery(picked), cursor }).filter(([, v]) => v) as [string, string][])}`;
  return (
    <nav aria-label="Pages" className="mt-3 flex gap-2 print:hidden">
      {prev && <Link href={href(prev)} className="btn">Newer</Link>}
      {next && <Link href={href(next)} className="btn">Older</Link>}
    </nav>
  );
}
