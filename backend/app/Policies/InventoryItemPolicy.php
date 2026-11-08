<?php
namespace App\Policies;
use Illuminate\Auth\GenericUser;
final class InventoryItemPolicy {
 public function viewAny(GenericUser $user):bool{return in_array('inventory.view',$user->permissions??[],true);}
 public function view(GenericUser $user,mixed $record=null):bool{return $this->viewAny($user);}
 public function create(GenericUser $user):bool{return in_array('inventory.manage',$user->permissions??[],true);}
 public function update(GenericUser $user,mixed $record=null):bool{return $this->create($user);}
 public function delete(GenericUser $user,mixed $record=null):bool{return $this->create($user);}
 public function manageStock(GenericUser $user):bool{return $this->create($user);}
 public function viewHistory(GenericUser $user):bool{return $this->viewAny($user);}
}