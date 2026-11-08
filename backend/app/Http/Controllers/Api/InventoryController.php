<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\InventoryIndexRequest;
use App\Http\Requests\Api\StoreInventoryItemRequest;
use App\Http\Requests\Api\UpdateInventoryItemRequest;
use App\Http\Requests\Api\StoreItemCategoryRequest;
use App\Http\Requests\Api\UpdateItemCategoryRequest;
use App\Http\Requests\Api\StoreEquipmentAssetRequest;
use App\Http\Requests\Api\UpdateEquipmentAssetRequest;
use App\Http\Requests\Api\StoreInventoryMovementRequest;
use App\Http\Resources\Api\InventoryItemResource;
use App\Http\Resources\Api\InventoryMovementResource;
use App\Services\Inventory\SupabaseInventoryService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
final class InventoryController extends Controller {
 public function __construct(private readonly SupabaseInventoryService $inventory){}
 private function token(Request $r):string{return (string)$r->bearerToken();}
 public function index(InventoryIndexRequest $r):JsonResponse{$v=$this->inventory->listItems($this->token($r),$r->options());return ApiResponse::success(['items'=>InventoryItemResource::collection($v['items'])->resolve()],'Inventory items retrieved successfully.',200,['pagination'=>$v['pagination']]);}
 public function show(Request $r,string $itemId):JsonResponse{return ApiResponse::success(['item'=>new InventoryItemResource($this->inventory->findItem($this->token($r),$itemId))],'Inventory item retrieved successfully.');}
 public function store(StoreInventoryItemRequest $r):JsonResponse{return ApiResponse::success(['item'=>new InventoryItemResource($this->inventory->createItem($this->token($r),$r->validated()))],'Inventory item created successfully.',201);}
 public function update(UpdateInventoryItemRequest $r,string $itemId):JsonResponse{$d=$r->validated();if($d===[])return ApiResponse::error('At least one inventory field is required.',422,'validation_error');return ApiResponse::success(['item'=>new InventoryItemResource($this->inventory->updateItem($this->token($r),$itemId,$d))],'Inventory item updated successfully.');}
 public function archive(Request $r,string $itemId):JsonResponse{return ApiResponse::success(['item'=>new InventoryItemResource($this->inventory->archiveItem($this->token($r),$itemId))],'Inventory item archived successfully.');}
 public function categories(Request $r):JsonResponse{return ApiResponse::success(['categories'=>$this->inventory->categories($this->token($r))],'Inventory categories retrieved successfully.');}
 public function storeCategory(StoreItemCategoryRequest $r):JsonResponse{return ApiResponse::success(['category'=>$this->inventory->createCategory($this->token($r),$r->validated())],'Inventory category created successfully.',201);}
 public function updateCategory(UpdateItemCategoryRequest $r,string $categoryId):JsonResponse{$d=$r->validated();if($d===[])return ApiResponse::error('At least one category field is required.',422,'validation_error');return ApiResponse::success(['category'=>$this->inventory->updateCategory($this->token($r),$categoryId,$d)],'Inventory category updated successfully.');}
 public function deleteCategory(Request $r,string $categoryId):JsonResponse{$this->inventory->deleteCategory($this->token($r),$categoryId);return ApiResponse::success(null,'Inventory category deleted successfully.');}
 public function locations(Request $r):JsonResponse{return ApiResponse::success(['locations'=>$this->inventory->locations($this->token($r))],'Inventory locations retrieved successfully.');}
 public function assets(Request $r,string $itemId):JsonResponse{return ApiResponse::success(['assets'=>$this->inventory->assets($this->token($r),$itemId)],'Equipment assets retrieved successfully.');}
 public function storeAsset(StoreEquipmentAssetRequest $r,string $itemId):JsonResponse{return ApiResponse::success(['asset'=>$this->inventory->createAsset($this->token($r),$itemId,$r->validated())],'Equipment asset created successfully.',201);}
 public function updateAsset(UpdateEquipmentAssetRequest $r,string $itemId,string $assetId):JsonResponse{$d=$r->validated();if($d===[])return ApiResponse::error('At least one asset field is required.',422,'validation_error');return ApiResponse::success(['asset'=>$this->inventory->updateAsset($this->token($r),$itemId,$assetId,$d)],'Equipment asset updated successfully.');}
 public function movements(Request $r,string $itemId):JsonResponse{$d=$r->validate(['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100']]);$v=$this->inventory->movements($this->token($r),$itemId,(int)($d['page']??1),(int)($d['per_page']??20));return ApiResponse::success(['movements'=>InventoryMovementResource::collection($v['movements'])->resolve()],'Inventory movement history retrieved successfully.',200,['pagination'=>$v['pagination']]);}
 public function storeMovement(StoreInventoryMovementRequest $r,string $itemId):JsonResponse{$v=$this->inventory->moveStock($this->token($r),$itemId,$r->validated());return ApiResponse::success(['movement'=>$v['movement'],'item'=>new InventoryItemResource($v['item'])],'Inventory stock movement recorded successfully.',201);}
}