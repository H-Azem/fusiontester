import { RunsList } from "@/components/runs-list";

export default function RunsPage() {
  return (
    <section className="panel">
      <div className="panel-head">
        <h2>All runs</h2>
        <span className="muted">Click a row for its step-by-step progress</span>
      </div>
      <RunsList />
    </section>
  );
}
