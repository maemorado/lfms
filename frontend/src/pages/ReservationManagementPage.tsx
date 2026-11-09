import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Check, Plus, RefreshCw, Search, X } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { cancelReservation, checkFacilityAvailability, createReservation, decideReservation, getApiError, getReservation, listFacilities, listReservations, updateReservation, type Facility, type Pagination, type Reservation, type ReservationInput } from "@/services/facility-reservation";

const emptyForm = { facility_id: "", start_at: "", end_at: "", purpose: "", subject_course: "", participant_count: 1 };
const statuses = ["pending","approved","rejected","cancelled","completed"] as const;
function toLocalInput(value: string) {
  const date = new Date(value);
  const offset = date.getTimezoneOffset();
  return new Date(date.getTime() - offset * 60000).toISOString().slice(0,16);
}
function formatDate(value: string) { return new Date(value).toLocaleString([], { dateStyle: "medium", timeStyle: "short" }); }

export default function ReservationManagementPage() {
  const { identity, can } = useAuth();
  const canManage = can("reservations.manage");
  const canCreate = can("reservations.create");
  const [items,setItems] = useState<Reservation[]>([]);
  const [facilities,setFacilities] = useState<Facility[]>([]);
  const [pagination,setPagination] = useState<Pagination | undefined>();
  const [page,setPage] = useState(1);
  const [search,setSearch] = useState("");
  const [status,setStatus] = useState("");
  const [facilityId,setFacilityId] = useState("");
  const [fromDate,setFromDate] = useState("");
  const [toDate,setToDate] = useState("");
  const [form,setForm] = useState(emptyForm);
  const [editing,setEditing] = useState<Reservation | null>(null);
  const [showForm,setShowForm] = useState(false);
  const [selected,setSelected] = useState<Reservation | null>(null);
  const [availabilityMessage,setAvailabilityMessage] = useState<string | null>(null);
  const [loading,setLoading] = useState(true);
  const [saving,setSaving] = useState(false);
  const [error,setError] = useState<string | null>(null);
  const [notice,setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const [result,facilityResult] = await Promise.all([
        listReservations({page,per_page:15,search:search || undefined,status:status || undefined,facility_id:facilityId || undefined,from_date:fromDate || undefined,to_date:toDate || undefined}),
        listFacilities({page:1,per_page:100,status:"available"}),
      ]);
      setItems(result.items); setPagination(result.pagination); setFacilities(facilityResult.items);
    } catch(cause) { setError(getApiError(cause)); }
    finally { setLoading(false); }
  },[page,search,status,facilityId,fromDate,toDate]);
  useEffect(()=>{void load();},[load]);

  const startEdit = (item: Reservation) => {
    setEditing(item); setSelected(null); setShowForm(true);
    setForm({facility_id:item.facility_id,start_at:toLocalInput(item.start_at),end_at:toLocalInput(item.end_at),purpose:item.purpose,subject_course:item.subject_course ?? "",participant_count:item.participant_count});
  };
  const submit = async (event: FormEvent) => {
    event.preventDefault(); setSaving(true); setError(null); setNotice(null);
    try {
      const payload: ReservationInput = {facility_id:form.facility_id,start_at:new Date(form.start_at).toISOString(),end_at:new Date(form.end_at).toISOString(),purpose:form.purpose.trim(),subject_course:form.subject_course.trim() || null,participant_count:form.participant_count};
      if(editing) await updateReservation(editing.id,payload); else await createReservation(payload);
      setEditing(null); setShowForm(false); setForm(emptyForm); setNotice(editing ? "Pending reservation updated." : "Reservation request submitted. It is awaiting Admin review.");
      await load();
    } catch(cause) { setError(getApiError(cause)); }
    finally { setSaving(false); }
  };
  const checkAvailability = async () => {
    if (!form.facility_id || !form.start_at || !form.end_at) { setAvailabilityMessage("Select a facility, start time, and end time first."); return; }
    try {
      const result = await checkFacilityAvailability(form.facility_id,new Date(form.start_at).toISOString(),new Date(form.end_at).toISOString());
      setAvailabilityMessage(result.is_available ? "No approved reservation conflicts were found. Admin approval is still required." : "This facility is unavailable for the selected time.");
    } catch(cause) { setAvailabilityMessage(getApiError(cause)); }
  };
  const openDetails = async (item: Reservation) => {
    setSelected(item);
    try { setSelected(await getReservation(item.id)); }
    catch(cause) { setError(getApiError(cause)); }
  };
  const cancel = async (item: Reservation) => {
    const reason = window.prompt("Why are you cancelling this reservation?");
    if(!reason?.trim()) return;
    setError(null); setNotice(null);
    try { await cancelReservation(item.id,reason.trim()); setNotice("Reservation cancelled."); await load(); if(selected?.id===item.id) setSelected(null); }
    catch(cause) { setError(getApiError(cause)); }
  };
  const decide = async (item: Reservation, decision: "approved" | "rejected") => {
    const remarks = window.prompt(decision==="rejected" ? "Enter the reason for rejection (required):" : "Optional approval remarks:");
    if(remarks===null || (decision==="rejected" && !remarks.trim())) return;
    setError(null); setNotice(null);
    try { await decideReservation(item.id,decision,remarks.trim() || undefined); setNotice(`Reservation ${decision}.`); await load(); if(selected?.id===item.id) setSelected(null); }
    catch(cause) { setError(getApiError(cause)); }
  };

  return <main className="min-h-full bg-transparent">
    <section className="mx-auto w-full max-w-none px-0 py-0">
      <div className="flex flex-wrap items-end justify-between gap-4"><div><p className="text-sm font-semibold text-primary">{canManage ? "Administration" : "Staff workspace"}</p><h1 className="mt-1 text-3xl font-semibold text-foreground">{canManage ? "Reservation management" : "My reservations"}</h1><p className="mt-2 text-sm text-muted-foreground">{canManage ? "Review requests, manage approvals, and monitor facility schedules." : "Request a facility and track the status of your bookings."}</p></div>
        {canCreate && <button onClick={()=>{setEditing(null);setShowForm(true);setForm({...emptyForm,facility_id:facilities[0]?.id ?? ""});setSelected(null);setError(null);}} className="inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-3 text-sm font-semibold text-primary-foreground"><Plus size={17}/> New request</button>}
      </div>
      {error && <p role="alert" className="mt-5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-destructive">{error}</p>}
      {notice && <p role="status" className="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{notice}</p>}
      {canCreate && (showForm || editing !== null) && <form onSubmit={submit} className="mt-6 rounded-2xl border border-border bg-card p-5 shadow-sm">
        <div className="flex items-center justify-between"><h2 className="font-semibold text-foreground">{editing ? "Edit pending request" : "Request a reservation"}</h2><button type="button" onClick={()=>{setEditing(null);setShowForm(false);setForm(emptyForm);}} className="text-sm text-muted-foreground">Close form</button></div>
        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <label className="text-sm text-muted-foreground">Facility<select required value={form.facility_id} onChange={e=>setForm({...form,facility_id:e.target.value})} className="mt-1 w-full rounded-lg border border-input bg-card px-3 py-2.5"><option value="">Select available facility</option>{facilities.map(f=><option key={f.id} value={f.id}>{f.name} · capacity {f.capacity}</option>)}</select></label>
          <label className="text-sm text-muted-foreground">Start date and time<input required type="datetime-local" value={form.start_at} onChange={e=>setForm({...form,start_at:e.target.value})} className="mt-1 w-full rounded-lg border border-input px-3 py-2.5"/></label>
          <label className="text-sm text-muted-foreground">End date and time<input required type="datetime-local" min={form.start_at || undefined} value={form.end_at} onChange={e=>setForm({...form,end_at:e.target.value})} className="mt-1 w-full rounded-lg border border-input px-3 py-2.5"/></label>
          <label className="text-sm text-muted-foreground">Participant count<input required type="number" min={1} value={form.participant_count} onChange={e=>setForm({...form,participant_count:Number(e.target.value)})} className="mt-1 w-full rounded-lg border border-input px-3 py-2.5"/></label>
          <label className="text-sm text-muted-foreground">Subject / course<input value={form.subject_course} onChange={e=>setForm({...form,subject_course:e.target.value})} className="mt-1 w-full rounded-lg border border-input px-3 py-2.5"/></label>
          <label className="text-sm text-muted-foreground sm:col-span-2 lg:col-span-1">Purpose<textarea required minLength={3} value={form.purpose} onChange={e=>setForm({...form,purpose:e.target.value})} rows={2} className="mt-1 w-full rounded-lg border border-input px-3 py-2.5"/></label>
        </div>
        <div className="mt-3 flex flex-wrap items-center gap-3"><button type="button" onClick={()=>void checkAvailability()} className="rounded-lg border border-input px-3 py-2 text-xs font-semibold text-muted-foreground">Check availability</button>{availabilityMessage && <p role="status" className="text-xs text-muted-foreground">{availabilityMessage}</p>}</div><p className="mt-3 text-xs text-muted-foreground">Requests start as pending. An Admin must approve them before the facility is reserved. Overlapping approved bookings are rejected by the server and PostgreSQL.</p>
        <button disabled={saving || !facilities.length} className="mt-5 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground disabled:opacity-50">{saving ? "Saving…" : editing ? "Save changes" : "Submit request"}</button>
      </form>}
      <div className="mt-6 rounded-2xl border border-border bg-card p-4 shadow-sm">
        <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-5">
          <div className="relative lg:col-span-2"><Search size={17} className="absolute left-3 top-3 text-slate-400"/><input value={search} onChange={e=>{setPage(1);setSearch(e.target.value);}} placeholder="Search purpose or facility" className="w-full rounded-lg border border-input py-2.5 pl-9 pr-3 text-sm"/></div>
          <select value={status} onChange={e=>{setPage(1);setStatus(e.target.value);}} className="rounded-lg border border-input bg-card px-3 py-2.5 text-sm"><option value="">All statuses</option>{statuses.map(s=><option key={s} value={s}>{s}</option>)}</select>
          <select value={facilityId} onChange={e=>{setPage(1);setFacilityId(e.target.value);}} className="rounded-lg border border-input bg-card px-3 py-2.5 text-sm"><option value="">All facilities</option>{facilities.map(f=><option key={f.id} value={f.id}>{f.name}</option>)}</select>
          <button onClick={()=>void load()} className="inline-flex items-center justify-center gap-2 rounded-lg border border-input px-3 py-2.5 text-sm"><RefreshCw size={16}/> Refresh</button>
          <label className="text-xs text-muted-foreground">From<input type="date" value={fromDate} onChange={e=>{setPage(1);setFromDate(e.target.value);}} className="mt-1 w-full rounded-lg border border-input px-2 py-2 text-sm text-muted-foreground"/></label>
          <label className="text-xs text-muted-foreground">To<input type="date" value={toDate} onChange={e=>{setPage(1);setToDate(e.target.value);}} className="mt-1 w-full rounded-lg border border-input px-2 py-2 text-sm text-muted-foreground"/></label>
        </div>
        <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[850px] text-left text-sm"><thead className="border-b border-border text-xs uppercase tracking-wide text-muted-foreground"><tr><th className="px-3 py-3">Facility / Requester</th><th className="px-3 py-3">Schedule</th><th className="px-3 py-3">Purpose</th><th className="px-3 py-3">Status</th><th className="px-3 py-3 text-right">Actions</th></tr></thead><tbody className="divide-y divide-slate-100">
          {loading ? <tr><td colSpan={5} className="px-3 py-10 text-center text-muted-foreground">Loading reservations…</td></tr> : items.length===0 ? <tr><td colSpan={5} className="px-3 py-10 text-center text-muted-foreground">No reservations found.</td></tr> : items.map(item=><tr key={item.id} className="hover:bg-muted/40"><td className="px-3 py-3"><p className="font-medium text-foreground">{item.facility.name}</p><p className="mt-0.5 text-xs text-muted-foreground">{canManage ? item.requester.full_name : "Your request"} · {item.facility.code}</p></td><td className="px-3 py-3"><p>{formatDate(item.start_at)}</p><p className="text-xs text-muted-foreground">to {formatDate(item.end_at)}</p></td><td className="max-w-[240px] px-3 py-3 text-muted-foreground"><span className="line-clamp-2">{item.purpose}</span></td><td className="px-3 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-muted-foreground">{item.status}</span></td><td className="px-3 py-3"><div className="flex justify-end gap-1"><button onClick={()=>void openDetails(item)} className="rounded-lg border border-input px-2.5 py-2 text-xs font-semibold">Details</button>{canCreate && item.requester_id===identity?.id && item.status==="pending" && <button onClick={()=>startEdit(item)} className="rounded-lg border border-input px-2.5 py-2 text-xs font-semibold">Edit</button>}{canManage && item.status==="pending" && <><button title="Approve" onClick={()=>void decide(item,"approved")} className="rounded-lg bg-emerald-50 p-2 text-emerald-700"><Check size={16}/></button><button title="Reject" onClick={()=>void decide(item,"rejected")} className="rounded-lg bg-red-50 p-2 text-red-700"><X size={16}/></button></>}{(canManage || item.requester_id===identity?.id) && ["pending","approved"].includes(item.status) && new Date(item.start_at)>new Date() && <button onClick={()=>void cancel(item)} className="rounded-lg border border-input px-2 py-2 text-xs">Cancel</button>}</div></td></tr>)}
        </tbody></table></div>
        <div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-4 text-sm text-muted-foreground"><span>{pagination ? `${pagination.total} reservations` : ""}</span><div className="flex items-center gap-2"><button disabled={!pagination || page<=1 || loading} onClick={()=>setPage(p=>p-1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">Previous</button><span>{pagination?.current_page ?? page} / {pagination?.last_page ?? 1}</span><button disabled={!pagination || page>=(pagination.last_page) || loading} onClick={()=>setPage(p=>p+1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">Next</button></div></div>
      </div>
      {selected && <section className="mt-5 rounded-2xl border border-border bg-card p-5"><div className="flex items-center justify-between"><h2 className="font-semibold text-foreground">Reservation details</h2><button onClick={()=>setSelected(null)} className="text-sm text-muted-foreground">Close</button></div><div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3"><p className="text-sm"><span className="text-muted-foreground">Facility</span><br/>{selected.facility.name}</p><p className="text-sm"><span className="text-muted-foreground">Requester</span><br/>{selected.requester.full_name}</p><p className="text-sm"><span className="text-muted-foreground">Status</span><br/>{selected.status}</p><p className="text-sm"><span className="text-muted-foreground">Start</span><br/>{formatDate(selected.start_at)}</p><p className="text-sm"><span className="text-muted-foreground">End</span><br/>{formatDate(selected.end_at)}</p><p className="text-sm"><span className="text-muted-foreground">Participants</span><br/>{selected.participant_count}</p><p className="text-sm sm:col-span-2"><span className="text-muted-foreground">Purpose</span><br/>{selected.purpose}</p></div>
        {selected.cancellation_reason && <p className="mt-3 text-sm text-muted-foreground">Cancellation reason: {selected.cancellation_reason}</p>}
        {selected.approvals && selected.approvals.length>0 && <div className="mt-5 border-t border-slate-100 pt-4"><h3 className="text-sm font-semibold">Review history</h3>{selected.approvals.map((a,i)=><div key={i} className="mt-2 rounded-lg bg-muted/40 p-3 text-sm"><p className="font-medium">{a.decision} by {a.reviewer_name}</p><p className="text-xs text-muted-foreground">{formatDate(a.reviewed_at)}</p>{a.remarks && <p className="mt-1 text-muted-foreground">{a.remarks}</p>}</div>)}</div>}
      </section>}
    </section>
  </main>;
}
