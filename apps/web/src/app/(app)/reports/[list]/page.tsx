import { notFound } from "next/navigation";
import { TransactionReportView } from "@/components/TransactionReportView";
import { isTxReport, TX_REPORTS } from "@/lib/transactionColumns";

/** Module 10: the transaction reports (Transaction list by date, Invoice list, Open invoices, Bill list, Unpaid bills, Payments). */
export async function generateMetadata({ params }: PageProps<"/reports/[list]">) {
  const { list } = await params;
  return { title: isTxReport(list) ? TX_REPORTS[list].title : "Report" };
}

export default async function TransactionReportPage({ params, searchParams }: PageProps<"/reports/[list]">) {
  const { list } = await params;
  if (!isTxReport(list)) notFound();
  return <TransactionReportView report={list} q={await searchParams} />;
}
