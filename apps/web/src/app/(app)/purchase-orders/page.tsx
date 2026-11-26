import { PurchaseOrderList } from "./views";

export const metadata = { title: "Purchase orders" };

export default async function Page({ searchParams }: PageProps<"/purchase-orders">) {
  return <PurchaseOrderList q={await searchParams} />;
}
