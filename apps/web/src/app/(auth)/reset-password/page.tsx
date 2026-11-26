import { Suspense } from "react";
import Link from "@/components/Link";
import { resetPassword } from "../actions";
import { AuthForm } from "../AuthForm";

export const metadata = { title: "Choose a new password" };

/** Opened from the emailed link: /reset-password?token=… The link works once, for 60 minutes. */
export default function ResetPasswordPage({ searchParams }: PageProps<"/reset-password">) {
  return (
    <>
      <h1 className="text-[24px] font-extrabold tracking-tight">Choose a new password</h1>
      <p className="mt-1.5 text-sm text-muted">Afterwards you are signed out on every device and sign in with the new password.</p>
      <Suspense fallback={null}>
        <ResetForm searchParams={searchParams} />
      </Suspense>
      <p className="mt-5 text-center text-sm text-muted">
        <Link href="/sign-in" className="font-bold text-brand">Go to sign in</Link>
      </p>
    </>
  );
}

async function ResetForm({ searchParams }: Pick<PageProps<"/reset-password">, "searchParams">) {
  const { token } = await searchParams;
  if (typeof token !== "string" || token === "") {
    return (
      <p role="alert" className="mt-5 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">
        This link is not complete. Open the link from the email again, or ask for a new one.
      </p>
    );
  }
  return (
    <AuthForm
      action={resetPassword}
      submit="Change password"
      hidden={{ token }}
      fields={[
        {
          name: "password",
          label: "New password",
          type: "password",
          autoComplete: "new-password",
          hint: "At least 10 characters. Passwords found in known data leaks are refused.",
        },
      ]}
    />
  );
}
