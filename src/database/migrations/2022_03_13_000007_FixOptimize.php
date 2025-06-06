<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveTree\Seat\Inventory\Jobs\UpdateInventory;

class FixOptimize extends Migration
{
    public function up()
    {
        if(!Schema::hasColumn('recursive_tree_seat_inventory_inventory_item', "id")) {
            Schema::table('recursive_tree_seat_inventory_inventory_item', function (Blueprint $table) {
                $table->bigIncrements("id");
            });
        }

        if(!Schema::hasColumn('recursive_tree_seat_inventory_stock_items', "id")) {
            Schema::table('recursive_tree_seat_inventory_stock_items', function (Blueprint $table) {
                $table->bigIncrements("id");
            });
        }
    }

    public function down()
    {
        if(Schema::hasColumn('recursive_tree_seat_inventory_inventory_item', "id")) {
            Schema::table('recursive_tree_seat_inventory_inventory_item', function (Blueprint $table) {
                $table->dropColumn("id");
            });
        }

        if(Schema::hasColumn('recursive_tree_seat_inventory_stock_items', "id")) {
            Schema::table('recursive_tree_seat_inventory_stock_items', function (Blueprint $table) {
                $table->dropColumn("id");
            });
        }
    }
}

