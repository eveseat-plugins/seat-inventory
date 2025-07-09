<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveTree\Seat\Inventory\Models\Stock;
use RecursiveTree\Seat\Inventory\Models\StockCategory;


class Categories extends Migration
{
    public function up()
    {
        if(!Schema::hasTable('recursive_tree_seat_inventory_stock_categories')) {
            Schema::create('recursive_tree_seat_inventory_stock_categories', function (Blueprint $table) {
                $table->bigIncrements("id");
                $table->string("name");
                $table->bigInteger("fitting_plugin_doctrine_id")->nullable()->default(null);
            });
        }

        if(!Schema::hasTable('recursive_tree_seat_inventory_stock_category_mapping')) {
            Schema::create('recursive_tree_seat_inventory_stock_category_mapping', function (Blueprint $table) {
                $table->bigIncrements("id");
                $table->bigInteger("stock_id");
                $table->bigInteger("category_id");
            });
        }

        // add all existing stock to a default category. We can use models since they might change
        DB::table('recursive_tree_seat_inventory_stock_categories')
            ->insert([
                'name' => 'Default View'
            ]);
        $category_id = DB::table('recursive_tree_seat_inventory_stock_categories')->first()->id;

        $records = DB::table('recursive_tree_seat_inventory_stock_definitions')
            ->pluck('id')
            ->map(function ($id) use ($category_id) {
                return [
                    'stock_id'=>$id,
                    'category_id'=>$category_id
                ];
            });

        DB::table('recursive_tree_seat_inventory_stock_category_mapping')
            ->insert($records->toArray());
    }

    public function down()
    {
        Schema::drop('recursive_tree_seat_inventory_stock_categories');
        Schema::drop('recursive_tree_seat_inventory_stock_category_mapping');
    }
}

