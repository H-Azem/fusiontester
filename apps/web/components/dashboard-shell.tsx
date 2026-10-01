"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { Bird } from "./bird";
import { Icon } from "./icons";

/**
 * One navigation model, two choreographies: a rail beside the content on desktop
 * and Material's bottom navigation bar on touch widths. Both read the same list,
 * so a destination can never exist in one and be missing from the other.
 */
const NAV = [
  { href: "/", label: "Dashboard", icon: "dashboard" },
  { href: "/projects", label: "Test apps", icon: "apps" },
  { href: "/runs", label: "Test runs", icon: "runs" },
  { href: "/automation", label: "Automate", icon: "bolt" },
  { href: "/settings", label: "Settings", icon: "settings" },
];

const TITLES: Record<string, string> = {
  "/": "Dashboard",
  "/projects": "Test apps",
  "/runs": "Test runs",
  "/automation": "Automate test",
  "/settings": "Settings",
};

export function DashboardShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const [user, setUser] = useState<{ username: string; role: string } | null>(null);

  useEffect(() => {
    let cancelled = false;

    fetch("/api/auth/me", { cache: "no-store" })
      .then((response) => (response.ok ? response.json() : null))
      .then((data: { user?: { username: string; role: string } } | null) => {
        if (!cancelled && data?.user) setUser(data.user);
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

  const isActive = (href: string) =>
    href === "/" ? pathname === "/" : pathname.startsWith(href);

  const active = [...NAV].reverse().find((item) => isActive(item.href));
  const title =
    TITLES[active?.href ?? "/runs/" + pathname.split("/")[2]] ??
    (pathname.startsWith("/runs/") ? "Test run" : "Dashboard");

  return (
    <div className="shell">
      <aside className="rail">
        <Link href="/" className="brand">
          <Bird state="success" size={32} />
          <span className="brand-text">
            <span className="brand-name">Fusion Tester</span>
            <span className="brand-sub">FLUTTER APP TESTING</span>
          </span>
        </Link>

        <nav className="nav" aria-label="Main">
          {NAV.map((item) => (
            <Link
              key={item.href}
              href={item.href}
              className={isActive(item.href) ? "nav-item active" : "nav-item"}
              aria-current={isActive(item.href) ? "page" : undefined}
            >
              <Icon name={item.icon} size={20} />
              {item.label}
            </Link>
          ))}
        </nav>

        <div className="rail-footer">
          <div className="user-chip">
            <span className="avatar" aria-hidden="true">
              {(user?.username ?? "?").slice(0, 1)}
            </span>
            <span className="brand-text">
              <span className="user-name">{user?.username ?? "…"}</span>
              {user?.role && <span className="user-role">{user.role}</span>}
            </span>
          </div>
          <button type="button" className="md-button text" onClick={() => void signOut()}>
            <Icon name="logout" size={18} />
            Sign out
          </button>
        </div>
      </aside>

      <div className="content">
        <header className="top-bar">
          <span className="top-bar-brand" aria-hidden="true">
            <Bird state="success" size={28} />
          </span>
          <h1>{title}</h1>
          <Link href="/runs" className="md-button icon" aria-label="Test runs">
            <Icon name="runs" size={20} />
          </Link>
        </header>

        <div className="page">{children}</div>
      </div>

      <nav className="nav-bar" aria-label="Main">
        {NAV.map((item) => (
          <Link
            key={item.href}
            href={item.href}
            className={isActive(item.href) ? "active" : ""}
            aria-current={isActive(item.href) ? "page" : undefined}
          >
            <Icon name={item.icon} size={22} />
            {item.label}
          </Link>
        ))}
      </nav>
    </div>
  );
}
