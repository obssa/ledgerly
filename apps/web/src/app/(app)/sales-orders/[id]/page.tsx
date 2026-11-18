import { QuoteView } from "@/app/(app)/quotes/views";

export const metadata = { title: "Sales order" };

export default async function Page({ params, searchParams }: PageProps<"/sales-orders/[id]">) {
  const [{ id }, { saved }] = await Promise.all([params, searchParams]);
  return <QuoteView kind="sales_order" id={id} saved={saved === "1"} />;
}
