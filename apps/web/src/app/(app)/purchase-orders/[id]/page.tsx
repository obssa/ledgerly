import { PurchaseOrderView } from "../views";

export const metadata = { title: "Purchase order" };

export default async function Page({ params, searchParams }: PageProps<"/purchase-orders/[id]">) {
  const [{ id }, { saved }] = await Promise.all([params, searchParams]);
  return <PurchaseOrderView id={id} saved={saved === "1"} />;
}
