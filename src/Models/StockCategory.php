<?php

namespace RecursiveTree\Seat\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockCategory extends Model
{
    public $timestamps = false;

    protected $table = 'seat_inventory_stock_categories';

    public function stocks(){
        return $this->belongsToMany(
            Stock::class,
            "seat_inventory_stock_category_mapping",
            "category_id",
            "stock_id"
        )->withPivot('manually_added','category_eligible')->using(StockCategoryMapping::class);
    }

    public function location(){
        return $this->hasOne(Location::class, 'category_id', 'id');
    }

    public function updateMembers($stocks){
        $syncData = [];

        //ensure manually added stocks stay that way
        $manually_added = $this->stocks()->wherePivot("manually_added",true)->pluck(sprintf('%s.id',Stock::TABLE));
        foreach ($manually_added as $stock){
            $syncData[$stock] = ["manually_added"=>true,"category_eligible"=>false];
        }

        $filters = $this->filters;

        $eligible = $stocks->filter(function (Stock $stock) use ($filters) {
            return $stock->isEligibleForCategory($filters);
        })->pluck("id");

        foreach ($eligible as $stock){
            if(!array_key_exists($stock,$syncData)){
                $syncData[$stock] = ["manually_added"=>false,"category_eligible"=>true];
            } else {
                //it's a manually added stock, only set category_eligible
                $syncData[$stock]["category_eligible"] = true;
            }
        }

        $this->stocks()->sync($syncData);
    }
}