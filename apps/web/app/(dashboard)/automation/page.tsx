import { AutomationWorkspace } from "@/components/automation-workspace";
import { SettingsGate } from "@/components/settings-gate";

/**
 * Automation holds the same weight as Settings — a rule reaches the repositories and
 * the device — so it opens behind the same settings password.
 */
export default function AutomationPage() {
  return (
    <SettingsGate>
      <AutomationWorkspace />
    </SettingsGate>
  );
}
