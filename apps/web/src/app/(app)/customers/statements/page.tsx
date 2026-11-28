import { StatementsList } from "@/components/StatementsList";

export const metadata = { title: "Customer statements" };

/** QuickBooks' Create statements: every customer's statement for a period, to print or send. */
export default async function CustomerStatementsPage({ searchParams }: PageProps<"/customers/statements">) {
  return <StatementsList side="customer" q={await searchParams} />;
}
