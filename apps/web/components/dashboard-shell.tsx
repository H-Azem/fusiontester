"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";

const NAV = [
  { href: "/", label: "Dashboard" },
  { href: "/projects", label: "Test apps" },
  { href: "/runs", label: "Test runs" },
  { href: "/settings", label: "Settings" },
  { href: "/account", label: "Account" },
];

export function DashboardShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const [username, setUsername] = useState<string | null>(null);
  const [role, setRole] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    fetch("/api/auth/me", { cache: "no-store" })
      .then((response) => (response.ok ? response.json() : null))
      .then((data: { user?: { username: string; role: string } } | null) => {
        if (!cancelled && data?.user) {
          setUsername(data.user.username);
          setRole(data.user.role);
        }
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, []);

  async function signOut() {
    await fetch("/api/auth/logout", { method: "POST" });
    router.replace("/login");
    router.refresh();
  }

  const activeItem = NAV.filter((item) => item.href !== "/").find((item) =>
    pathname.startsWith(item.href),
  );
  const title = activeItem?.label ?? "Dashboard";

  return (
    <div className="shell">
      <aside className="sidebar">
        <Link href="/" className="brand">
          Fusion Tester
        </Link>

        <nav>
          {NAV.map((item) => {
            const active = item.href === "/" ? pathname === "/" : pathname.startsWith(item.href);
            return (
              <Link
                key={item.href}
                href={item.href}
                className={active ? "nav-item active" : "nav-item"}
              >
                <span className="nav-dot" />
                {item.label}
              </Link>
            );
          })}
        </nav>

        <div className="sidebar-footer">
          <div className="user">
            <span className="user-name">{username ?? "…"}</span>
            {role && <span className="muted">{role}</span>}
          </div>
          <button type="button" onClick={() => void signOut()}>
            Sign out
          </button>
        </div>
      </aside>

      <div className="content">
        <header className="topbar">
          <h1>{title}</h1>
        </header>
        <div className="page">{children}</div>
      </div>
    </div>
  );
}
