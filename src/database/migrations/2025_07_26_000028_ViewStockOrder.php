<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up()
    {
        Schema::table("seat_inventory_stock_category_mapping",function (Blueprint $table){
            $table->integer("list_order")->default(0);
            $table->index("list_order");
        });

        // keep current order
        DB::table("seat_inventory_stock_category_mapping")
            ->update([
                "list_order"=>DB::raw("id")
            ]);
    }

    public function down()
    {
        Schema::table("seat_inventory_stock_category_mapping",function (Blueprint $table){
            $table->dropColumn("list_order");
        });
    }
};

