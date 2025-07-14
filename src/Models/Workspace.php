<?php

namespace RecursiveTree\Seat\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Universe\UniverseStation;
use Seat\Eveapi\Models\Universe\UniverseStructure;

class Workspace extends Model
{
    public $timestamps = false;

    public const TABLE = 'seat_inventory_workspaces';

    protected $table = self::TABLE;

    public function markets(){
        return $this->hasMany(TrackedMarket::class,"workspace_id","id");
    }
}