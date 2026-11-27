"use client";

import { useDates } from "./DatePrefs";

/**
 * A stored date written the company's way (Settings, Date format): `<Dt v="2026-11-27" />` → 27/11/2026, or
 * 11/27/2026, or the Ethiopian calendar's numbers. Empty dates show `empty` ("—"). With `time`, a timestamp shows its
 * date and time in the company's time zone.
 */
export function Dt({ v, empty = "—", time = false }: { v: string | null | undefined; empty?: string; time?: boolean }) {
  const { date, at } = useDates();
  if (!v) return <>{empty}</>;
  if (time) return <>{at(v)}</>;
  // A moment shown as a day: the day it was in the company's time zone, not in UTC.
  if (/^\d{4}-\d{2}-\d{2}T/.test(v)) return <>{at(v).split(" ")[0]}</>;
  return <>{date(v, empty)}</>;
}
