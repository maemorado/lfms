import { useCallback, useEffect, useState, type FormEvent } from "react";
import { AlertTriangle, ArrowLeft, FileText, RefreshCw, Wrench } from "lucide-react";
import { Link } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";
import { updateAsset } from "@/services/inventory";
import {
  addMaintenanceRecord, createEvidenceUrl, createIncident, createMaintenance, getIncident,
  getMaintenance, getMaintenanceTargets, listIncidents, listMaintenance, listMaintenanceEvidence,
  maintenanceIncidentError, updateIncident, updateMaintenance, uploadEvidence,
  type IncidentReport, type MaintenanceRequest, type Targets, type PageMeta,
} from "@/services/maintenance-incidents";

type Tab="maintenance"|"incidents";
type TargetType="facility"|"asset";
const dateText=(value?:string|null)=>value?new Date(value).toLocaleString():"—";
const badge=(status:string)=>"inline-flex rounded-full px-2.5 py-1 text-xs font-semibold "+(
  ["completed","resolved","closed"].includes(status)?"bg-emerald-50 text-emerald-800":
  ["cancelled","dismissed"].includes(status)?"bg-rose-50 text-rose-800":
  ["in_progress","under_review","on_hold"].includes(status)?"bg-amber-50 text-amber-800":"bg-slate-100 text-slate-700"
);
const emptyPage:PageMeta={current_page:1,per_page:20,total:0,last_page:1};

export default function MaintenanceIncidentPage(){
  const {can,profile}=useAuth();
  const maintenanceAdmin=can("maintenance.manage");
  const incidentAdmin=can("incidents.manage");
  const [tab,setTab]=useState<Tab>("maintenance");
  const [targets,setTargets]=useState<Targets>({facilities:[],assets:[]});
  const [maintenance,setMaintenance]=useState<MaintenanceRequest[]>([]);
  const [incidents,setIncidents]=useState<IncidentReport[]>([]);
  const [maintenancePage,setMaintenancePage]=useState<PageMeta>(emptyPage);
  const [incidentPage,setIncidentPage]=useState<PageMeta>(emptyPage);
  const [search,setSearch]=useState("");
  const [status,setStatus]=useState("");
  const [loading,setLoading]=useState(true);
  const [busy,setBusy]=useState(false);
  const [error,setError]=useState<string|null>(null);
  const [notice,setNotice]=useState<string|null>(null);
  const [targetType,setTargetType]=useState<TargetType>("facility");
  const [targetId,setTargetId]=useState("");
  const [description,setDescription]=useState("");
  const [priority,setPriority]=useState("medium");
  const [incidentType,setIncidentType]=useState("damage");
  const [files,setFiles]=useState<File[]>([]);
  const [selectedMaintenance,setSelectedMaintenance]=useState<MaintenanceRequest|null>(null);
  const [selectedIncident,setSelectedIncident]=useState<IncidentReport|null>(null);
  const [maintenanceEvidence,setMaintenanceEvidence]=useState<{name:string;path:string}[]>([]);
  const [resolution,setResolution]=useState("");
  const [recordRemarks,setRecordRemarks]=useState("");
  const [assignedTo,setAssignedTo]=useState("");
  const [recordCost,setRecordCost]=useState("0");

  const load=useCallback(async()=>{
    setLoading(true);setError(null);
    try{
      const [t,m,i]=await Promise.all([
        getMaintenanceTargets(),
        listMaintenance({page:maintenancePage.current_page,per_page:maintenancePage.per_page,search:search||undefined,status:["pending","approved","in_progress","on_hold","completed","cancelled"].includes(status)?status:undefined}),
        listIncidents({page:incidentPage.current_page,per_page:incidentPage.per_page,search:search||undefined,status:["open","under_review","resolved","closed","dismissed"].includes(status)?status:undefined}),
      ]);
      setTargets(t);setMaintenance(m.requests);setMaintenancePage(m.pagination??emptyPage);setIncidents(i.incidents);setIncidentPage(i.pagination??emptyPage);
    }catch(e){setError(maintenanceIncidentError(e));}
    finally{setLoading(false);}
  },[maintenancePage.current_page,maintenancePage.per_page,incidentPage.current_page,incidentPage.per_page,search,status]);

  useEffect(()=>{void load();},[load]);

  const targetLabel=useMemo(()=>new Map([
    ...targets.facilities.map(x=>[x.id,x.name] as const),
    ...targets.assets.map(x=>[x.id,(x.inventory_items?.name??"Equipment")+" · "+x.asset_tag] as const),
  ]),[targets]);

  const submit=async(e:FormEvent)=>{
    e.preventDefault();if(!targetId)return;
    setBusy(true);setError(null);setNotice(null);
    try{
      const target=targetType==="facility"?{facility_id:targetId}:{asset_id:targetId};
      if(tab==="maintenance"){
        const created=await createMaintenance({...target,description:description.trim(),priority});
        for(const file of files)await uploadEvidence("maintenance",created.id,file);
        setNotice("Maintenance request submitted.");
      }else{
        const created=await createIncident({...target,incident_type:incidentType,description:description.trim()});
        for(const file of files)await uploadEvidence("incidents",created.id,file);
        setNotice("Incident report submitted.");
      }
      setDescription("");setFiles([]);setTargetId("");await load();
    }catch(e){setError(maintenanceIncidentError(e));}
    finally{setBusy(false);}
  };

  const openMaintenance=async(id:string)=>{
    setError(null);
    try{
      const [record,evidence]=await Promise.all([getMaintenance(id),listMaintenanceEvidence(id)]);
      setSelectedMaintenance(record);setSelectedIncident(null);setMaintenanceEvidence(evidence);setRecordRemarks("");setAssignedTo("");setRecordCost("0");
    }catch(e){setError(maintenanceIncidentError(e));}
  };
  const openIncident=async(id:string)=>{
    setError(null);
    try{const record=await getIncident(id);setSelectedIncident(record);setSelectedMaintenance(null);setResolution(record.resolution??"");}
    catch(e){setError(maintenanceIncidentError(e));}
  };

  const saveMaintenanceStatus=async(next:string)=>{
    if(!selectedMaintenance)return;setBusy(true);setError(null);
    try{
      const updated=await updateMaintenance(selectedMaintenance.id,next);
      setSelectedMaintenance(updated);
      if(next==="in_progress"&&selectedMaintenance.asset_id&&can("inventory.manage")){
        const asset=targets.assets.find(x=>x.id===selectedMaintenance.asset_id);
        if(asset?.inventory_items?.id)await updateAsset(asset.inventory_items.id,asset.id,{status:"under_repair"});
      }
      setNotice("Maintenance status updated.");await load();
    }catch(e){setError(maintenanceIncidentError(e));}
    finally{setBusy(false);}
  };

  const saveMaintenanceRecord=async(e:FormEvent)=>{
    e.preventDefault();if(!selectedMaintenance)return;setBusy(true);setError(null);
    try{
      const now=new Date().toISOString();
      await addMaintenanceRecord(selectedMaintenance.id,{
        assigned_to:assignedTo.trim()||null,
        started_at:selectedMaintenance.status==="in_progress"?now:null,
        completed_at:selectedMaintenance.status==="completed"?now:null,
        cost:Number(recordCost||0),remarks:recordRemarks.trim()||null,
      });
      setNotice("Maintenance activity recorded.");setSelectedMaintenance(await getMaintenance(selectedMaintenance.id));setRecordRemarks("");await load();
    }catch(e){setError(maintenanceIncidentError(e));}
    finally{setBusy(false);}
  };

  const saveIncident=async(nextStatus:string)=>{
    if(!selectedIncident)return;setBusy(true);setError(null);
    try{
      const updated=await updateIncident(selectedIncident.id,{status:nextStatus,resolution:resolution.trim()||null});
      setSelectedIncident(updated);
      if(selectedIncident.incident_type==="damage"||selectedIncident.incident_type==="missing"){
        if(selectedIncident.asset_id&&can("inventory.manage")){
          const asset=targets.assets.find(x=>x.id===selectedIncident.asset_id);
          if(asset?.inventory_items?.id)await updateAsset(asset.inventory_items.id,asset.id,{
            status:selectedIncident.incident_type==="missing"?"missing":"damaged",
            ...(selectedIncident.incident_type==="damage"?{condition:"damaged"}:{}),
          });
        }
      }
      setNotice("Incident investigation updated.");await load();
    }catch(e){setError(maintenanceIncidentError(e));}
    finally{setBusy(false);}
  };

  const openEvidence=async(path:string)=>{
    try{const url=await createEvidenceUrl(path);window.open(url,"_blank","noopener,noreferrer");}
    catch(e){setError(maintenanceIncidentError(e));}
  };

  const page=tab==="maintenance"?maintenancePage:incidentPage;
  const setPage=tab==="maintenance"?setMaintenancePage:setIncidentPage;
  const activeList=tab==="maintenance"?maintenance:incidents;
  const isMine=(id:string)=>id===profile?.id;

  return <main className="min-h-screen bg-slate-50">
    <header className="border-b border-slate-200 bg-white"><div className="mx-auto flex max-w-7xl items-center justify-between px-5 py-4"><Link to="/dashboard" className="inline-flex items-center gap-2 text-sm text-slate-600"><ArrowLeft size={17}/> Dashboard</Link><span className="text-sm font-semibold tracking-widest text-slate-800">LFMS / OPERATIONS</span></div></header>
    <section className="mx-auto max-w-7xl px-5 py-8">
      <div className="flex flex-wrap items-end justify-between gap-4"><div><p className="text-sm font-semibold text-teal-700">Facility care and safety</p><h1 className="mt-1 text-3xl font-semibold text-slate-950">Maintenance & incident reporting</h1><p className="mt-2 text-sm text-slate-600">Report issues, follow progress, and keep equipment status accurate.</p></div><button onClick={()=>void load()} className="inline-flex items-center gap-2 rounded-xl border bg-white px-4 py-2.5 text-sm font-semibold"><RefreshCw size={15}/> Refresh</button></div>
      {error&&<p role="alert" className="mt-5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">{error}</p>}
      {notice&&<p role="status" className="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{notice}</p>}
      <nav className="mt-7 flex gap-2"><button onClick={()=>{setTab("maintenance");setStatus("");setSelectedIncident(null);}} className={"rounded-xl px-4 py-2.5 text-sm font-semibold "+(tab==="maintenance"?"bg-slate-900 text-white":"border bg-white text-slate-600")}><Wrench className="mr-2 inline h-4 w-4"/>Maintenance</button><button onClick={()=>{setTab("incidents");setStatus("");setSelectedMaintenance(null);}} className={"rounded-xl px-4 py-2.5 text-sm font-semibold "+(tab==="incidents"?"bg-slate-900 text-white":"border bg-white text-slate-600")}><AlertTriangle className="mr-2 inline h-4 w-4"/>Incidents</button></nav>
      <div className="mt-5 grid gap-5 lg:grid-cols-[1.6fr_1fr]">
        <div className="rounded-2xl border bg-white p-5">
          <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="font-semibold text-slate-900">{tab==="maintenance"?"Maintenance requests":"Incident reports"}</h2><div className="flex flex-wrap gap-2"><input value={search} onChange={e=>{setSearch(e.target.value);setPage(p=>({...p,current_page:1}));}} placeholder="Search description" className="w-48 rounded-lg border px-3 py-2 text-sm"/><select value={status} onChange={e=>{setStatus(e.target.value);setPage(p=>({...p,current_page:1}));}} className="rounded-lg border bg-white px-3 py-2 text-sm"><option value="">All statuses</option>{(tab==="maintenance"?["pending","approved","in_progress","on_hold","completed","cancelled"]:["open","under_review","resolved","closed","dismissed"]).map(x=><option key={x} value={x}>{x.replaceAll("_"," ")}</option>)}</select></div></div>
          {loading?<p className="py-10 text-center text-sm text-slate-500">Loading records…</p>:activeList.length===0?<p className="py-10 text-center text-sm text-slate-500">No records found.</p>:<div className="mt-4 space-y-3">{tab==="maintenance"?maintenance.map(r=><article key={r.id} className="rounded-xl border p-4"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="font-semibold text-slate-900">{r.facilities?.name??r.equipment_assets?.inventory_items?.name??"Maintenance request"}</p><p className="mt-1 line-clamp-2 text-sm text-slate-600">{r.description}</p><p className="mt-2 text-xs text-slate-500">{r.profiles?.full_name??"Requester"} · {dateText(r.created_at)} · {r.priority} priority</p></div><span className={badge(r.status)}>{r.status.replaceAll("_"," ")}</span></div><button onClick={()=>void openMaintenance(r.id)} className="mt-3 rounded-lg border px-3 py-2 text-sm font-semibold">View details</button></article>):incidents.map(r=><article key={r.id} className="rounded-xl border p-4"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="font-semibold text-slate-900">{r.facilities?.name??r.equipment_assets?.inventory_items?.name??"Incident report"}</p><p className="mt-1 line-clamp-2 text-sm text-slate-600">{r.description}</p><p className="mt-2 text-xs text-slate-500">{r.profiles?.full_name??"Reporter"} · {dateText(r.created_at)} · {r.incident_type}</p></div><span className={badge(r.status)}>{r.status.replaceAll("_"," ")}</span></div><button onClick={()=>void openIncident(r.id)} className="mt-3 rounded-lg border px-3 py-2 text-sm font-semibold">Investigate / details</button></article>)}</div>}
          <div className="mt-4 flex items-center justify-between border-t pt-4 text-xs text-slate-500"><span>{page.total} total · Page {page.current_page} of {page.last_page}</span><div className="flex gap-2"><button disabled={page.current_page<=1} onClick={()=>setPage(p=>({...p,current_page:p.current_page-1}))} className="rounded-lg border px-3 py-2 disabled:opacity-40">Previous</button><button disabled={page.current_page>=page.last_page} onClick={()=>setPage(p=>({...p,current_page:p.current_page+1}))} className="rounded-lg border px-3 py-2 disabled:opacity-40">Next</button></div></div>
        </div>
        <form onSubmit={submit} className="h-fit rounded-2xl border bg-white p-5">
          <h2 className="font-semibold text-slate-900">{tab==="maintenance"?"Submit maintenance request":"Submit incident report"}</h2>
          <label className="mt-4 block text-xs text-slate-600">Affected target type<select value={targetType} onChange={e=>{setTargetType(e.target.value as TargetType);setTargetId("");}} className="mt-1 w-full rounded-lg border bg-white p-2.5 text-sm"><option value="facility">Facility / laboratory</option><option value="asset">Equipment asset</option></select></label>
          <label className="mt-3 block text-xs text-slate-600">Affected {targetType==="facility"?"facility":"equipment"}<select required value={targetId} onChange={e=>setTargetId(e.target.value)} className="mt-1 w-full rounded-lg border bg-white p-2.5 text-sm"><option value="">Select a target</option>{(targetType==="facility"?targets.facilities:targets.assets).map(x=><option key={x.id} value={x.id}>{targetType==="facility"?(x as Targets["facilities"][number]).name:(x as Targets["assets"][number]).asset_tag+" · "+((x as Targets["assets"][number]).inventory_items?.name??"Equipment")}</option>)}</select></label>
          {tab==="maintenance"?<label className="mt-3 block text-xs text-slate-600">Priority<select value={priority} onChange={e=>setPriority(e.target.value)} className="mt-1 w-full rounded-lg border bg-white p-2.5 text-sm">{["low","medium","high","critical"].map(x=><option key={x}>{x}</option>)}</select></label>:<label className="mt-3 block text-xs text-slate-600">Incident category<select value={incidentType} onChange={e=>setIncidentType(e.target.value)} className="mt-1 w-full rounded-lg border bg-white p-2.5 text-sm">{["damage","missing","safety","facility","other"].map(x=><option key={x}>{x}</option>)}</select></label>}
          <label className="mt-3 block text-xs text-slate-600">Description<textarea required minLength={5} maxLength={5000} rows={5} value={description} onChange={e=>setDescription(e.target.value)} className="mt-1 w-full rounded-lg border p-2.5 text-sm" placeholder="Describe the issue, when it occurred, and relevant details."/></label>
          <label className="mt-3 block text-xs text-slate-600">Evidence (JPG, PNG, WEBP, PDF, TXT · max 10 MB each)<input type="file" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,.txt,image/jpeg,image/png,image/webp,application/pdf,text/plain" onChange={e=>setFiles(Array.from(e.target.files??[]))} className="mt-1 block w-full text-xs"/></label>
          {files.length>0&&<p className="mt-2 text-xs text-slate-500">{files.length} file(s) selected</p>}
          <button disabled={busy||!targetId||description.trim().length<5} className="mt-4 w-full rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50">{busy?"Submitting…":"Submit report"}</button>
        </form>
      </div>

      {selectedMaintenance&&<section className="mt-6 rounded-2xl border bg-white p-5">
        <div className="flex flex-wrap justify-between gap-3"><div><h2 className="text-lg font-semibold">Maintenance details</h2><p className="mt-1 text-xs text-slate-500">{selectedMaintenance.id} · {dateText(selectedMaintenance.created_at)}</p></div><button onClick={()=>setSelectedMaintenance(null)} className="text-sm text-slate-500">Close</button></div>
        <p className="mt-3 text-sm text-slate-700">{selectedMaintenance.description}</p><p className="mt-2 text-xs text-slate-500">Target: {selectedMaintenance.facilities?.name??selectedMaintenance.equipment_assets?.asset_tag??"—"} · Status: {selectedMaintenance.status}</p>
        {maintenanceAdmin&&<div className="mt-4 flex flex-wrap gap-2">{(selectedMaintenance.status==="pending"?["approved","cancelled"]:selectedMaintenance.status==="approved"?["in_progress","on_hold","cancelled"]:selectedMaintenance.status==="in_progress"?["on_hold","completed"]:selectedMaintenance.status==="on_hold"?["in_progress","completed","cancelled"]:[]).map(s=><button key={s} disabled={busy} onClick={()=>void saveMaintenanceStatus(s)} className="rounded-lg border px-3 py-2 text-sm font-semibold">{s.replaceAll("_"," ")}</button>)}</div>}
        <div className="mt-5 border-t pt-4"><h3 className="font-semibold">Maintenance activity history</h3>{selectedMaintenance.maintenance_records?.map(r=><div key={r.id} className="mt-2 rounded-lg bg-slate-50 p-3 text-sm"><p className="font-medium">{r.profiles?.full_name??r.assigned_to??"Unassigned"} · {dateText(r.started_at)} – {dateText(r.completed_at)}</p><p className="mt-1 text-slate-600">{r.remarks||"No remarks"} · Cost: {r.cost}</p></div>)}{!selectedMaintenance.maintenance_records?.length&&<p className="mt-2 text-sm text-slate-500">No maintenance activity recorded yet.</p>}</div>
        <div className="mt-5 border-t pt-4"><h3 className="font-semibold">Supporting evidence</h3>{maintenanceEvidence.map(x=><button key={x.path} onClick={()=>void openEvidence(x.path)} className="mt-2 mr-2 inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm"><FileText size={15}/>{x.name}</button>)}{!maintenanceEvidence.length&&<p className="mt-2 text-sm text-slate-500">No evidence uploaded.</p>}</div>
        {maintenanceAdmin&&<form onSubmit={saveMaintenanceRecord} className="mt-5 grid gap-3 border-t pt-4 sm:grid-cols-2"><h3 className="font-semibold sm:col-span-2">Record maintenance action / assignment</h3><label className="text-xs text-slate-600">Assigned profile UUID (optional)<input value={assignedTo} onChange={e=>setAssignedTo(e.target.value)} className="mt-1 w-full rounded-lg border p-2.5 text-sm" placeholder="Profile UUID"/></label><label className="text-xs text-slate-600">Cost<input type="number" min="0" step="0.01" value={recordCost} onChange={e=>setRecordCost(e.target.value)} className="mt-1 w-full rounded-lg border p-2.5 text-sm"/></label><label className="text-xs text-slate-600 sm:col-span-2">Inspection findings / actions / remarks<textarea rows={3} value={recordRemarks} onChange={e=>setRecordRemarks(e.target.value)} className="mt-1 w-full rounded-lg border p-2.5 text-sm"/></label><button disabled={busy} className="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white sm:col-span-2">Save maintenance record</button></form>}
      </section>}

      {selectedIncident&&<section className="mt-6 rounded-2xl border bg-white p-5">
        <div className="flex flex-wrap justify-between gap-3"><div><h2 className="text-lg font-semibold">Incident investigation</h2><p className="mt-1 text-xs text-slate-500">{selectedIncident.id} · Reported {dateText(selectedIncident.created_at)}</p></div><button onClick={()=>setSelectedIncident(null)} className="text-sm text-slate-500">Close</button></div>
        <p className="mt-3 text-sm text-slate-700">{selectedIncident.description}</p><p className="mt-2 text-xs text-slate-500">Category: {selectedIncident.incident_type} · Target: {selectedIncident.facilities?.name??selectedIncident.equipment_assets?.asset_tag??"—"} · Status: {selectedIncident.status}</p>
        {selectedIncident.asset_id&&<p className="mt-2 text-xs text-slate-500">Related reports for this equipment in current list: {incidents.filter(x=>x.asset_id===selectedIncident.asset_id).length}</p>}
        <div className="mt-4"><h3 className="font-semibold">Evidence</h3>{selectedIncident.incident_attachments?.map(x=><button key={x.id} onClick={()=>void openEvidence(x.file_path)} className="mt-2 mr-2 inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm"><FileText size={15}/>{x.file_name}</button>)}{!selectedIncident.incident_attachments?.length&&<p className="mt-2 text-sm text-slate-500">No evidence uploaded.</p>}</div>
        {can("incidents.manage")&&<div className="mt-5 border-t pt-4"><label className="block text-xs text-slate-600">Investigation findings, corrective action, and resolution<textarea rows={4} value={resolution} onChange={e=>setResolution(e.target.value)} className="mt-1 w-full rounded-lg border p-2.5 text-sm"/></label><div className="mt-3 flex flex-wrap gap-2">{(selectedIncident.status==="open"?["under_review","dismissed"]:selectedIncident.status==="under_review"?["open","resolved","dismissed"]:selectedIncident.status==="resolved"?["open","closed"]:[]).map(s=><button key={s} disabled={busy} onClick={()=>void saveIncident(s)} className="rounded-lg border px-3 py-2 text-sm font-semibold">{s.replaceAll("_"," ")}</button>)}</div>{can("inventory.manage")&&selectedIncident.asset_id&&(selectedIncident.incident_type==="damage"||selectedIncident.incident_type==="missing")&&<p className="mt-3 text-xs text-slate-500">When you save the investigation status, this asset is also marked {selectedIncident.incident_type==="damage"?"damaged":"missing"} so it cannot be borrowed.</p>}</div>}
      </section>}
    </section>
  </main>;
}
