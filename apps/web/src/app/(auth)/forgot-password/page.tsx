import Link from "next/link";
import { forgotPassword } from "../actions";
import { AuthForm } from "../AuthForm";

export const metadata = { title: "Reset your password" };

export default function ForgotPasswordPage() {
  return (
    <>
      <h1 className="text-[24px] font-extrabold tracking-tight">Reset your password</h1>
      <p className="mt-1.5 text-sm text-muted">Enter your email address and we will send you a link to choose a new password.</p>
      <AuthForm
        action={forgotPassword}
        submit="Email me a link"
        fields={[{ name: "email", label: "Email", type: "email", autoComplete: "username" }]}
      />
      <p className="mt-5 text-center text-sm text-muted">
        <Link href="/sign-in" className="font-bold text-brand">Back to sign in</Link>
      </p>
    </>
  );
}
