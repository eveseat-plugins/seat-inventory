<?php

namespace RecursiveTree\Seat\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Seat\Eveapi\Models\Market\Price;
use Seat\Eveapi\Models\Sde\InvType;

class StockItem extends Model implements ItemEntry
{
    public const TABLE = 'seat_inventory_stock_items';

    public $timestamps = false;

    protected $table = self::TABLE;

    public function stock(): HasOne
    {
        return $this->hasOne(Stock::class, "id", "stock_id");
    }

    public function type(): HasOne
    {
        return $this->hasOne(InvType::class, 'typeID', 'type_id');
    }

    public function getTypeId()
    {
        return $this->type_id;
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public static function fromAlternativeAmountColumn($items, $amount_column): ItemEntryList
    {
        return ItemEntryList::fromItemEntries(
            $items->map(function ($item) use ($amount_column) {
                return new ItemEntryBasic($item->type_id,$item[$amount_column]);
            })->values()
        );
    }

    public function prices(): HasOne
    {
        return $this->hasOne(Price::class, 'type_id', 'type_id');
    }
}