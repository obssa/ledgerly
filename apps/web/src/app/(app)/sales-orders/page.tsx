import { QuoteList } from "@/app/(app)/quotes/views";

export const metadata = { title: "Sales orders" };

export default async function Page({ searchParams }: PageProps<"/sales-orders">) {
  return <QuoteList kind="sales_order" q={await searchParams} />;
}
