import { EditPurchaseOrder } from "../../views";

export const metadata = { title: "Edit purchase order" };

export default async function Page({ params }: PageProps<"/purchase-orders/[id]/edit">) {
  const { id } = await params;
  return <EditPurchaseOrder id={id} />;
}
