import { useCallback, useEffect, useState } from "react";
import type { FormEvent } from "react";
import { Link } from "react-router-dom";
import { Search, UserPlus, RefreshCw, Pencil, Eye } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { createUser, getApiError, getAssignableRoles, listUsers, updateUser, updateUserStatus, type LfmsRole, type LfmsUser, type Pagination } from "@/services/user-management";

const initialForm = { full_name: "", email: "", employee_id: "", role: "staff_sa" };
export default function UserManagementPage() {
  const { can, identity } = useAuth();
  const [users, setUsers] = useState<LfmsUser[]>([]);
  const [roles, setRoles] = useState<LfmsRole[]>([]);
  const [pagination, setPagination] = useState<Pagination | undefined>();
  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [roleFilter, setRoleFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [form, setForm] = useState(initialForm);
  const [editing, setEditing] = useState<LfmsUser | null>(null);
  const [details, setDetails] = useState<LfmsUser | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const canManage = can("accounts.manage");

  const load = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const [result, availableRoles] = await Promise.all([
        listUsers({ page, per_page: 10, search, role: roleFilter || undefined, status: statusFilter || undefined }),
        getAssignableRoles(),
      ]);
      setUsers(result.users); setPagination(result.pagination); setRoles(availableRoles);
    } catch (cause) { setError(getApiError(cause)); }
    finally { setLoading(false); }
  }, [page, search, roleFilter, statusFilter]);
  useEffect(() => { void load(); }, [load]);

  const submitSearch = (event: FormEvent) => { event.preventDefault(); setPage(1); setSearch(searchInput.trim()); };
  const startEdit = (user: LfmsUser) => {
    setEditing(user); setDetails(null); setShowCreate(false);
    setForm({ full_name: user.full_name, email: user.email, employee_id: user.employee_id ?? "", role: user.roles[0] ?? "staff_sa" });
    setError(null); setNotice(null);
  };
  const submitCreate = async (event: FormEvent) => {
    event.preventDefault(); setSaving(true); setError(null); setNotice(null);
    try {
      await createUser({ full_name: form.full_name.trim(), email: form.email.trim(), employee_id: form.employee_id.trim() || undefined, role: form.role });
      setForm(initialForm); setShowCreate(false); setPage(1); setSearch(""); setSearchInput("");
      setNotice("Invitation sent. The new account is listed below.");
      await load();
    } catch (cause) { setError(getApiError(cause)); }
    finally { setSaving(false); }
  };
  const submitEdit = async (event: FormEvent) => {
    event.preventDefault(); if (!editing) return;
    setSaving(true); setError(null); setNotice(null);
    try {
      const input: { full_name: string; employee_id: string | null; role?: string } = { full_name: form.full_name.trim(), employee_id: form.employee_id.trim() || null };
      if (editing.id !== identity?.id) input.role = form.role;
      await updateUser(editing.id, input);
      setEditing(null); setNotice("Account details updated."); await load();
    } catch (cause) { setError(getApiError(cause)); }
    finally { setSaving(false); }
  };
  const toggleStatus = async (user: LfmsUser) => {
    if (user.id === identity?.id) { setError("You cannot change your own account status here."); return; }
    const next = user.status === "active" ? "inactive" : "active";
    if (!window.confirm(`Are you sure you want to ${next === "inactive" ? "deactivate" : "activate"} ${user.full_name}?`)) return;
    setBusyId(user.id); setError(null); setNotice(null);
    try { await updateUserStatus(user.id, next); setNotice(`Account ${next === "active" ? "activated" : "deactivated"}.`); await load(); }
    catch (cause) { setError(getApiError(cause)); }
    finally { setBusyId(null); }
  };

  if (!can("accounts.view")) return <main className="min-h-full bg-transparent p-8"><p className="text-sm text-red-700">You do not have permission to view user accounts.</p><Link to="/dashboard" className="mt-3 inline-block text-sm underline">Back to dashboard</Link></main>;
  return <main className="min-h-full bg-transparent">
    <section className="mx-auto w-full max-w-none px-0 py-0">
      <div className="flex flex-wrap items-end justify-between gap-4"><div><p className="text-sm font-semibold text-primary">Administration</p><h1 className="mt-1 text-3xl font-semibold text-foreground">User accounts</h1><p className="mt-2 text-sm text-muted-foreground">Create accounts, manage role assignments, and control access.</p></div>
        {canManage && <button onClick={() => {setShowCreate(true);setEditing(null);setDetails(null);setForm(initialForm);setError(null);}} className="inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-3 text-sm font-semibold text-primary-foreground hover:bg-slate-700"><UserPlus size={17}/> Invite user</button>}
      </div>
      {error && <p role="alert" className="mt-5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-destructive">{error}</p>}
      {notice && <p role="status" className="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{notice}</p>}
      {(showCreate || editing) && <form onSubmit={editing ? submitEdit : submitCreate} className="mt-6 rounded-2xl border border-border bg-card p-5 shadow-sm">
        <div className="flex items-center justify-between"><h2 className="font-semibold text-foreground">{editing ? "Edit account" : "Invite a new user"}</h2><button type="button" onClick={() => {setEditing(null);setShowCreate(false);}} className="text-sm text-muted-foreground hover:text-foreground">Cancel</button></div>
        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <label className="text-sm text-muted-foreground">Full name<input required minLength={2} maxLength={160} value={form.full_name} onChange={e=>setForm({...form,full_name:e.target.value})} className="mt-1 w-full rounded-lg border border-input px-3 py-2.5 text-foreground"/></label>
          {!editing && <label className="text-sm text-muted-foreground">Email<input required type="email" value={form.email} onChange={e=>setForm({...form,email:e.target.value})} className="mt-1 w-full rounded-lg border border-input px-3 py-2.5 text-foreground"/></label>}
          <label className="text-sm text-muted-foreground">Employee ID<input value={form.employee_id} onChange={e=>setForm({...form,employee_id:e.target.value})} maxLength={80} className="mt-1 w-full rounded-lg border border-input px-3 py-2.5 text-foreground"/></label>
          {(!editing || editing.id !== identity?.id) && canManage && <label className="text-sm text-muted-foreground">Role<select value={form.role} onChange={e=>setForm({...form,role:e.target.value})} className="mt-1 w-full rounded-lg border border-input bg-card px-3 py-2.5 text-foreground">{roles.map(item=><option key={item.name} value={item.name}>{item.name === "staff_sa" ? "Staff / SA" : "Admin"}</option>)}</select></label>}
        </div>
        {!editing && <p className="mt-3 text-xs text-muted-foreground">Supabase sends an invitation email. The user sets their own password when accepting it.</p>}
        <button disabled={saving} className="mt-5 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground disabled:opacity-50">{saving ? "Saving…" : editing ? "Save changes" : "Send invitation"}</button>
      </form>}
      <div className="mt-6 rounded-2xl border border-border bg-card p-4 shadow-sm">
        <form onSubmit={submitSearch} className="grid gap-3 md:grid-cols-[1fr_180px_180px_auto]">
          <div className="relative"><Search size={17} className="absolute left-3 top-3 text-slate-400"/><input placeholder="Search name, email, employee ID" value={searchInput} onChange={e=>setSearchInput(e.target.value)} className="w-full rounded-lg border border-input py-2.5 pl-9 pr-3 text-sm"/></div>
          <select value={roleFilter} onChange={e=>{setPage(1);setRoleFilter(e.target.value);}} className="rounded-lg border border-input bg-card px-3 py-2.5 text-sm"><option value="">All roles</option>{roles.map(r=><option key={r.name} value={r.name}>{r.name}</option>)}</select>
          <select value={statusFilter} onChange={e=>{setPage(1);setStatusFilter(e.target.value);}} className="rounded-lg border border-input bg-card px-3 py-2.5 text-sm"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="suspended">Suspended</option></select>
          <div className="flex gap-2"><button className="rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground">Search</button><button type="button" onClick={()=>void load()} className="rounded-lg border border-input px-3 text-muted-foreground" title="Refresh"><RefreshCw size={16}/></button></div>
        </form>
        <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[760px] text-left text-sm"><thead className="border-b border-border text-xs uppercase tracking-wide text-muted-foreground"><tr><th className="px-3 py-3">User</th><th className="px-3 py-3">Employee ID</th><th className="px-3 py-3">Role</th><th className="px-3 py-3">Status</th><th className="px-3 py-3 text-right">Actions</th></tr></thead><tbody className="divide-y divide-slate-100">
          {loading ? <tr><td colSpan={5} className="px-3 py-10 text-center text-muted-foreground">Loading accounts…</td></tr> : users.length === 0 ? <tr><td colSpan={5} className="px-3 py-10 text-center text-muted-foreground">No user accounts found.</td></tr> : users.map(user=><tr key={user.id} className="hover:bg-muted/40"><td className="px-3 py-3"><p className="font-medium text-foreground">{user.full_name}</p><p className="mt-0.5 text-xs text-muted-foreground">{user.email}</p></td><td className="px-3 py-3 text-muted-foreground">{user.employee_id || "—"}</td><td className="px-3 py-3 text-muted-foreground">{user.roles.map(r=>r==="staff_sa"?"Staff / SA":r).join(", ") || "Unassigned"}</td><td className="px-3 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${user.status==="active"?"bg-emerald-50 text-emerald-700":"bg-muted text-muted-foreground"}`}>{user.status}</span></td><td className="px-3 py-3"><div className="flex justify-end gap-1"><button onClick={()=>setDetails(user)} title="View details" className="rounded-lg p-2 text-muted-foreground hover:bg-muted"><Eye size={16}/></button>{canManage && <><button onClick={()=>startEdit(user)} title="Edit account" className="rounded-lg p-2 text-muted-foreground hover:bg-muted"><Pencil size={16}/></button>{user.id!==identity?.id && <button disabled={busyId===user.id} onClick={()=>void toggleStatus(user)} className="rounded-lg px-2 py-1 text-xs font-semibold text-muted-foreground hover:bg-muted disabled:opacity-50">{busyId===user.id?"…":user.status==="active"?"Deactivate":"Activate"}</button>}</>}</div></td></tr>)}
        </tbody></table></div>
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 text-sm text-muted-foreground"><span>{pagination ? `Showing ${pagination.from ?? 0}–${pagination.to ?? 0} of ${pagination.total}` : " "}</span><div className="flex items-center gap-2"><button disabled={!pagination || page<=1 || loading} onClick={()=>setPage(p=>p-1)} className="rounded-lg border border-input px-3 py-2 disabled:opacity-40">Previous</button><span>Page {pagination?.current_page ?? page} of {pagination?.last_page ?? 1}</span><button disabled={!pagination || page>=(pagination.last_page) || loading} onClick={()=>setPage(p=>p+1)} className="rounded-lg border border-input px-3 py-2 disabled:opacity-40">Next</button></div></div>
      </div>
      {details && <div className="mt-5 rounded-2xl border border-border bg-card p-5"><div className="flex items-center justify-between"><h2 className="font-semibold text-foreground">Account details</h2><button onClick={()=>setDetails(null)} className="text-sm text-muted-foreground">Close</button></div><div className="mt-4 grid gap-3 sm:grid-cols-2"><p className="text-sm"><span className="text-muted-foreground">Name</span><br/>{details.full_name}</p><p className="text-sm"><span className="text-muted-foreground">Email</span><br/>{details.email}</p><p className="text-sm"><span className="text-muted-foreground">Employee ID</span><br/>{details.employee_id || "—"}</p><p className="text-sm"><span className="text-muted-foreground">Role</span><br/>{details.roles.join(", ")}</p><p className="text-sm"><span className="text-muted-foreground">Status</span><br/>{details.status}</p><p className="text-sm"><span className="text-muted-foreground">Created</span><br/>{new Date(details.created_at).toLocaleString()}</p></div></div>}
    </section>
  </main>;
}
