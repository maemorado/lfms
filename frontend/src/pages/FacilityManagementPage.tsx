import { useCallback, useEffect, useState, type FormEvent } from "react";
import { ArrowLeft, Building2, Pencil, Plus, RefreshCw, Search, Warehouse } from "lucide-react";
import { Link } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";
import { createFacility, getApiError, getFacilityLookups, listFacilities, updateFacility, type Facility, type FacilityLookups, type Pagination } from "@/services/facility-reservation";

const emptyForm = { building_id: "", facility_type_id: "", name: "", code: "", capacity: 1, description: "", status: "available" as Facility["status"] };
const statuses: Facility["status"][] = ["available","unavailable","maintenance","inactive"];

export default function FacilityManagementPage() {
  const { can } = useAuth();
  const canManage = can("facilities.manage");
  const [items,setItems] = useState<Facility[]>([]);
  const [lookups,setLookups] = useState<FacilityLookups | null>(null);
  const [pagination,setPagination] = useState<Pagination | undefined>();
  const [page,setPage] = useState(1);
  const [search,setSearch] = useState("");
  const [status,setStatus] = useState("");
  const [form,setForm] = useState(emptyForm);
  const [editing,setEditing] = useState<Facility | null>(null);
  const [showForm,setShowForm] = useState(false);
  const [selected,setSelected] = useState<Facility | null>(null);
  const [loading,setLoading] = useState(true);
  const [saving,setSaving] = useState(false);
  const [error,setError] = useState<string | null>(null);
  const [notice,setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const [result,options] = await Promise.all([
        listFacilities({page,per_page:12,search:search || undefined,status:status || undefined}),
        getFacilityLookups(),
      ]);
      setItems(result.items); setPagination(result.pagination); setLookups(options);
    } catch (cause) { setError(getApiError(cause)); }
    finally { setLoading(false); }
  },[page,search,status]);
  useEffect(()=>{void load();},[load]);

  const startEdit = (item: Facility) => {
    setEditing(item); setSelected(null); setShowForm(true);
    setForm({building_id:item.building_id,facility_type_id:item.facility_type_id,name:item.name,code:item.code,capacity:item.capacity,description:item.description ?? "",status:item.status});
  };
  const submit = async (event: FormEvent) => {
    event.preventDefault(); setSaving(true); setError(null); setNotice(null);
    try {
      const payload = {...form,name:form.name.trim(),code:form.code.trim(),description:form.description.trim() || null};
      if(editing) await updateFacility(editing.id,payload); else await createFacility(payload);
      setEditing(null); setShowForm(false); setForm(emptyForm); setNotice(editing ? "Facility updated." : "Facility created.");
      await load();
    } catch(cause) { setError(getApiError(cause)); }
    finally { setSaving(false); }
  };

  return <main className="min-h-screen bg-slate-50">
    <header className="border-b border-slate-200 bg-white"><div className="mx-auto flex max-w-7xl items-center justify-between px-5 py-4"><Link to="/dashboard" className="inline-flex items-center gap-2 text-sm text-slate-600"><ArrowLeft size={17}/> Dashboard</Link><span className="text-sm font-semibold tracking-widest text-slate-800">LFMS / FACILITIES</span></div></header>
    <section className="mx-auto max-w-7xl px-5 py-8">
      <div className="flex flex-wrap items-end justify-between gap-4"><div><p className="text-sm font-semibold text-teal-700">Facilities and laboratories</p><h1 className="mt-1 text-3xl font-semibold text-slate-950">Facility directory</h1><p className="mt-2 text-sm text-slate-600">Browse laboratory spaces, capacity, location, and operational status.</p></div>
        {canManage && <button onClick={()=>{setEditing(null);setShowForm(true);setForm(emptyForm);setError(null);}} className="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white"><Plus size={17}/> New facility</button>}
      </div>
      {error && <p role="alert" className="mt-5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">{error}</p>}
      {notice && <p role="status" className="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{notice}</p>}
      {canManage && (showForm || editing !== null) && <form onSubmit={submit} className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex items-center justify-between"><h2 className="font-semibold text-slate-900">{editing ? "Edit facility" : "Create facility"}</h2><button type="button" onClick={()=>{setEditing(null);setShowForm(false);setForm(emptyForm);}} className="text-sm text-slate-500">Close form</button></div>
        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <label className="text-sm text-slate-600">Facility name<input required value={form.name} onChange={e=>setForm({...form,name:e.target.value})} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5"/></label>
          <label className="text-sm text-slate-600">Code<input required value={form.code} onChange={e=>setForm({...form,code:e.target.value})} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5"/></label>
          <label className="text-sm text-slate-600">Building<select required value={form.building_id} onChange={e=>setForm({...form,building_id:e.target.value})} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5"><option value="">Select building</option>{lookups?.buildings.map(b=><option key={b.id} value={b.id}>{b.name}</option>)}</select></label>
          <label className="text-sm text-slate-600">Facility type<select required value={form.facility_type_id} onChange={e=>setForm({...form,facility_type_id:e.target.value})} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5"><option value="">Select type</option>{lookups?.facility_types.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}</select></label>
          <label className="text-sm text-slate-600">Capacity<input type="number" min={1} required value={form.capacity} onChange={e=>setForm({...form,capacity:Number(e.target.value)})} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5"/></label>
          <label className="text-sm text-slate-600">Status<select value={form.status} onChange={e=>setForm({...form,status:e.target.value as Facility["status"]})} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5">{statuses.map(s=><option key={s} value={s}>{s}</option>)}</select></label>
          <label className="text-sm text-slate-600 sm:col-span-2">Description<textarea value={form.description} onChange={e=>setForm({...form,description:e.target.value})} rows={2} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5"/></label>
        </div>
        <button disabled={saving || !lookups?.buildings.length || !lookups?.facility_types.length} className="mt-5 rounded-xl bg-teal-800 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{saving ? "Saving…" : editing ? "Save changes" : "Create facility"}</button>
      </form>}
      <div className="mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div className="grid gap-3 md:grid-cols-[1fr_220px_auto]"><div className="relative"><Search size={17} className="absolute left-3 top-3 text-slate-400"/><input value={search} onChange={e=>{setPage(1);setSearch(e.target.value);}} placeholder="Search facility, code, or building" className="w-full rounded-lg border border-slate-300 py-2.5 pl-9 pr-3 text-sm"/></div><select value={status} onChange={e=>{setPage(1);setStatus(e.target.value);}} className="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm"><option value="">All statuses</option>{statuses.map(s=><option key={s} value={s}>{s}</option>)}</select><button onClick={()=>void load()} className="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-3 py-2.5 text-sm"><RefreshCw size={16}/> Refresh</button></div>
        <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {loading ? <p className="col-span-full py-10 text-center text-sm text-slate-500">Loading facilities…</p> : items.length===0 ? <p className="col-span-full py-10 text-center text-sm text-slate-500">No facilities match your filters.</p> : items.map(item=><article key={item.id} className="rounded-xl border border-slate-200 p-4 hover:border-teal-300"><div className="flex items-start justify-between gap-3"><div className="flex items-center gap-3"><span className="rounded-xl bg-teal-50 p-3 text-teal-800"><Warehouse size={20}/></span><div><h3 className="font-semibold text-slate-900">{item.name}</h3><p className="text-xs text-slate-500">{item.code} · {item.facility_type.name}</p></div></div><span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">{item.status}</span></div><p className="mt-4 flex items-center gap-2 text-sm text-slate-600"><Building2 size={15}/>{item.building.name}</p><p className="mt-1 text-sm text-slate-600">Capacity: {item.capacity}</p><p className="mt-2 line-clamp-2 text-sm text-slate-500">{item.description || "No description provided."}</p><div className="mt-4 flex gap-2"><button onClick={()=>setSelected(item)} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700">Details</button>{canManage && <button onClick={()=>startEdit(item)} className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700"><Pencil size={13}/> Edit</button>}</div></article>)}
        </div>
        <div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-4 text-sm text-slate-500"><span>{pagination ? `${pagination.total} facilities` : ""}</span><div className="flex items-center gap-2"><button disabled={!pagination || page<=1 || loading} onClick={()=>setPage(p=>p-1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">Previous</button><span>{pagination?.current_page ?? page} / {pagination?.last_page ?? 1}</span><button disabled={!pagination || page>=pagination.last_page || loading} onClick={()=>setPage(p=>p+1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">Next</button></div></div>
      </div>
      {selected && <div className="mt-5 rounded-2xl border border-slate-200 bg-white p-5"><div className="flex items-center justify-between"><h2 className="font-semibold">Facility details</h2><button onClick={()=>setSelected(null)} className="text-sm text-slate-500">Close</button></div><div className="mt-3 grid gap-3 sm:grid-cols-2"><p className="text-sm">Name: {selected.name}</p><p className="text-sm">Code: {selected.code}</p><p className="text-sm">Building: {selected.building.name}</p><p className="text-sm">Type: {selected.facility_type.name}</p><p className="text-sm">Capacity: {selected.capacity}</p><p className="text-sm">Status: {selected.status}</p></div></div>}
    </section>
  </main>;
}
