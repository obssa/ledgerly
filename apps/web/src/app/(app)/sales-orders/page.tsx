import { QuoteList } from "@/app/(app)/quotes/views";

export const metadata = { title: "Sales orders" };

export default function Page() {
  return <QuoteList kind="sales_order" />;
}
