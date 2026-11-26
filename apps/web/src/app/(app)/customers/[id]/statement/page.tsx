import { StatementView } from "@/components/StatementView";

export const metadata = { title: "Customer statement" };

/** The customer's statement for a period: what they owed before it, every movement, and what they owe at the end. */
export default async function CustomerStatementPage({ params, searchParams }: PageProps<"/customers/[id]/statement">) {
  const { id } = await params;
  return <StatementView party="customer" id={id} q={await searchParams} />;
}
