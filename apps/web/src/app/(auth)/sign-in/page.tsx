import { Suspense } from "react";
import Link from "next/link";
import { getDemoOptions } from "@/lib/data";
import { demoSignIn, signIn } from "../actions";
import { AuthForm } from "../AuthForm";

export const metadata = { title: "Sign in" };

/** One button per role into the shared demo company; nothing at all when demo sign-in is off. */
async function DemoSignIn({ searchParams }: { searchParams: PageProps<"/sign-in">["searchParams"] }) {
  const [options, { demo }] = await Promise.all([getDemoOptions(), searchParams]);
  if (!options) return null;
  return (
    <section aria-labelledby="demo_heading" className="mt-6 border-t border-line pt-5">
      <h2 id="demo_heading" className="text-[15px] font-bold">Try the demo</h2>
      <p className="mt-1 text-[13px] text-muted">
        Sign in to <span className="font-semibold text-ink">{options.company}</span> as one of its team.
      </p>
      {demo === "unavailable" && (
        <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">
          The demo is not available right now. Try again in a minute.
        </p>
      )}
      <form action={demoSignIn} className="mt-3 grid grid-cols-2 gap-2">
        {options.roles.map((r) => (
          <button key={r.role} type="submit" name="role" value={r.role} className="btn flex-col !items-start !gap-0.5 py-2 text-left">
            <span className="font-bold">{r.label}</span>
            <span className="text-[12px] font-normal text-muted">{r.about}</span>
          </button>
        ))}
      </form>
    </section>
  );
}

export default function SignInPage({ searchParams }: PageProps<"/sign-in">) {
  return (
    <>
      <h1 className="text-[24px] font-extrabold tracking-tight">Sign in to Evenbooks</h1>
      <AuthForm
        action={signIn}
        submit="Sign in"
        fields={[
          { name: "email", label: "Email", type: "email", autoComplete: "username" },
          { name: "password", label: "Password", type: "password", autoComplete: "current-password" },
        ]}
      />
      <p className="mt-4 text-center text-sm">
        <Link href="/forgot-password" className="font-bold text-brand">Forgot your password?</Link>
      </p>
      <p className="mt-3 text-center text-sm text-muted">
        New to Evenbooks? <Link href="/sign-up" className="font-bold text-brand">Create an account</Link>
      </p>
      <Suspense fallback={null}>
        <DemoSignIn searchParams={searchParams} />
      </Suspense>
    </>
  );
}
