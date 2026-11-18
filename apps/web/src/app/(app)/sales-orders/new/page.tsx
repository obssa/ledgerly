import { NewQuote } from "@/app/(app)/quotes/views";

export const metadata = { title: "New sales order" };

export default function Page() {
  return <NewQuote kind="sales_order" />;
}
