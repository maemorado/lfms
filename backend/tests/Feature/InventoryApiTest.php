<?php
namespace Tests\Feature;
use App\Services\Inventory\SupabaseInventoryService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;
final class InventoryApiTest extends TestCase {
 private const ADMIN='11111111-1111-4111-8111-111111111111';
 private const STAFF='22222222-2222-4222-8222-222222222222';
 private const ROLE='33333333-3333-4333-8333-333333333333';
 protected function setUp():void{parent::setUp();config(['supabase.url'=>'https://example.supabase.co','supabase.publishable_key'=>'test-publishable-key','supabase.auth_user_timeout'=>10]);}
 private function fakeUser(string $id,string $role,array $permissions):void{
  Http::fake([
   'https://example.supabase.co/auth/v1/user'=>Http::response(['id'=>$id,'email'=>'test@example.test'],200),
   'https://example.supabase.co/rest/v1/profiles*'=>Http::response([['id'=>$id,'full_name'=>'Test','email'=>'test@example.test','status'=>'active']],200),
   'https://example.supabase.co/rest/v1/user_roles*'=>Http::response([['role_id'=>self::ROLE,'roles'=>['name'=>$role]]],200),
   'https://example.supabase.co/rest/v1/role_permissions*'=>Http::response(array_map(fn($p)=>['permissions'=>['name'=>$p]],$permissions),200),
  ]);
 }
 public function test_staff_can_read_inventory_but_cannot_create_items():void{
  $this->fakeUser(self::STAFF,'staff_sa',['inventory.view']);
  $service=Mockery::mock(SupabaseInventoryService::class);
  $service->shouldReceive('listItems')->once()->andReturn(['items'=>[],'pagination'=>['current_page'=>1,'per_page'=>20,'total'=>0,'last_page'=>1]]);
  $service->shouldNotReceive('createItem');
  $this->app->instance(SupabaseInventoryService::class,$service);
  $this->withToken('staff-token')->getJson('/api/v1/inventory')->assertOk()->assertJsonPath('data.items',[]);
  $this->withToken('staff-token')->postJson('/api/v1/inventory',['name'=>'Test','item_code'=>'TEST-1','item_type'=>'consumable','unit'=>'piece'])->assertForbidden();
 }
 public function test_admin_can_create_inventory_item_through_authenticated_service():void{
  $this->fakeUser(self::ADMIN,'admin',['inventory.view','inventory.manage']);
  $service=Mockery::mock(SupabaseInventoryService::class);
  $service->shouldReceive('createItem')->once()->with('admin-token',Mockery::on(fn($d)=>$d['item_code']==='PAPER-1'))->andReturn(['id'=>'44444444-4444-4444-8444-444444444444','name'=>'Paper','item_code'=>'PAPER-1','item_type'=>'consumable','minimum_stock'=>5,'status'=>'active','inventory_stock'=>[],'equipment_assets'=>[]]);
  $this->app->instance(SupabaseInventoryService::class,$service);
  $this->withToken('admin-token')->postJson('/api/v1/inventory',['name'=>'Paper','item_code'=>'PAPER-1','item_type'=>'consumable','unit'=>'ream','minimum_stock'=>5])->assertCreated()->assertJsonPath('data.item.item_code','PAPER-1');
 }
 public function test_stock_movement_uses_authenticated_rpc_and_never_direct_stock_update():void{
  config(['supabase.url'=>'https://example.supabase.co','supabase.publishable_key'=>'test-publishable-key']);
  Http::fakeSequence()
   ->push([['id'=>'44444444-4444-4444-8444-444444444444','name'=>'Paper','item_code'=>'PAPER-1','item_type'=>'consumable','status'=>'active','minimum_stock'=>0,'inventory_stock'=>[],'equipment_assets'=>[]]],200)
   ->push([['movement_id'=>'55555555-5555-4555-8555-555555555555','stock_id'=>'66666666-6666-4666-8666-666666666666','resulting_quantity'=>12]],200)
   ->push([['id'=>'44444444-4444-4444-8444-444444444444','name'=>'Paper','item_code'=>'PAPER-1','item_type'=>'consumable','status'=>'active','minimum_stock'=>0,'inventory_stock'=>[['quantity'=>12]],'equipment_assets'=>[]]],200);
  $service=app(SupabaseInventoryService::class);
  $result=$service->moveStock('caller-jwt','44444444-4444-4444-8444-444444444444',['location_id'=>'77777777-7777-4777-8777-777777777777','movement_type'=>'stock_in','quantity'=>12]);
  $this->assertSame('55555555-5555-4555-8555-555555555555',$result['movement']['movement_id']);
  Http::assertSent(fn($request)=>str_ends_with($request->url(),'/rest/v1/rpc/apply_inventory_stock_movement')&&$request->hasHeader('Authorization','Bearer caller-jwt')&&$request['p_movement_type']==='stock_in');
 }
}