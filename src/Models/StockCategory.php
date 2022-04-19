<?php

namespace RecursiveTree\Seat\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockCategory extends Model
{
    public $timestamps = false;

    protected $table = 'recursive_tree_seat_inventory_stock_categories';

    public function stocks(){
        return $this->belongsToMany(
            Stock::class,
            "recursive_tree_seat_inventory_stock_category_mapping",
            "category_id",
            "stock_id"
        )->withPivot('manually_added');;
    }

    public function location(){
        return $this->hasOne(Location::class, 'category_id', 'id');
    }

    public function updateMembers($stocks){
        $syncData = [];

        //ensure manually added stocks stay that way
        $manually_added = $this->stocks()->wherePivot("manually_added",true)->pluck("recursive_tree_seat_inventory_stock_definitions.id");
        foreach ($manually_added as $stock){
            $syncData[$stock] = ["manually_added"=>true];
        }

        $filters = $this->filters;

        $eligible = $stocks->filter(function ($stock) use ($filters) {
            return $stock->isEligibleForCategory($filters);
        })->pluck("id");

        foreach ($eligible as $stock){
            if(!array_key_exists($stock,$syncData)){
                $syncData[$stock] = ["manually_added"=>false];
            }
        }

        $this->stocks()->sync($syncData);
    }
}