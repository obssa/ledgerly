import { StatementView } from "@/components/StatementView";

export const metadata = { title: "Supplier statement" };

/** What the company owed a supplier before a period, every bill and payment in it, and what it owes at the end. */
export default async function SupplierStatementPage({ params, searchParams }: PageProps<"/suppliers/[id]/statement">) {
  const { id } = await params;
  return <StatementView party="supplier" id={id} q={await searchParams} />;
}
