import { EditQuote } from "@/app/(app)/quotes/views";

export const metadata = { title: "Edit quote" };

export default async function Page({ params }: PageProps<"/quotes/[id]/edit">) {
  const { id } = await params;
  return <EditQuote kind="quote" id={id} />;
}
