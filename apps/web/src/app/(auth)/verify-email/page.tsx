import { Suspense } from "react";
import Link from "@/components/Link";
import { connection } from "next/server";
import { ApiError, ledgerly } from "@/lib/api";

export const metadata = { title: "Confirm your email address" };

/** Opened from the emailed link: /verify-email?token=… Confirms the address and says so. */
export default function VerifyEmailPage({ searchParams }: PageProps<"/verify-email">) {
  return (
    <>
      <h1 className="text-[24px] font-extrabold tracking-tight">Confirm your email address</h1>
      <Suspense fallback={<p className="mt-4 text-sm text-muted">Checking the link…</p>}>
        <Result searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Result({ searchParams }: Pick<PageProps<"/verify-email">, "searchParams">) {
  await connection();
  const { token } = await searchParams;

  let message = "This link is not complete. Open the link from the email again.";
  let ok = false;
  if (typeof token === "string" && token !== "") {
    try {
      message = (await ledgerly.verifyEmail(token)).message;
      ok = true;
    } catch (error) {
      message =
        error instanceof ApiError && error.status === 422
          ? "This link was already used or has expired. Sign in and ask for a new confirmation email."
          : "We could not check the link just now. Try again in a minute.";
    }
  }

  return (
    <>
      <p
        role={ok ? "status" : "alert"}
        className={`mt-4 rounded-lg px-3 py-2 text-[13px] font-semibold ${ok ? "bg-good-tint text-good" : "bg-bad-tint text-bad"}`}
      >
        {message}
      </p>
      <Link href="/" className="btn btn-primary mt-5 w-full justify-center">{ok ? "Continue to Evenbooks" : "Go to Evenbooks"}</Link>
    </>
  );
}
