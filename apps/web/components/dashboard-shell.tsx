"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";

/** 16px stroke icons, sized and coloured by the surrounding nav item. */
const ICONS: Record<string, React.ReactNode> = {
  dashboard: (
    <>
      <rect x="2.5" y="2.5" width="4.5" height="4.5" rx="1.2" />
      <rect x="9" y="2.5" width="4.5" height="4.5" rx="1.2" />
      <rect x="2.5" y="9" width="4.5" height="4.5" rx="1.2" />
      <rect x="9" y="9" width="4.5" height="4.5" rx="1.2" />
    </>
  ),
  apps: (
    <>
      <path d="M8 1.9 14 5.1v5.8L8 14.1 2 10.9V5.1l6-3.2Z" />
      <path d="M2 5.1 8 8.3l6-3.2" />
      <path d="M8 8.3v5.8" />
    </>
  ),
  runs: <path d="M1.9 8.4h2.9l2-4.4 2.4 7.9 1.9-3.5h2.9" />,
  settings: (
    <>
      <path d="M2.5 5.2h11" />
      <path d="M2.5 10.8h11" />
      <circle cx="6.1" cy="5.2" r="1.7" />
      <circle cx="10.4" cy="10.8" r="1.7" />
    </>
  ),
  account: (
    <>
      <circle cx="8" cy="5.6" r="2.6" />
      <path d="M3.1 13.6c.7-2.4 2.6-3.7 4.9-3.7s4.2 1.3 4.9 3.7" />
    </>
  ),
};

const NAV = [
  { href: "/", label: "Dashboard", icon: "dashboard" },
  { href: "/projects", label: "Test apps", icon: "apps" },
  { href: "/runs", label: "Test runs", icon: "runs" },
  { href: "/settings", label: "Settings", icon: "settings" },
  { href: "/account", label: "Account", icon: "account" },
];

function NavIcon({ name }: { name: string }) {
  return (
    <svg
      className="nav-icon"
      width="16"
      height="16"
      viewBox="0 0 16 16"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.4"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      {ICONS[name]}
    </svg>
  );
}

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
          <span className="brand-mark" aria-hidden="true">
            <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor">
              <path d="M9 1.5 3.4 9.2h3.9L6.6 14.5 12.6 6.6H8.5L9 1.5Z" />
            </svg>
          </span>
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
                <NavIcon name={item.icon} />
                {item.label}
              </Link>
            );
          })}
        </nav>

        <div className="sidebar-footer">
          <div className="user">
            <span className="avatar" aria-hidden="true">
              {(username ?? "?").slice(0, 1)}
            </span>
            <span>
              <span className="user-name">{username ?? "…"}</span>
              {role && (
                <>
                  <br />
                  <span className="user-role">{role}</span>
                </>
              )}
            </span>
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
