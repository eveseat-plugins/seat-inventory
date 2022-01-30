<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

use RecursiveTree\Seat\Inventory\Jobs\UpdateInventory;
use RecursiveTree\Seat\Inventory\Jobs\UpdateStockLevels;
use RecursiveTree\Seat\Inventory\Models\InventorySource;
use RecursiveTree\Seat\Inventory\Observers\UniverseStationObserver;
use RecursiveTree\Seat\Inventory\Observers\UniverseStructureObserver;
use Seat\Eveapi\Models\Universe\UniverseStation;
use Seat\Eveapi\Models\Universe\UniverseStructure;

class AddUpdateTimestamps extends Migration
{
    public function up()
    {
        Schema::table('recursive_tree_seat_inventory_stock_definitions',function (Blueprint $table){
            $table->timestamp("last_updated")->nullable();
        });

        Schema::table('recursive_tree_seat_inventory_inventory_source',function (Blueprint $table){
            $table->timestamp("last_updated")->nullable();
        });

        UpdateInventory::dispatch()->onQueue('default');
    }

    public function down()
    {
        Schema::table('recursive_tree_seat_inventory_stock_definitions',function (Blueprint $table){
            $table->dropColumn("last_updated");
        });

        Schema::table('recursive_tree_seat_inventory_inventory_source',function (Blueprint $table){
            $table->dropColumn("last_updated");
        });
    }
}

