import { QuoteView } from "@/app/(app)/quotes/views";

export const metadata = { title: "Quote" };

export default async function Page({ params, searchParams }: PageProps<"/quotes/[id]">) {
  const [{ id }, { saved }] = await Promise.all([params, searchParams]);
  return <QuoteView kind="quote" id={id} saved={saved === "1"} />;
}
