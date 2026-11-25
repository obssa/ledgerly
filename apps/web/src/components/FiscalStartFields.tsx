"use client";

import { useId, useState } from "react";
import { ETHIOPIAN_MONTHS, ETHIOPIAN_MONTH_HINTS, ethiopianMonthFor, gregorianMonthFor } from "@/lib/ethiopian";

const GREGORIAN = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

/**
 * The calendar of the books and the month their fiscal year starts in, together: the month is a month of that calendar
 * (the API reads it so: app/Support/FiscalCalendar.php). With the Ethiopian calendar the months are Meskerem to Nehase,
 * each with the Gregorian months it falls in; switching calendar keeps the season (July becomes Hamle, and back).
 */
export function FiscalStartFields({ calendars, defaultCalendar, defaultMonth, calendarDisabled, monthDisabled, ethiopianLabel = "Ethiopian" }: {
  calendars: string[];
  defaultCalendar: string;
  defaultMonth: number;
  calendarDisabled?: boolean;
  monthDisabled?: boolean;
  ethiopianLabel?: string;
}) {
  const [calendar, setCalendar] = useState(defaultCalendar);
  const [month, setMonth] = useState(defaultMonth);
  const calendarId = useId(), monthId = useId();
  const ethiopian = calendar === "ethiopian";
  return (
    <>
      <div>
        <label className="field-label" htmlFor={calendarId}>Calendar</label>
        <select className="field" id={calendarId} name="calendar" value={calendar} disabled={calendarDisabled}
          onChange={(e) => {
            const next = e.target.value;
            if (next !== calendar) setMonth(next === "ethiopian" ? ethiopianMonthFor(month) : gregorianMonthFor(month));
            setCalendar(next);
          }}>
          {calendars.map((c) => <option key={c} value={c}>{c === "ethiopian" ? ethiopianLabel : "Gregorian"}</option>)}
        </select>
      </div>
      <div>
        <label className="field-label" htmlFor={monthId}>Fiscal year starts in</label>
        <select className="field" id={monthId} name="fiscal_year_start_month" value={month} disabled={monthDisabled} onChange={(e) => setMonth(Number(e.target.value))}>
          {(ethiopian ? ETHIOPIAN_MONTHS.slice(0, 12) : GREGORIAN).map((m, i) => (
            <option key={m} value={i + 1}>
              {ethiopian ? `${m} (${ETHIOPIAN_MONTH_HINTS[i]})${i === 10 ? ": the tax year" : ""}` : `${m}${i === 6 ? " (usual in Ethiopia)" : ""}`}
            </option>
          ))}
        </select>
      </div>
    </>
  );
}
