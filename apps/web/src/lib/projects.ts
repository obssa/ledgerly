/* Module 9a: what the project form sends. The API checks everything again. */

export const PROJECT_STATUSES = [["active", "Active"], ["on_hold", "On hold"], ["completed", "Completed"], ["cancelled", "Cancelled"]] as const;
export const PROJECT_BILLING = [["fixed_fee", "Fixed fee"], ["time_and_materials", "Time and materials"], ["non_billable", "Not billed (internal)"]] as const;

const clean = (v: string) => v.replace(/[,\s]/g, "");

/** The body of POST or PUT /projects: empty optional fields as null, amounts without separators; the code only when new. */
export function projectBody(form: FormData, creating: boolean): Record<string, unknown> {
  const value = (n: string) => (typeof form.get(n) === "string" ? String(form.get(n)).trim() : "");
  const body: Record<string, unknown> = { name: value("name"), status: value("status") || "active", billing: value("billing") || "fixed_fee" };
  if (creating) body.code = value("code");
  for (const n of ["customer_id", "starts_on", "ends_on", "notes"]) body[n] = value(n) || null;
  for (const n of ["contract_value", "budget_income", "budget_cost", "hourly_rate"]) body[n] = clean(value(n)) || null;
  return body;
}

/** The body of POST /time-entries: hours as typed (a comma for decimals is accepted), billable unless unticked. */
export function timeBody(form: FormData): Record<string, unknown> {
  const value = (n: string) => (typeof form.get(n) === "string" ? String(form.get(n)).trim() : "");
  return {
    project_id: value("project_id"), work_date: value("work_date"), hours: value("hours").replace(",", "."),
    billable: form.get("billable") === "on", rate: clean(value("rate")) || null, note: value("note") || null,
  };
}
