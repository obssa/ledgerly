import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { getPayOptions } from "@/lib/data";
import { PayForm } from "./PayForm";

export const metadata = { title: "Pay bills" };

/** Step 1 picks the supplier (a plain GET form, so it works without JavaScript); step 2 records the payment. */
export default async function PayBillsPage({ searchParams }: PageProps<"/payments-made/new">) {
  const { supplier } = await searchParams;
  const options = await getPayOptions(typeof supplier === "string" ? supplier : null);

  return (
    <>
      <PageTitle eyebrow="Money out / Payments made" title="Pay bills"
        actions={<Link href={`/payments-made/scheduled/new${options.supplier ? `?supplier=${encodeURIComponent(options.supplier.id)}` : ""}`} className="btn">Schedule for a later day</Link>} />
      <Card className="mb-4 max-w-[720px]" label="Supplier">
        <form className="flex flex-wrap items-end gap-2.5">
          <div className="min-w-[240px] flex-1">
            <label className="field-label" htmlFor="supplier">Supplier</label>
            <select className="field" id="supplier" name="supplier" defaultValue={options.supplier?.id ?? ""}>
              <option value="">Choose a supplier</option>
              {options.suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </div>
          <button type="submit" className="btn">Show open bills</button>
        </form>
      </Card>
      {options.supplier && <PayForm key={options.supplier.id} options={{ ...options, supplier: options.supplier }} />}
    </>
  );
}
