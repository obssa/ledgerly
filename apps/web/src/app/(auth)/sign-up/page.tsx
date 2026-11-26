import Link from "@/components/Link";
import { signUp } from "../actions";
import { AuthForm } from "../AuthForm";

export const metadata = { title: "Create an account" };

export default function SignUpPage() {
  return (
    <>
      <h1 className="text-[24px] font-extrabold tracking-tight">Create your account</h1>
      <p className="mt-1.5 text-sm text-muted">Then set up your company&apos;s books.</p>
      <AuthForm
        action={signUp}
        submit="Create account"
        fields={[
          { name: "name", label: "Your name", type: "text", autoComplete: "name" },
          { name: "email", label: "Email", type: "email", autoComplete: "email" },
          {
            name: "password",
            label: "Password",
            type: "password",
            autoComplete: "new-password",
            hint: "At least 10 characters. Passwords found in known data leaks are refused.",
          },
        ]}
      />
      <p className="mt-5 text-center text-sm text-muted">
        Already have an account? <Link href="/sign-in" className="font-bold text-brand">Sign in</Link>
      </p>
    </>
  );
}
