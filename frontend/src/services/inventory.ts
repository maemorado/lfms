import { api } from "@/lib/api";
export type InventoryCategory={id:string;name:string;description:string|null};
export type InventoryLocation={id:string;name:string;facility_id:string|null;facilities?:{id:string;name:string;code:string}|null};
export type EquipmentAsset={id:string;asset_tag:string;serial_number:string|null;condition:string;status:string;location_id:string|null;inventory_locations?:InventoryLocation|null};
export type InventoryItem={id:string;category_id:string|null;item_categories?:InventoryCategory|null;name:string;item_code:string;item_type:"equipment"|"consumable";unit:string;description:string|null;minimum_stock:number;status:"active"|"inactive"|"discontinued";quantity_on_hand:number;available_assets:number;availability:string;inventory_stock:{id:string;quantity:number;location_id:string;inventory_locations?:InventoryLocation}[];equipment_assets:EquipmentAsset[];created_at:string;updated_at:string};
export type InventoryMovement={id:string;item_id:string;inventory_items?:{name:string;item_code:string;unit:string};location_id:string;inventory_locations?:{name:string};movement_type:string;quantity:number;performed_by:string;profiles?:{full_name:string;email:string};remarks:string|null;created_at:string};
export type PageMeta={current_page:number;per_page:number;total:number;last_page:number};
type Envelope<T>={success:boolean;message:string;data:T;meta?:{pagination?:PageMeta}};
async function get<T>(url:string,params?:Record<string,unknown>){return (await api.get<Envelope<T>>(url,{params})).data;}
async function post<T>(url:string,body:unknown){return (await api.post<Envelope<T>>(url,body)).data;}
async function patch<T>(url:string,body:unknown){return (await api.patch<Envelope<T>>(url,body)).data;}
export async function listInventory(params:Record<string,unknown>){const r=await get<{items:InventoryItem[]}>("/v1/inventory",params);return {items:r.data.items,pagination:r.meta?.pagination};}
export async function getInventoryItem(id:string){return (await get<{item:InventoryItem}>(`/v1/inventory/${id}`)).data.item;}
export async function createInventoryItem(input:Record<string,unknown>){return (await post<{item:InventoryItem}>("/v1/inventory",input)).data.item;}
export async function updateInventoryItem(id:string,input:Record<string,unknown>){return (await patch<{item:InventoryItem}>(`/v1/inventory/${id}`,input)).data.item;}
export async function archiveInventoryItem(id:string){return (await post<{item:InventoryItem}>(`/v1/inventory/${id}/archive`,{})).data.item;}
export async function listCategories(){return (await get<{categories:InventoryCategory[]}>("/v1/inventory/categories")).data.categories;}
export async function createCategory(input:Record<string,unknown>){return (await post<{category:InventoryCategory}>("/v1/inventory/categories",input)).data.category;}
export async function updateCategory(id:string,input:Record<string,unknown>){return (await patch<{category:InventoryCategory}>(`/v1/inventory/categories/${id}`,input)).data.category;}
export async function deleteCategory(id:string){await api.delete(`/v1/inventory/categories/${id}`);}
export async function listLocations(){return (await get<{locations:InventoryLocation[]}>("/v1/inventory/locations")).data.locations;}
export async function listAssets(id:string){return (await get<{assets:EquipmentAsset[]}>(`/v1/inventory/${id}/assets`)).data.assets;}
export async function createAsset(id:string,input:Record<string,unknown>){return (await post<{asset:EquipmentAsset}>(`/v1/inventory/${id}/assets`,input)).data.asset;}
export async function updateAsset(id:string,assetId:string,input:Record<string,unknown>){return (await patch<{asset:EquipmentAsset}>(`/v1/inventory/${id}/assets/${assetId}`,input)).data.asset;}
export async function recordMovement(id:string,input:Record<string,unknown>){return (await post<{movement:unknown;item:InventoryItem}>(`/v1/inventory/${id}/movements`,input)).data;}
export async function listMovements(id:string,page=1){const r=await get<{movements:InventoryMovement[]}>(`/v1/inventory/${id}/movements`,{page,per_page:20});return {movements:r.data.movements,pagination:r.meta?.pagination};}
export function inventoryError(error:unknown){const e=error as {response?:{data?:{message?:string;errors?:Record<string,string[]>}};message?:string};return e.response?.data?.errors?Object.values(e.response.data.errors).flat()[0]??"Check the submitted fields.":e.response?.data?.message??e.message??"Inventory request failed.";}
