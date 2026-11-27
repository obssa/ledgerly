"use client";

import { createContext, useContext, useMemo, type ReactNode } from "react";
import { DEFAULT_DATE_PREFS, formatDate, formatDateTime, type DatePrefs } from "@/lib/dates";

/*
 * The company's date preferences (calendar and date format, Settings) for client components: the server reads them once
 * per page (DatePrefsBoundary in the app layout) and every form or panel in the browser writes dates the same way.
 */
const Context = createContext<DatePrefs & { timezone?: string }>(DEFAULT_DATE_PREFS);

export function DatePrefsProvider({ prefs, children }: { prefs: DatePrefs & { timezone?: string }; children: ReactNode }) {
  return <Context.Provider value={prefs}>{children}</Context.Provider>;
}

/** `date("2026-11-27")` → "27/11/2026" in the company's format; `at(timestamp)` → its date and time. */
export function useDates() {
  const prefs = useContext(Context);
  return useMemo(() => ({
    prefs,
    date: (iso: string | null | undefined, empty?: string) => formatDate(iso, prefs, empty),
    at: (ts: string | null | undefined) => formatDateTime(ts, prefs, prefs.timezone),
  }), [prefs]);
}
