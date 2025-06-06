<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveTree\Seat\Inventory\Jobs\UpdateInventory;

class Optimize extends Migration
{
    public function up()
    {
        Schema::table('recursive_tree_seat_inventory_inventory_item', function (Blueprint $table) {
            $table->dropColumn("id");
        });

        Schema::table('recursive_tree_seat_inventory_stock_items', function (Blueprint $table) {
            $table->dropColumn("id");
        });

        Schema::table('recursive_tree_seat_inventory_tracked_alliances', function (Blueprint $table) {
            $table->dropColumn("id");
            $table->renameColumn('automate_corporations', 'manage_members');
        });

        Schema::table('recursive_tree_seat_inventory_tracked_corporations', function (Blueprint $table) {
            $table->dropColumn("id");
            $table->bigInteger("managed_by")->nullable()->default(null);
        });
    }

    public function down()
    {
        Schema::table('recursive_tree_seat_inventory_inventory_item', function (Blueprint $table) {
            $table->bigIncrements("id");
        });

        Schema::table('recursive_tree_seat_inventory_stock_items', function (Blueprint $table) {
            $table->bigIncrements("id");
        });

        Schema::table('recursive_tree_seat_inventory_tracked_alliances', function (Blueprint $table) {
            $table->bigIncrements("id");
            $table->renameColumn('manage_members','automate_corporations');
        });

        Schema::table('recursive_tree_seat_inventory_tracked_corporations', function (Blueprint $table) {
            $table->bigIncrements("id");
            $table->dropColumn("managed_by");
        });
    }
}

