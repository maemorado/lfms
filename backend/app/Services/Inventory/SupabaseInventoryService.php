<?php

namespace App\Services\Inventory;

use App\Exceptions\InventoryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class SupabaseInventoryService
{
    private function client(string $token)
    {
        $url = rtrim((string) config('supabase.url'), '/');
        $key = (string) config('supabase.publishable_key');
        if ($url === '' || $key === '' || $token === '') throw new InventoryException('Inventory service is not configured for the authenticated session.', 503);
        return Http::acceptJson()->withHeaders(['apikey'=>$key,'Authorization'=>'Bearer '.$token])->timeout((int)config('supabase.auth_user_timeout',10));
    }

    private function request(string $token,string $method,string $path,array $query=[],?array $body=null,string $prefer='return=representation'): array
    {
        $url=rtrim((string)config('supabase.url'),'/').'/rest/v1/'.$path;
        $client=$this->client($token)->withHeaders(['Prefer'=>$prefer]);
        $response=match(strtoupper($method)){
            'GET'=>$client->get($url,$query),
            'POST'=>$client->post($url,$body??[]),
            'PATCH'=>$client->patch($url.(($query!==[])?'?'.http_build_query($query):''),$body??[]),
            'DELETE'=>$client->delete($url.(($query!==[])?'?'.http_build_query($query):'')),
            default=>throw new InventoryException('Unsupported inventory operation.',405),
        };
        if(!$response->successful()){
            $status=$response->status();
            $message=match($status){401=>'Your session is no longer valid. Sign in again.',403=>'Your account does not have permission to access this inventory data.',404=>'The requested inventory record was not found.',409=>'The inventory change conflicts with existing records.',422=>'The inventory data violates an existing database rule.',default=>'The inventory service could not complete the request.'};
            throw new InventoryException($message,in_array($status,[401,403,404,409,422],true)?$status:($status===400?422:502));
        }
        return ['data'=>$response->json()??[],'content_range'=>$response->header('Content-Range')];
    }

    private function uuid(string $id): void { if(!Str::isUuid($id)) throw new InventoryException('The supplied inventory identifier is invalid.',422); }
    private function countFromRange(?string $range): int {
        if(!is_string($range)||!str_contains($range,'/')) return 0;
        $raw=substr($range,strrpos($range,'/')+1);
        return ctype_digit($raw)?(int)$raw:0;
    }

    public function listItems(string $token,array $o): array
    {
        $select='*,item_categories(id,name),inventory_stock(id,quantity,location_id,inventory_locations(id,name,facility_id,facilities(id,name,code))),equipment_assets(id,asset_tag,serial_number,condition,status,location_id)';
        $q=['select'=>$select,'status'=>'eq.'.($o['status']??'active'),'order'=>($o['sort']??'name').'.'.($o['direction']??'asc'),'limit'=>(string)($o['per_page']??20),'offset'=>(string)(((int)($o['page']??1)-1)*(int)($o['per_page']??20))];
        if(!empty($o['item_type']))$q['item_type']='eq.'.$o['item_type'];
        if(!empty($o['category_id']))$q['category_id']='eq.'.$o['category_id'];
        if(!empty($o['search'])){$s=str_replace(['%','_',','],['\\%','\\_', '\\,'],$o['search']);$q['or']='(name.ilike.*'.$s.'*,item_code.ilike.*'.$s.'*)';}
        $result=$this->request($token,'GET','inventory_items',$q,null,'count=exact');
        $rows=array_map(fn($r)=>$this->normalizeItem($r),is_array($result['data'])?$result['data']:[]);
        
        $total=$this->countFromRange($result['content_range']??null);
        return ['items'=>$rows,'pagination'=>['current_page'=>(int)($o['page']??1),'per_page'=>(int)($o['per_page']??20),'total'=>$total,'last_page'=>max(1,(int)ceil($total/max(1,(int)($o['per_page']??20))))]];
    }

    private function normalizeItem(array $r): array
    {
        $stock=is_array($r['inventory_stock']??null)?$r['inventory_stock']:[];
        $assets=is_array($r['equipment_assets']??null)?$r['equipment_assets']:[];
        $quantity=array_sum(array_map(fn($x)=>(float)($x['quantity']??0),$stock));
        $available=count(array_filter($assets,fn($x)=>($x['status']??null)==='available'));
        $minimum=(float)($r['minimum_stock']??0);
        $r['quantity_on_hand']=$quantity;$r['available_assets']=$available;
        $r['availability']=($r['item_type']??'')==='equipment'?($available>0?'available':'unavailable'):($quantity<=0?'out_of_stock':($quantity<=$minimum?'low_stock':'in_stock'));
        return $r;
    }

    public function findItem(string $token,string $id): array
    {
        $this->uuid($id);
        $r=$this->request($token,'GET','inventory_items',['select'=>'*,item_categories(id,name),inventory_stock(id,quantity,location_id,inventory_locations(id,name,facility_id,facilities(id,name,code))),equipment_assets(id,asset_tag,serial_number,condition,status,location_id)','id'=>'eq.'.$id,'limit'=>'1']);
        $item=$r['data'][0]??null;
        if(!is_array($item))throw new InventoryException('The inventory item was not found.',404);
        return $this->normalizeItem($item);
    }

    public function createItem(string $token,array $data): array
    {
        $r=$this->request($token,'POST','inventory_items',[],$data);
        $item=$r['data'][0]??null;
        if(!is_array($item))throw new InventoryException('The inventory item was not returned after creation.',502);
        return $this->findItem($token,$item['id']);
    }
    public function updateItem(string $token,string $id,array $data): array
    {
        $this->uuid($id);$this->request($token,'PATCH','inventory_items',['id'=>'eq.'.$id],$data);return $this->findItem($token,$id);
    }
    public function archiveItem(string $token,string $id): array
    {
        $item=$this->findItem($token,$id);
        if($item['status']==='inactive')return $item;
        if((float)$item['quantity_on_hand']>0)throw new InventoryException('This item cannot be archived while stock remains on hand.',409);
        foreach($item['equipment_assets']??[] as $asset) if(!in_array($asset['status']??'', ['retired','missing'],true))throw new InventoryException('Retire or mark every equipment asset missing before archiving this item.',409);
        return $this->updateItem($token,$id,['status'=>'inactive']);
    }

    public function categories(string $token): array
    {
        return $this->request($token,'GET','item_categories',['select'=>'id,name,description,created_at','order'=>'name.asc'])['data'];
    }
    public function createCategory(string $token,array $data): array
    {
        return $this->request($token,'POST','item_categories',[],$data)['data'][0]??[];
    }
    public function updateCategory(string $token,string $id,array $data): array
    {
        $this->uuid($id);return $this->request($token,'PATCH','item_categories',['id'=>'eq.'.$id],$data)['data'][0]??throw new InventoryException('The category was not found.',404);
    }
    public function deleteCategory(string $token,string $id): void
    {
        $this->uuid($id);
        if($this->request($token,'GET','inventory_items',['select'=>'id','category_id'=>'eq.'.$id,'limit'=>'1'])['data']!==[])throw new InventoryException('This category is still assigned to inventory items and cannot be deleted.',409);
        $this->request($token,'DELETE','item_categories',['id'=>'eq.'.$id],null,'return=minimal');
    }
    public function locations(string $token): array
    {
        return $this->request($token,'GET','inventory_locations',['select'=>'id,name,description,facility_id,facilities(id,name,code)','order'=>'name.asc'])['data'];
    }
    public function assets(string $token,string $itemId): array
    {
        $this->uuid($itemId);
        return $this->request($token,'GET','equipment_assets',['select'=>'id,item_id,asset_tag,serial_number,condition,status,location_id,created_at,updated_at,inventory_locations(id,name,facility_id,facilities(id,name,code))','item_id'=>'eq.'.$itemId,'order'=>'asset_tag.asc'])['data'];
    }
    public function createAsset(string $token,string $itemId,array $data): array
    {
        if($this->findItem($token,$itemId)['item_type']!=='equipment')throw new InventoryException('Individual assets can only be added to equipment items.',422);
        return $this->request($token,'POST','equipment_assets',[],['item_id'=>$itemId]+$data)['data'][0]??[];
    }
    public function updateAsset(string $token,string $itemId,string $assetId,array $data): array
    {
        $this->uuid($itemId);$this->uuid($assetId);
        return $this->request($token,'PATCH','equipment_assets',['id'=>'eq.'.$assetId,'item_id'=>'eq.'.$itemId],$data)['data'][0]??throw new InventoryException('The equipment asset was not found.',404);
    }
    public function moveStock(string $token,string $itemId,array $data): array
    {
        $this->uuid($itemId);
        if($this->findItem($token,$itemId)['item_type']!=='consumable')throw new InventoryException('Stock movements are for consumable items. Track equipment using individual asset records.',422);
        $url=rtrim((string)config('supabase.url'),'/').'/rest/v1/rpc/apply_inventory_stock_movement';
        $response=$this->client($token)->post($url,['p_item_id'=>$itemId,'p_location_id'=>$data['location_id'],'p_movement_type'=>$data['movement_type'],'p_quantity'=>$data['quantity'],'p_remarks'=>$data['remarks']??null,'p_reference_type'=>$data['reference_type']??null,'p_reference_id'=>$data['reference_id']??null]);
        if(!$response->successful()){
            $body=strtolower($response->body());$status=$response->status();
            $message=(str_contains($body,'insufficient')||str_contains($body,'negative'))?'There is not enough stock at this location for that withdrawal.':($status===403?'Your account cannot manage inventory stock.':'The stock movement could not be completed.');
            throw new InventoryException($message,in_array($status,[401,403,404,409,422],true)?$status:($status===400?422:502));
        }
        return ['movement'=>$response->json()[0]??$response->json(),'item'=>$this->findItem($token,$itemId)];
    }
    public function movements(string $token,string $itemId,int $page=1,int $perPage=20): array
    {
        $this->uuid($itemId);
        $r=$this->request($token,'GET','inventory_movements',['select'=>'*,inventory_items!inventory_movements_item_id_fkey(id,name,item_code,unit),inventory_locations!inventory_movements_location_id_fkey(id,name),profiles!inventory_movements_performed_by_fkey(id,full_name,email)','item_id'=>'eq.'.$itemId,'order'=>'created_at.desc','limit'=>(string)$perPage,'offset'=>(string)(($page-1)*$perPage)],null,'count=exact');
        $total=$this->countFromRange($r['content_range']??null);
        return ['movements'=>$r['data'],'pagination'=>['current_page'=>$page,'per_page'=>$perPage,'total'=>$total,'last_page'=>max(1,(int)ceil($total/$perPage))]];
    }
}