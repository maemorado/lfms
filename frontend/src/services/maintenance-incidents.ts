import { api } from "@/lib/api";
import { supabase } from "@/lib/supabase";

export type PageMeta={current_page:number;per_page:number;total:number;last_page:number};
type Envelope<T>={success:boolean;message:string;data:T;meta?:{pagination?:PageMeta}};
export type TargetFacility={id:string;name:string;code:string};
export type TargetAsset={id:string;asset_tag:string;condition:string;status:string;location_id:string|null;inventory_items?:{id:string;name:string;item_code:string}|null};
export type MaintenanceRecord={id:string;assigned_to:string|null;started_at:string|null;completed_at:string|null;cost:number;remarks:string|null;profiles?:{id:string;full_name:string;email:string}|null};
export type MaintenanceRequest={id:string;facility_id:string|null;asset_id:string|null;reported_by:string;description:string;priority:string;status:string;created_at:string;updated_at:string;facilities?:TargetFacility|null;equipment_assets?:TargetAsset|null;profiles?:{id:string;full_name:string;email:string}|null;maintenance_records:MaintenanceRecord[]};
export type IncidentAttachment={id:string;file_path:string;file_name:string;uploaded_by:string;uploaded_at:string};
export type IncidentReport={id:string;facility_id:string|null;asset_id:string|null;reported_by:string;incident_type:string;description:string;status:string;resolution:string|null;resolved_by:string|null;resolved_at:string|null;created_at:string;updated_at:string;facilities?:TargetFacility|null;equipment_assets?:TargetAsset|null;profiles?:{id:string;full_name:string;email:string}|null;incident_attachments:IncidentAttachment[]};
export type Targets={facilities:TargetFacility[];assets:TargetAsset[]};

async function get<T>(url:string,params?:Record<string,unknown>){return (await api.get<Envelope<T>>(url,{params})).data;}
async function post<T>(url:string,body:unknown){return (await api.post<Envelope<T>>(url,body)).data;}
async function patch<T>(url:string,body:unknown){return (await api.patch<Envelope<T>>(url,body)).data;}

export async function getMaintenanceTargets(){return (await get<Targets>("/v1/maintenance/targets")).data;}
export async function listMaintenance(params:Record<string,unknown>={}){const r=await get<{requests:MaintenanceRequest[]}>("/v1/maintenance/requests",params);return {requests:r.data.requests,pagination:r.meta?.pagination};}
export async function getMaintenance(id:string){return (await get<{request:MaintenanceRequest}>(`/v1/maintenance/requests/${id}`)).data.request;}
export async function createMaintenance(input:{facility_id?:string|null;asset_id?:string|null;description:string;priority:string}){return (await post<{request:MaintenanceRequest}>("/v1/maintenance/requests",input)).data.request;}
export async function updateMaintenance(id:string,status:string){return (await patch<{request:MaintenanceRequest}>(`/v1/maintenance/requests/${id}`,{status})).data.request;}
export async function addMaintenanceRecord(id:string,input:{assigned_to?:string|null;started_at?:string|null;completed_at?:string|null;cost?:number;remarks?:string|null}){return (await post<{record:MaintenanceRecord}>(`/v1/maintenance/requests/${id}/records`,input)).data.record;}
export async function listIncidents(params:Record<string,unknown>={}){const r=await get<{incidents:IncidentReport[]}>("/v1/incidents",params);return {incidents:r.data.incidents,pagination:r.meta?.pagination};}
export async function getIncident(id:string){return (await get<{incident:IncidentReport}>(`/v1/incidents/${id}`)).data.incident;}
export async function createIncident(input:{facility_id?:string|null;asset_id?:string|null;incident_type:string;description:string}){return (await post<{incident:IncidentReport}>("/v1/incidents",input)).data.incident;}
export async function updateIncident(id:string,input:{status?:string;resolution?:string|null}){return (await patch<{incident:IncidentReport}>(`/v1/incidents/${id}`,input)).data.incident;}
export async function linkIncidentAttachment(id:string,file_path:string,file_name:string){return (await post<{attachment:IncidentAttachment}>(`/v1/incidents/${id}/attachments`,{file_path,file_name})).data.attachment;}

const allowedMime=new Set(["image/jpeg","image/png","image/webp","application/pdf","text/plain"]);
export async function uploadEvidence(kind:"maintenance"|"incidents",recordId:string,file:File){
  if(!supabase)throw new Error("Supabase Storage is not configured.");
  if(!allowedMime.has(file.type))throw new Error("Only JPG, PNG, WEBP, PDF, and TXT files are supported.");
  if(file.size>10*1024*1024)throw new Error("Evidence files must be 10 MB or smaller.");
  const safeName=file.name.normalize("NFKD").replace(/[^a-zA-Z0-9._-]+/g,"_").slice(-100)||"evidence";
  const path=`${kind}/${recordId}/${crypto.randomUUID()}-${safeName}`;
  const {error}=await supabase.storage.from("lfms-evidence").upload(path,file,{contentType:file.type,upsert:false});
  if(error)throw error;
  if(kind==="incidents")await linkIncidentAttachment(recordId,path,file.name);
  return path;
}
export async function listMaintenanceEvidence(id:string){
  if(!supabase)throw new Error("Supabase Storage is not configured.");
  const {data,error}=await supabase.storage.from("lfms-evidence").list(`maintenance/${id}`,{limit:100,sortBy:{column:"created_at",order:"desc"}});
  if(error)throw error;
  return (data??[]).map(x=>({name:x.name,path:`maintenance/${id}/${x.name}`}));
}
export async function createEvidenceUrl(path:string){
  if(!supabase)throw new Error("Supabase Storage is not configured.");
  const {data,error}=await supabase.storage.from("lfms-evidence").createSignedUrl(path,60);
  if(error)throw error;
  if(!data?.signedUrl)throw new Error("The evidence link could not be created.");
  return data.signedUrl;
}
export function maintenanceIncidentError(error:unknown){
  const e=error as {response?:{data?:{message?:string;errors?:Record<string,string[]>}};message?:string};
  return e.response?.data?.errors?Object.values(e.response.data.errors).flat()[0]??"Check the submitted fields.":e.response?.data?.message??e.message??"The request could not be completed.";
}
