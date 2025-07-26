<?php

namespace RecursiveTree\Seat\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class StockCategoryMapping extends Pivot
{
    public $timestamps = false;
    public $incrementing = true;
    protected $table = 'seat_inventory_stock_category_mapping';
}