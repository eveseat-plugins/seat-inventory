<?php

namespace RecursiveTree\Seat\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class InventorySource extends Model
{
    public $timestamps = false;

    protected $table = 'seat_inventory_inventory_source';

    protected $casts = [
        'last_updated' => 'datetime',
    ];

    public function location(){
        return $this->hasOne(Location::class, 'id', 'location_id');
    }

    public function items()
    {
        return $this->hasMany(InventoryItem::class,"source_id","id");
    }

    public function getSourceType(){
        $sources = config('inventory.sources');

        if(array_key_exists($this->source_type,$sources)){
            return $sources[$this->source_type];
        }

        return null;
    }
}