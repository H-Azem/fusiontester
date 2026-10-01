import { RunsList } from "@/components/runs-list";

export default function RunsPage() {
  return (
    <section className="panel">
      <div className="panel-head">
        <div>
          <h2>Test runs</h2>
          <p className="panel-sub">Open a run to watch its steps, its device and its screenshots.</p>
        </div>
      </div>
      <RunsList />
    </section>
  );
}
