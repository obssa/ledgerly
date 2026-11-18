import { QuoteList } from "@/app/(app)/quotes/views";

export const metadata = { title: "Quotes" };

export default function Page() {
  return <QuoteList kind="quote" />;
}
