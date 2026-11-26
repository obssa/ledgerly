import { QuoteList } from "@/app/(app)/quotes/views";

export const metadata = { title: "Quotes" };

export default async function Page({ searchParams }: PageProps<"/quotes">) {
  return <QuoteList kind="quote" q={await searchParams} />;
}
