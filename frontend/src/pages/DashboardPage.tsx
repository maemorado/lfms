import { useCallback, useEffect, useMemo, useState } from "react";
import {
  Activity,
  AlertTriangle,
  ArrowDownRight,
  ArrowRight,
  Bell,
  Building2,
  CalendarClock,
  CalendarDays,
  CheckCircle2,
  ClipboardList,
  FileBarChart,
  LayoutGrid,
  Package,
  RefreshCw,
  ShieldCheck,
  Users,
  Wrench,
  type LucideIcon,
} from "lucide-react";
import { Link } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";
import { api } from "@/lib/api";

type ReservationSummary = {
  id: string;
  purpose: string;
  start_at: string;
  end_at: string;
  status?: string;
  facilities?: { name?: string };
};

type NotificationSummary = {
  id: string;
  title: string;
  message: string;
  created_at: string;
};

type DashboardResponse = {
  metrics?: Record<string, Record<string, number>>;
  current_reservations?: ReservationSummary[];
  upcoming_reservations?: ReservationSummary[];
  recent_notifications?: NotificationSummary[];
};

type ModuleLink = {
  label: string;
  description: string;
  path: string;
  icon: LucideIcon;
  permissions?: string[];
};

const moduleLinks: ModuleLink[] = [
  { label: "Facilities", description: "Browse available spaces and equipment", path: "/facilities", icon: Building2, permissions: ["facilities.view", "facilities.manage"] },
  { label: "Reservations", description: "Review and manage facility bookings", path: "/reservations", icon: CalendarDays, permissions: ["reservations.create", "reservations.manage"] },
  { label: "Inventory", description: "View laboratory and facility supplies", path: "/inventory", icon: Package, permissions: ["inventory.view", "inventory.manage"] },
  { label: "Borrowing", description: "Track requests and borrowed items", path: "/borrowing", icon: ClipboardList, permissions: ["borrowing.request", "borrowing.manage"] },
  { label: "Maintenance & incidents", description: "Submit and monitor reported issues", path: "/maintenance-incidents", icon: Wrench, permissions: ["maintenance.create", "maintenance.manage", "incidents.create", "incidents.manage"] },
  { label: "User management", description: "Manage user accounts and assignments", path: "/users", icon: Users, permissions: ["accounts.view"] },
  { label: "Reports & analytics", description: "Explore operational reports", path: "/reports", icon: FileBarChart, permissions: ["reports.view"] },
  { label: "Announcements", description: "Read the latest organization updates", path: "/announcements", icon: Bell },
  { label: "Notifications", description: "Review your notification inbox", path: "/notifications", icon: Bell, permissions: ["notifications.view_own", "notifications.manage"] },
  { label: "Activity history", description: "Review your recent workspace activity", path: "/activity", icon: Activity },
  { label: "Audit history", description: "Review recorded administrative activity", path: "/audit", icon: ShieldCheck, permissions: ["audit.view"] },
];

const metricIcons: Array<[RegExp, LucideIcon]> = [
  [/reserv|booking/i, CalendarDays],
  [/facility|room|space/i, Building2],
  [/inventory|stock|item/i, Package],
  [/borrow|loan/i, ClipboardList],
  [/maintenance|repair/i, Wrench],
  [/incident|issue/i, AlertTriangle],
  [/user|account|staff/i, Users],
  [/notification/i, Bell],
];

function metricIcon(label: string): LucideIcon {
  return metricIcons.find(([pattern]) => pattern.test(label))?.[1] ?? LayoutGrid;
}

function formatDateTime(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? "Date unavailable"
    : date.toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
}

function formatTime(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? "Time unavailable"
    : date.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
}

function ReservationList({
  title,
  description,
  items,
  emptyMessage,
  upcoming = false,
}: {
  title: string;
  description: string;
  items: ReservationSummary[];
  emptyMessage: string;
  upcoming?: boolean;
}) {
  return (
    <section className="rounded-2xl border border-border bg-card p-5 shadow-sm sm:p-6">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold tracking-tight">{title}</h2>
          <p className="mt-1 text-sm text-muted-foreground">{description}</p>
        </div>
        <span className="rounded-lg bg-muted px-2.5 py-1 text-xs font-semibold tabular-nums text-muted-foreground">{items.length}</span>
      </div>
      {items.length > 0 ? (
        <ul className="mt-5 divide-y divide-border">
          {items.map((item) => (
            <li key={item.id} className="flex gap-3 py-4 first:pt-0 last:pb-0">
              <div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground">
                <CalendarClock className="h-4 w-4" aria-hidden="true" />
              </div>
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold">{item.purpose || "Facility reservation"}</p>
                <p className="mt-1 truncate text-xs text-muted-foreground">{item.facilities?.name || "Facility not specified"}</p>
                <p className="mt-2 text-xs text-muted-foreground">
                  {formatDateTime(item.start_at)}{upcoming ? "" : ` – ${formatTime(item.end_at)}`}
                </p>
              </div>
              {upcoming && item.status && (
                <span className="h-fit shrink-0 rounded-full bg-muted px-2.5 py-1 text-[11px] font-medium capitalize text-muted-foreground">{item.status.replaceAll("_", " ")}</span>
              )}
            </li>
          ))}
        </ul>
      ) : (
        <div className="mt-5 rounded-xl border border-dashed border-border px-4 py-8 text-center">
          <CalendarDays className="mx-auto h-5 w-5 text-muted-foreground/60" aria-hidden="true" />
          <p className="mt-2 text-sm text-muted-foreground">{emptyMessage}</p>
        </div>
      )}
      <Link to="/reservations" className="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-foreground hover:underline">
        View reservations <ArrowRight className="h-4 w-4" aria-hidden="true" />
      </Link>
    </section>
  );
}

export default function DashboardPage() {
  const { identity, user, profile, roles, can } = useAuth();
  const [dashboard, setDashboard] = useState<DashboardResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const loadDashboard = useCallback(async (isRefresh = false) => {
    if (isRefresh) setRefreshing(true);
    else setLoading(true);
    setError(null);
    try {
      const response = await api.get("/v1/dashboard");
      setDashboard(response.data?.data ?? null);
    } catch {
      setError("Dashboard information is temporarily unavailable. Please try again.");
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    void loadDashboard();
  }, [loadDashboard]);

  const name = profile?.full_name?.trim() || identity?.email?.split("@")[0] || user?.email?.split("@")[0] || "there";
  const visibleModules = useMemo(
    () => moduleLinks.filter((item) => !item.permissions || item.permissions.some((permission) => can(permission))),
    [can],
  );
  const metrics = Object.entries(dashboard?.metrics ?? {}).flatMap(([group, values]) =>
    Object.entries(values).map(([key, value]) => ({
      id: `${group}-${key}`,
      label: `${group.replaceAll("_", " ")} · ${key.replaceAll("_", " ")}`,
      value,
    })),
  );

  return (
    <div className="space-y-7">
      <section className="relative overflow-hidden rounded-2xl border border-border bg-card p-6 shadow-sm sm:p-8">
        <div className="pointer-events-none absolute -right-12 -top-20 h-64 w-64 rounded-full bg-muted/80 blur-3xl" />
        <div className="relative flex flex-col justify-between gap-6 sm:flex-row sm:items-end">
          <div className="max-w-2xl">
            <div className="inline-flex items-center gap-2 rounded-full border border-border bg-background/80 px-3 py-1.5 text-xs font-medium text-muted-foreground">
              <span className="h-1.5 w-1.5 rounded-full bg-success" />
              Workspace overview
            </div>
            <h1 className="mt-5 text-2xl font-semibold tracking-tight sm:text-3xl">Welcome back, {name}.</h1>
            <p className="mt-2 max-w-xl text-sm leading-6 text-muted-foreground">Here’s what’s happening across your laboratory and facility workspace.</p>
            <div className="mt-4 flex flex-wrap items-center gap-2">
              {roles.map((role) => <span key={role} className="rounded-full bg-muted px-3 py-1 text-xs font-medium capitalize text-muted-foreground">{role.replaceAll("_", " ")}</span>)}
              <span className="text-xs text-muted-foreground">{identity?.email ?? user?.email ?? ""}</span>
            </div>
          </div>
          <button type="button" onClick={() => void loadDashboard(true)} disabled={refreshing || loading} className="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-xl border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-60">
            <RefreshCw className={`h-4 w-4 ${refreshing ? "animate-spin" : ""}`} aria-hidden="true" />
            {refreshing ? "Refreshing…" : "Refresh data"}
          </button>
        </div>
      </section>

      <section aria-labelledby="overview-heading">
        <div className="mb-4 flex items-end justify-between gap-3">
          <div><h2 id="overview-heading" className="text-base font-semibold tracking-tight">Operational overview</h2><p className="mt-1 text-sm text-muted-foreground">Live figures returned by the LFMS dashboard service.</p></div>
          {loading && <span className="text-xs text-muted-foreground">Loading metrics…</span>}
        </div>
        {error && <div role="alert" className="mb-4 flex flex-col gap-3 rounded-xl border border-destructive/30 bg-destructive/5 p-4 sm:flex-row sm:items-center sm:justify-between"><p className="text-sm text-destructive">{error}</p><button type="button" onClick={() => void loadDashboard(true)} className="text-left text-sm font-semibold text-destructive underline underline-offset-4">Try again</button></div>}
        {loading ? (
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{[0,1,2,3].map((item) => <div key={item} className="h-28 animate-pulse rounded-2xl border border-border bg-muted/60" />)}</div>
        ) : metrics.length > 0 ? (
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {metrics.map((metric) => {
              const Icon = metricIcon(metric.label);
              return <article key={metric.id} className="rounded-2xl border border-border bg-card p-5 shadow-sm transition-shadow hover:shadow-md">
                <div className="flex items-center justify-between gap-3"><span className="text-xs font-medium capitalize text-muted-foreground">{metric.label}</span><span className="flex h-9 w-9 items-center justify-center rounded-xl bg-muted text-muted-foreground"><Icon className="h-4 w-4" aria-hidden="true" /></span></div>
                <p className="mt-4 text-3xl font-semibold tracking-tight tabular-nums">{Number(metric.value).toLocaleString()}</p>
              </article>;
            })}
          </div>
        ) : !error ? (
          <div className="rounded-2xl border border-dashed border-border bg-card px-5 py-10 text-center"><LayoutGrid className="mx-auto h-6 w-6 text-muted-foreground/60" /><p className="mt-3 text-sm font-medium">No operational metrics available</p><p className="mt-1 text-xs text-muted-foreground">There are no metric values to display for this account right now.</p></div>
        ) : null}
      </section>

      <section className="grid gap-4 xl:grid-cols-2">
        <ReservationList title="Happening now" description="Reservations currently in progress" items={dashboard?.current_reservations ?? []} emptyMessage="There are no current reservations." />
        <ReservationList title="Coming up" description="Your next scheduled reservations" items={dashboard?.upcoming_reservations ?? []} emptyMessage="There are no upcoming reservations." upcoming />
      </section>

      <section className="rounded-2xl border border-border bg-card p-5 shadow-sm sm:p-6">
        <div className="flex items-start justify-between gap-3">
          <div><h2 className="text-base font-semibold tracking-tight">Recent notifications</h2><p className="mt-1 text-sm text-muted-foreground">The latest updates delivered to your account.</p></div>
          {can("notifications.view_own") && <Link to="/notifications" className="inline-flex shrink-0 items-center gap-1 text-sm font-semibold hover:underline">View all <ArrowRight className="h-4 w-4" /></Link>}
        </div>
        {dashboard?.recent_notifications?.length ? (
          <ul className="mt-4 grid gap-3 md:grid-cols-2">
            {dashboard.recent_notifications.map((item) => <li key={item.id} className="flex gap-3 rounded-xl bg-muted/50 p-4"><span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-background text-muted-foreground"><Bell className="h-4 w-4" /></span><div className="min-w-0"><p className="truncate text-sm font-semibold">{item.title}</p><p className="mt-1 line-clamp-2 text-xs leading-5 text-muted-foreground">{item.message}</p><p className="mt-2 text-[11px] text-muted-foreground">{formatDateTime(item.created_at)}</p></div></li>)}
          </ul>
        ) : <div className="mt-4 rounded-xl border border-dashed border-border px-4 py-7 text-center"><CheckCircle2 className="mx-auto h-5 w-5 text-muted-foreground/60" /><p className="mt-2 text-sm text-muted-foreground">No recent notifications.</p></div>}
      </section>

      <section>
        <div className="mb-4 flex items-end justify-between gap-3"><div><h2 className="text-base font-semibold tracking-tight">Quick access</h2><p className="mt-1 text-sm text-muted-foreground">Jump directly to the tools available to your account.</p></div><span className="text-xs text-muted-foreground">{visibleModules.length} modules</span></div>
        {visibleModules.length > 0 ? <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">{visibleModules.map(({label,description,path,icon:Icon}) => <Link key={path} to={path} className="group flex items-start gap-3 rounded-2xl border border-border bg-card p-4 shadow-sm transition-all hover:-translate-y-0.5 hover:border-foreground/20 hover:shadow-md"><span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground transition-colors group-hover:bg-primary group-hover:text-primary-foreground"><Icon className="h-5 w-5" aria-hidden="true" /></span><span className="min-w-0 flex-1"><span className="block text-sm font-semibold">{label}</span><span className="mt-1 block text-xs leading-5 text-muted-foreground">{description}</span></span><ArrowDownRight className="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:translate-y-0.5" aria-hidden="true" /></Link>)}</div> : <p className="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">No modules are available for this account.</p>}
      </section>
    </div>
  );
}
