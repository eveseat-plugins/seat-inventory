<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RecursiveTree\Seat\Inventory\Jobs\UpdateInventory;

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

