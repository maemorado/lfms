import { api } from "@/lib/api";
export type PageMeta={current_page:number;per_page:number;total:number;last_page:number};
type Envelope<T>={success:boolean;message:string;data:T;meta?:{pagination?:PageMeta}};
export type BorrowableItem={id:string;name:string;item_code:string;item_type:"equipment"|"consumable";unit:string;quantity_on_hand:number;available_assets:number;availability:string;inventory_stock:{quantity:number;location_id:string;inventory_locations?:{name:string}}[];equipment_assets?:{id:string;asset_tag:string;status:string;location_id:string|null}[]};
export type BorrowRequestItem={id:string;item_id:string;quantity:number;remarks?:string|null;inventory_items:BorrowableItem};
export type BorrowRequest={id:string;borrower_id:string;status:string;requested_at:string;expected_return_at:string;remarks:string|null;review_remarks?:string|null;profiles?:{id:string;full_name:string;email:string;employee_id:string|null};borrow_request_items:BorrowRequestItem[]};
export type BorrowTransactionItem={id:string;item_id:string;asset_id:string|null;quantity:number;location_id:string|null;inventory_items:{id:string;name:string;item_code:string;item_type:string;unit:string};equipment_assets?:{id:string;asset_tag:string;condition:string;status:string}|null};
export type BorrowingRecord={id:string;borrow_request_id:string;released_by:string;released_at:string;remarks:string|null;borrow_requests:BorrowRequest;borrow_transaction_items:BorrowTransactionItem[]};
export type ReturnRecord={id:string;transaction_id:string;received_by:string;returned_at:string;remarks:string|null;profiles?:{full_name:string;email:string};return_transaction_items:{id:string;item_id:string;asset_id:string|null;quantity:number;condition:string;remarks:string|null;inventory_items?:{name:string;item_code:string;unit:string}}[]};
async function get<T>(url:string,params?:Record<string,unknown>){return (await api.get<Envelope<T>>(url,{params})).data;}
async function post<T>(url:string,body:unknown){return (await api.post<Envelope<T>>(url,body)).data;}
export async function listBorrowable(params:Record<string,unknown>={}){return (await get<{items:BorrowableItem[]}>("/v1/borrowing/inventory",params)).data.items;}
export async function listBorrowRequests(params:Record<string,unknown>={}){const r=await get<{requests:BorrowRequest[]}>("/v1/borrowing/requests",params);return {requests:r.data.requests,pagination:r.meta?.pagination};}
export async function getBorrowRequest(id:string){return (await get<{request:BorrowRequest}>("/v1/borrowing/requests/"+id)).data.request;}
export async function createBorrowRequest(input:unknown){return (await post<{request:{id:string;status:string}}>("/v1/borrowing/requests",input)).data.request;}
export async function cancelBorrowRequest(id:string){return (await post<{request:unknown}>("/v1/borrowing/requests/"+id+"/cancel",{})).data.request;}
export async function reviewBorrowRequest(id:string,input:{decision:"approved"|"rejected";remarks?:string}){return (await post<{request:unknown}>("/v1/borrowing/requests/"+id+"/review",input)).data.request;}
export async function releaseBorrowRequest(id:string,input:unknown){return (await post<{borrowing:unknown}>("/v1/borrowing/requests/"+id+"/release",input)).data.borrowing;}
export async function listBorrowings(params:Record<string,unknown>={}){const r=await get<{borrowings:BorrowingRecord[]}>("/v1/borrowing/records",params);return {borrowings:r.data.borrowings,pagination:r.meta?.pagination};}
export async function listOverdueBorrowings(params:Record<string,unknown>={}){const r=await get<{borrowings:BorrowingRecord[]}>("/v1/borrowing/overdue",params);return {borrowings:r.data.borrowings,pagination:r.meta?.pagination};}
export async function getBorrowing(id:string){return (await get<{borrowing:BorrowingRecord}>("/v1/borrowing/records/"+id)).data.borrowing;}
export async function listReturns(id:string){return (await get<{returns:ReturnRecord[]}>("/v1/borrowing/records/"+id+"/returns")).data.returns;}
export async function processBorrowReturn(id:string,input:unknown){return (await post<{return:unknown}>("/v1/borrowing/records/"+id+"/returns",input)).data.return;}
export function borrowingError(error:unknown){const e=error as {response?:{data?:{message?:string;errors?:Record<string,string[]>}};message?:string};return e.response?.data?.errors?Object.values(e.response.data.errors).flat()[0]??"Check the submitted fields.":e.response?.data?.message??e.message??"Borrowing request failed.";}
