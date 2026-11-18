import { NewQuote } from "@/app/(app)/quotes/views";

export const metadata = { title: "New quote" };

export default function Page() {
  return <NewQuote kind="quote" />;
}
