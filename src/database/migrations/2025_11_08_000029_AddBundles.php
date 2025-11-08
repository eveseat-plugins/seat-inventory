<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up()
    {
        Schema::table("seat_inventory_stocks",function (Blueprint $table){
            $table->integer("bundle_size")->unsigned()->default(1);
        });
        Schema::table("seat_inventory_stock_items",function (Blueprint $table){
            $table->integer("original_amount")->unsigned();
        });

        DB::table("seat_inventory_stock_items")->update(["original_amount"=>DB::raw("amount")]);
    }

    public function down()
    {
        Schema::table("seat_inventory_stocks",function (Blueprint $table){
            $table->dropColumn("bundle_size");
        });
        Schema::table("seat_inventory_stock_items",function (Blueprint $table){
            $table->dropColumn("original_amount");
        });
    }
};

