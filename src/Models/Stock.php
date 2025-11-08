<?php

namespace RecursiveTree\Seat\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Intervention\Image\Facades\Image;
use RecursiveTree\Seat\TreeLib\Helpers\FittingPluginHelper;
use RecursiveTree\Seat\Inventory\Jobs\GenerateStockIcon;
use Seat\Services\Contracts\HasTypeID;
use Seat\Services\Contracts\HasTypeIDWithAmount;

class Stock extends Model
{
    public const TABLE = 'seat_inventory_stocks';

    public $timestamps = false;

    protected $table = self::TABLE;

    protected $hidden = ['icon'];

    public function location(){
        return $this->hasOne(Location::class, 'id', 'location_id');
    }

    public function fitting_plugin(){
        return $this->hasOne(FittingPluginHelper::$FITTING_PLUGIN_FITTING_MODEL, 'fitting_id', 'fitting_plugin_fitting_id');
    }

    public function items()
    {
        return $this->hasMany(StockItem::class,"stock_id","id");
    }

    public function levels(){
        return $this->hasMany(StockLevel::class,"stock_id","id");
    }

    public function categories(){
        return $this->belongsToMany(
            StockCategory::class,
            "seat_inventory_stock_category_mapping",
            "stock_id",
            "category_id"
        )->withPivot('manually_added','category_eligible')->using(StockCategoryMapping::class);
    }

    public function getIcon(){
        if($this->icon){
            return $this->icon;
        } else {
            GenerateStockIcon::dispatch($this->id,null); //schedule the image to be generated

            $image = Image::make(__DIR__."/../resources/images/generating.png"); //return a default in the meantime
            return $image->encode("data-url");
        }
    }

    public function setIcon($image){
        $this->icon = $image->encode("data-url");
    }

    public function getTotalAvailable(){
        return $this->available;
    }

    public function isEligibleForCategory($filters): bool
    {
        $filters = json_decode($filters);

        $has_location = false;
        $location_fulfilled = false;

        $has_doctrine = false;
        $doctrine_fulfilled = false;

        foreach ($filters as $filter){
            if($filter->type === "location"){
                $has_location = true;
                if($this->location_id === $filter->id) $location_fulfilled = true;

            } else if($filter->type === "doctrine"){
                $has_doctrine = true;
                if(!FittingPluginHelper::pluginIsAvailable()){
                    $doctrine_fulfilled = true;
                } else {
                    $doctrine = FittingPluginHelper::$FITTING_PLUGIN_DOCTRINE_MODEL::find($filter->id);
                    if ($doctrine) {
                        if ($doctrine->fittings()->where("crypta_tech_seat_fittings.fitting_id", $this->fitting_plugin_fitting_id)->exists()) $doctrine_fulfilled = true;
                    }
                }
            }
        }

        return
            ($has_location || $has_doctrine) //only eligible if we have filters
            &&(!$has_location || $location_fulfilled) //location
            && (!$has_doctrine || $doctrine_fulfilled); //doctrine
    }

    public function saveItems($items): void {
        // delete old items
        $types = $items->map(function (HasTypeID $item){
            return $item->getTypeID();
        })->unique();
        StockItem::destroy($this->items()->whereNotIn("type_id",$types)->pluck('id'));

        //dd($this->bundle_size);

        $this->items()->upsert($items->map(function (HasTypeIDWithAmount $item){
            return [
                'type_id'=>$item->getTypeID(),
                'amount'=>$item->getAmount() * $this->bundle_size,
                'original_amount' => $item->getAmount(),
                'stock_id'=>$this->id,
                'missing_items'=>$item->getAmount() * $this->bundle_size
            ];
        })->values()->toArray(),['type_id','stock_id'],['amount','missing_items','original_amount']);
    }
}