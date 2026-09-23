import { TestApps } from "@/components/test-apps";

export default function ProjectsPage() {
  return (
    <section className="panel">
      <div className="panel-head">
        <h2>Repositories</h2>
        <span className="muted">
          Pick a branch, choose tests, then start a run
        </span>
      </div>
      <TestApps />
    </section>
  );
}
