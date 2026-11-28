import { StatementsList } from "@/components/StatementsList";

export const metadata = { title: "Supplier statements" };

/** Suppliers' statements as the books have them, to check against the statements they send. */
export default async function SupplierStatementsPage({ searchParams }: PageProps<"/suppliers/statements">) {
  return <StatementsList side="supplier" q={await searchParams} />;
}
