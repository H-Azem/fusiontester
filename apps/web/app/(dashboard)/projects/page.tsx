import { TestApps } from "@/components/test-apps";

export default function ProjectsPage() {
  return (
    <>
      <section className="panel">
        <div className="panel-head">
          <div>
            <h2>Test apps</h2>
            <p className="panel-sub">
              Pick a repository, open its branches, then choose the flows to drive.
            </p>
          </div>
        </div>
        <TestApps />
      </section>
    </>
  );
}
