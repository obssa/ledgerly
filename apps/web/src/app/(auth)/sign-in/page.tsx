import Link from "next/link";
import { signIn } from "../actions";
import { AuthForm } from "../AuthForm";

export const metadata = { title: "Sign in" };

export default function SignInPage() {
  return (
    <>
      <h1 className="text-[24px] font-extrabold tracking-tight">Sign in to Ledgerly</h1>
      <AuthForm
        action={signIn}
        submit="Sign in"
        fields={[
          { name: "email", label: "Email", type: "email", autoComplete: "username" },
          { name: "password", label: "Password", type: "password", autoComplete: "current-password" },
        ]}
      />
      <p className="mt-5 text-center text-sm text-muted">
        New to Ledgerly? <Link href="/sign-up" className="font-bold text-brand">Create an account</Link>
      </p>
    </>
  );
}
