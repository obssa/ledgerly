import { notFound } from "next/navigation";
import { Card, PageTitle } from "@/components/ui";
import { getRecurringInvoices } from "@/lib/data";
import { RecurringRow } from "./RecurringRow";

export const metadata = { title: "Recurring invoices" };

/** Module 2: recurring invoices (077), the next to come first. Each is set up from an invoice with "Repeat this invoice". */
export default async function RecurringInvoicesPage() {
  const data = await getRecurringInvoices();
  if (!data) notFound();
  return (
    <>
      <PageTitle title="Recurring invoices" />
      <p className="mb-4 max-w-[760px] text-sm text-muted">
        Each makes an ordinary invoice on its dates, posted or left as a draft, as the person who set it up or last resumed it. To set one up, open an
        invoice and choose &ldquo;Repeat this invoice&rdquo;.
      </p>
      <Card className="!p-2" label="Recurring invoices">
        {data.data.length === 0 ? <p className="p-3 text-sm text-muted">None yet.</p> : (
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Name</th><th scope="col">Customer</th><th scope="col">Every</th><th scope="col">Next</th><th scope="col">Until</th>
                  <th scope="col">Makes</th><th scope="col" className="!text-right">Made</th><th scope="col"><span className="sr-only">Actions</span></th></tr>
              </thead>
              <tbody>
                {data.data.map((r) => <RecurringRow key={r.id} row={r} manage={data.can.manage} />)}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </>
  );
}
