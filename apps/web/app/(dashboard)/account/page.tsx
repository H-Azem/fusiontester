import { redirect } from "next/navigation";

/** The account lives in Settings now; the old address still lands somewhere useful. */
export default function AccountPage() {
  redirect("/settings");
}
