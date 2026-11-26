import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { getCustomers } from "@/lib/data";
import { ProjectForm } from "../ProjectForm";

export const metadata = { title: "New project" };

/** Module 9a: add a project, for a customer or internal. */
export default async function NewProjectPage() {
  const customers = await getCustomers({});

  return (
    <>
      <PageTitle eyebrow={<><Link href="/projects" className="hover:underline">Projects</Link> / New</>} title="New project" />
      <Card label="Project">
        <ProjectForm customers={customers.data.map((c) => ({ id: c.id, name: c.name }))} />
      </Card>
    </>
  );
}
