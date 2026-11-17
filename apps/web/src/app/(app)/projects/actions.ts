"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { projectBody } from "@/lib/projects";
import { getSession } from "@/lib/session";

/* The Server Function behind the project forms. The API checks who may manage projects (projects.manage) and every rule. */

export async function saveProject(_: FormState | undefined, form: FormData): Promise<FormState> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  const id = text(form, "id") ?? null;
  let saved: string;
  try {
    saved = (await ledgerly.saveProject(id, projectBody(form, id === null), { token, companyId })).id;
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
    if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot manage projects." };
    return formStateFromError(error);
  }
  if (id) {
    refresh();
    return { notice: "Saved." };
  }
  redirect(`/projects/${encodeURIComponent(saved)}`);
}
