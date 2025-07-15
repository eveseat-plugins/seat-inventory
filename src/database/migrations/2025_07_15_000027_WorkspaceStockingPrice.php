<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up()
    {
        Schema::table("seat_inventory_workspaces",function (Blueprint $table){
            $table->boolean("enable_stocking_prices")->default(false);
        });

        Schema::table("seat_inventory_stocks",function (Blueprint $table){
            $table->bigInteger("contract_stocking_price")->unsigned()->default(0);
        });
    }

    public function down()
    {
        Schema::table("seat_inventory_workspaces",function (Blueprint $table){
            $table->dropColumn("enable_stocking_prices");
        });

        Schema::table("seat_inventory_stocks",function (Blueprint $table){
            $table->dropColumn("contract_stocking_price");
        });
    }
};

