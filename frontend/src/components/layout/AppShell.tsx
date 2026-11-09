import { useState } from "react";
import { Activity, Bell, Building2, CalendarDays, ChevronDown, ClipboardList, FileBarChart, LayoutDashboard, LogOut, Menu, Megaphone, Package, ShieldCheck, Users, Wrench, X } from "lucide-react";
import { NavLink, Outlet, useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";
import NotificationBell from "@/components/NotificationBell";

const items = [
  { label: "Dashboard", path: "/dashboard", icon: LayoutDashboard },
  { label: "Facilities", path: "/facilities", icon: Building2, permissions: ["facilities.view", "facilities.manage"] },
  { label: "Reservations", path: "/reservations", icon: CalendarDays, permissions: ["reservations.create", "reservations.manage"] },
  { label: "Inventory", path: "/inventory", icon: Package, permissions: ["inventory.view", "inventory.manage"] },
  { label: "Borrowing", path: "/borrowing", icon: ClipboardList, permissions: ["borrowing.request", "borrowing.manage"] },
  { label: "Maintenance & Incidents", path: "/maintenance-incidents", icon: Wrench, permissions: ["maintenance.create", "maintenance.manage", "incidents.create", "incidents.manage"] },
  { label: "User Management", path: "/users", icon: Users, permissions: ["accounts.view"] },
  { label: "Reports & Analytics", path: "/reports", icon: FileBarChart, permissions: ["reports.view"] },
  { label: "Announcements", path: "/announcements" },
  { label: "Notifications", path: "/notifications", icon: Bell, permissions: ["notifications.view_own"] },
  { label: "Activity History", path: "/activity", icon: Activity },
  { label: "Audit History", path: "/audit", icon: ShieldCheck, permissions: ["audit.view"] },
];
const titles: Record<string, string> = {
  "/dashboard":"Dashboard","/facilities":"Facilities","/reservations":"Reservations","/inventory":"Inventory",
  "/borrowing":"Borrowing","/maintenance-incidents":"Maintenance & Incidents","/users":"User Management",
  "/profile":"My Profile","/reports":"Reports & Analytics","/announcements":"Announcements",
  "/notifications":"Notifications","/activity":"Activity History","/audit":"Audit History"
};

function Sidebar({ close }: { close?: () => void }) {
  const { can } = useAuth();
  return <>
    <div className="flex h-[4.5rem] items-center gap-3 border-b border-sidebar-border px-5">
      <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-sidebar-primary text-sidebar-primary-foreground"><Building2 className="h-5 w-5"/></div>
      <div><p className="text-sm font-bold tracking-[0.16em]">LFMS</p><p className="text-[11px] text-sidebar-foreground/60">Laboratory & Facility Management</p></div>
    </div>
    <nav aria-label="Main navigation" className="flex-1 space-y-1 overflow-y-auto px-3 py-5">
      <p className="px-3 pb-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-sidebar-foreground/45">Workspace</p>
      {items.filter(item => !item.permissions || item.permissions.some(can)).map(({label,path,icon:Icon}) => <NavLink key={path} to={path} onClick={close} className={({isActive}) => `flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-[13px] font-medium transition-colors ${isActive ? "bg-sidebar-accent text-sidebar-accent-foreground" : "text-sidebar-foreground/70 hover:bg-sidebar-accent/70 hover:text-sidebar-foreground"}`}><Icon className="h-[17px] w-[17px] shrink-0 opacity-80"/><span className="truncate">{label}</span></NavLink>)}
    </nav>
    <div className="border-t border-sidebar-border px-5 py-4 text-[11px] text-sidebar-foreground/50">Laboratory & Facility Management System</div>
  </>;
}

export default function AppShell() {
  const { identity, profile, roles, signOut } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const [mobileOpen,setMobileOpen] = useState(false);
  const [profileOpen,setProfileOpen] = useState(false);
  const [signingOut,setSigningOut] = useState(false);
  const name = profile?.full_name?.trim() || identity?.email || "My account";
  const initials = name.split(/\s+/).filter(Boolean).slice(0,2).map(part=>part[0]?.toUpperCase()).join("") || "U";
  const title = titles[location.pathname] || "LFMS Workspace";
  const logout = async () => { setSigningOut(true); try { await signOut(); navigate("/login",{replace:true}); } finally { setSigningOut(false); } };

  return <div className="min-h-screen bg-background text-foreground">
    <aside className="fixed inset-y-0 left-0 z-30 hidden w-[264px] flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground lg:flex"><Sidebar/></aside>
    {mobileOpen && <div className="fixed inset-0 z-50 lg:hidden"><button aria-label="Close navigation" onClick={()=>setMobileOpen(false)} className="absolute inset-0 bg-black/40"/><aside className="relative flex h-full w-[min(19rem,85vw)] flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground shadow-2xl"><button type="button" aria-label="Close menu" onClick={()=>setMobileOpen(false)} className="absolute right-3 top-3 z-10 rounded-lg p-2 hover:bg-sidebar-accent"><X className="h-5 w-5"/></button><Sidebar close={()=>setMobileOpen(false)}/></aside></div>}
    <div className="min-h-screen lg:pl-[264px]">
      <header className="sticky top-0 z-20 flex h-[4.5rem] items-center justify-between gap-4 border-b border-border bg-background/95 px-4 backdrop-blur sm:px-6 lg:px-8">
        <div className="flex min-w-0 items-center gap-3"><button type="button" onClick={()=>setMobileOpen(true)} aria-label="Open navigation" className="rounded-lg p-2 text-muted-foreground hover:bg-muted lg:hidden"><Menu className="h-5 w-5"/></button><div className="min-w-0"><p className="truncate text-sm font-semibold">{title}</p><p className="hidden text-xs text-muted-foreground sm:block">Laboratory & Facility Management System</p></div></div>
        <div className="flex shrink-0 items-center gap-2 sm:gap-3"><NotificationBell/><div className="relative">
          <button type="button" onClick={()=>setProfileOpen(v=>!v)} aria-expanded={profileOpen} aria-haspopup="menu" className="flex items-center gap-2 rounded-xl p-1.5 text-left hover:bg-muted"><span className="flex h-9 w-9 items-center justify-center rounded-full bg-secondary text-xs font-semibold text-secondary-foreground">{initials}</span><span className="hidden max-w-36 sm:block"><span className="block truncate text-xs font-semibold">{name}</span><span className="block truncate text-[11px] text-muted-foreground">{roles.join(", ") || "LFMS account"}</span></span><ChevronDown className="hidden h-4 w-4 text-muted-foreground sm:block"/></button>
          {profileOpen && <><button aria-label="Close account menu" className="fixed inset-0 z-10 cursor-default" onClick={()=>setProfileOpen(false)}/><div role="menu" className="absolute right-0 z-20 mt-2 w-56 rounded-xl border border-border bg-popover p-1.5 text-popover-foreground shadow-lg"><div className="border-b border-border px-3 py-2.5"><p className="truncate text-sm font-medium">{name}</p><p className="truncate text-xs text-muted-foreground">{identity?.email ?? ""}</p></div><NavLink role="menuitem" to="/profile" onClick={()=>setProfileOpen(false)} className="block rounded-lg px-3 py-2.5 text-sm hover:bg-muted">My profile</NavLink><button role="menuitem" type="button" disabled={signingOut} onClick={()=>void logout()} className="flex w-full items-center gap-2 rounded-lg px-3 py-2.5 text-left text-sm text-destructive hover:bg-muted disabled:opacity-60"><LogOut className="h-4 w-4"/>{signingOut?"Signing out…":"Sign out"}</button></div></>}
        </div></div>
      </header>
      <main className="mx-auto w-full max-w-[90rem] px-4 py-6 sm:px-6 sm:py-8 lg:px-8"><Outlet/></main>
    </div>
  </div>;
}
