import { EditQuote } from "@/app/(app)/quotes/views";

export const metadata = { title: "Edit sales order" };

export default async function Page({ params }: PageProps<"/sales-orders/[id]/edit">) {
  const { id } = await params;
  return <EditQuote kind="sales_order" id={id} />;
}
