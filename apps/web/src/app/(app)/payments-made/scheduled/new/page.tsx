import { Card, PageTitle } from "@/components/ui";
import { getPayOptions } from "@/lib/data";
import { todayIn } from "@/lib/dates";
import { ScheduleForm } from "../ScheduleForm";

export const metadata = { title: "Schedule a payment" };

/** Step 1 picks the supplier (a plain GET form); step 2 plans which of their bills to pay, and when. */
export default async function SchedulePaymentPage({ searchParams }: PageProps<"/payments-made/scheduled/new">) {
  const { supplier } = await searchParams;
  const options = await getPayOptions(typeof supplier === "string" ? supplier : null);
  const chosen = options.supplier;
  const currency = chosen?.currency && options.currencies.includes(chosen.currency) ? chosen.currency : options.baseCurrency;

  return (
    <>
      <PageTitle eyebrow="Money out / Scheduled payments" title="Schedule a payment"
        description="Plan a supplier payment for a later day. Nothing is recorded until you confirm the money went out." />
      <Card className="mb-4 max-w-[720px]" label="Supplier">
        <form className="flex flex-wrap items-end gap-2.5">
          <div className="min-w-[240px] flex-1">
            <label className="field-label" htmlFor="supplier">Supplier</label>
            <select className="field" id="supplier" name="supplier" defaultValue={chosen?.id ?? ""}>
              <option value="">Choose a supplier</option>
              {options.suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </div>
          <button type="submit" className="btn">Show open bills</button>
        </form>
      </Card>
      {chosen && (
        <ScheduleForm key={chosen.id} supplier={chosen} currency={currency} today={todayIn()} demo={options.demo}
          bills={options.bills.filter((b) => b.currency === currency).map((b) => ({ id: b.id, number: b.number, reference: b.reference, due_date: b.due_date, amount_due: b.amount_due, currency: b.currency }))}
          accounts={options.accounts.filter((a) => (a.currency ?? options.baseCurrency) === currency)} />
      )}
    </>
  );
}
