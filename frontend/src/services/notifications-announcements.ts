import {api} from "@/lib/api";
export type PageMeta={current_page:number;per_page:number;total:number;last_page:number};
type Envelope<T>={success:boolean;message:string;data:T;meta?:{pagination?:PageMeta}};
export type LfmsNotification={id:string;user_id:string;title:string;message:string;type:string;read_at:string|null;created_at:string;event_key:string|null;related_record_type:string|null;related_record_id:string|null;metadata:Record<string,unknown>};
export type Announcement={id:string;title:string;content:string;created_by:string;published_at:string|null;status:"draft"|"published"|"archived";category:string;expires_at:string|null;created_at:string;updated_at:string};
export type AnnouncementAudit={id:string;user_id:string|null;table_name:string;record_id:string;action:string;old_values:Record<string,unknown>|null;new_values:Record<string,unknown>|null;created_at:string};
const get=async<T>(url:string,params?:Record<string,unknown>)=>(await api.get<Envelope<T>>(url,{params})).data;
const post=async<T>(url:string,body:unknown={})=>(await api.post<Envelope<T>>(url,body)).data;
const patch=async<T>(url:string,body:unknown)=>(await api.patch<Envelope<T>>(url,body)).data;
export async function listNotifications(params:Record<string,unknown>={}){const r=await get<{notifications:LfmsNotification[]}>("/v1/notifications",params);return {notifications:r.data.notifications,pagination:r.meta?.pagination};}
export async function getUnreadNotificationCount(){return (await get<{unread_count:number}>("/v1/notifications/unread-count")).data.unread_count;}
export async function markNotificationRead(id:string){return (await patch<{notification:LfmsNotification}>(`/v1/notifications/${id}/read`,{})).data.notification;}
export async function markAllNotificationsRead(){return (await post<{updated:number}>("/v1/notifications/read-all")).data.updated;}
export async function dispatchOverdueNotifications(){return (await post<{created:number}>("/v1/notifications/dispatch-overdue")).data.created;}
export async function listAnnouncements(params:Record<string,unknown>={}){const r=await get<{announcements:Announcement[]}>("/v1/announcements",params);return {announcements:r.data.announcements,pagination:r.meta?.pagination};}
export async function getAnnouncement(id:string){return (await get<{announcement:Announcement}>(`/v1/announcements/${id}`)).data.announcement;}
export async function createAnnouncement(input:{title:string;content:string;category:string;expires_at:string|null}){return (await post<{announcement:Announcement}>("/v1/announcements",input)).data.announcement;}
export async function updateAnnouncement(id:string,input:{title:string;content:string;category:string;expires_at:string|null}){return (await patch<{announcement:Announcement}>(`/v1/announcements/${id}`,input)).data.announcement;}
export async function setAnnouncementState(id:string,state:"publish"|"unpublish"|"archive"){return (await post<{announcement:Announcement}>(`/v1/announcements/${id}/${state}`)).data.announcement;}
export async function getAnnouncementHistory(id:string){return (await get<{history:AnnouncementAudit[]}>(`/v1/announcements/${id}/history`)).data.history;}
export function notificationDestination(item:LfmsNotification):string|null{const id=item.related_record_id;if(!id||!item.related_record_type)return null;switch(item.related_record_type){case "announcement":return `/announcements?id=${encodeURIComponent(id)}`;case "borrowing":return `/borrowing?requestId=${encodeURIComponent(id)}`;case "maintenance":return `/maintenance-incidents?maintenanceId=${encodeURIComponent(id)}`;case "incident":return `/maintenance-incidents?incidentId=${encodeURIComponent(id)}`;case "reservation":return `/dashboard?reservationId=${encodeURIComponent(id)}`;default:return null;}}
export function notificationError(error:unknown){const e=error as {response?:{data?:{message?:string;errors?:Record<string,string[]>}};message?:string};return e.response?.data?.errors?Object.values(e.response.data.errors).flat()[0]??"Check the submitted fields.":e.response?.data?.message??e.message??"The request could not be completed.";}
