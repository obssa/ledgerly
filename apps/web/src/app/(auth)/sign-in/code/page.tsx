import Link from "@/components/Link";
import { verifyCode } from "../../actions";
import { AuthForm } from "../../AuthForm";

export const metadata = { title: "Enter your code" };

/** Second step of sign-in for people who set up an authenticator app. */
export default function SignInCodePage() {
  return (
    <>
      <h1 className="text-[24px] font-extrabold tracking-tight">Enter your code</h1>
      <p className="mt-1.5 text-sm text-muted">Open your authenticator app and type the six-digit code for Evenbooks.</p>
      <AuthForm
        action={verifyCode}
        submit="Sign in"
        fields={[
          {
            name: "code",
            label: "Code",
            type: "text",
            autoComplete: "one-time-code",
            hint: "Lost your phone? Type one of your recovery codes instead.",
          },
        ]}
      />
      <p className="mt-5 text-center text-sm text-muted">
        <Link href="/sign-in" className="font-bold text-brand">Back to sign in</Link>
      </p>
    </>
  );
}
