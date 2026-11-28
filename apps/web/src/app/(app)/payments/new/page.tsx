import { Card, PageTitle } from "@/components/ui";
import { getReceiveOptions } from "@/lib/data";
import { PaymentForm } from "./PaymentForm";

export const metadata = { title: "Receive a payment" };

/** Step 1 picks the customer (a plain GET form, so it works without JavaScript); step 2 records the payment. */
export default async function ReceivePaymentPage({ searchParams }: PageProps<"/payments/new">) {
  const { customer } = await searchParams;
  const options = await getReceiveOptions(typeof customer === "string" ? customer : null);

  return (
    <>
      <PageTitle title="Receive a payment" />
      <Card className="mb-4 max-w-[720px]" label="Customer">
        <form className="flex flex-wrap items-end gap-2.5">
          <div className="min-w-[240px] flex-1">
            <label className="field-label" htmlFor="customer">Customer</label>
            <select className="field" id="customer" name="customer" defaultValue={options.customer?.id ?? ""}>
              <option value="">Choose a customer</option>
              {options.customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>
          <button type="submit" className="btn">Show open invoices</button>
        </form>
      </Card>
      {options.customer && <PaymentForm key={options.customer.id} options={{ ...options, customer: options.customer }} />}
    </>
  );
}
