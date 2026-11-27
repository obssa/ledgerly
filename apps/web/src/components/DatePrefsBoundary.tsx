import type { ReactNode } from "react";
import { getDatePrefs } from "@/lib/data";
import { DatePrefsProvider } from "./DatePrefs";

/** Reads the company's date preferences once per page and hands them to the client components below it. */
export async function DatePrefsBoundary({ children }: { children: ReactNode }) {
  const prefs = await getDatePrefs();
  return <DatePrefsProvider prefs={prefs}>{children}</DatePrefsProvider>;
}
