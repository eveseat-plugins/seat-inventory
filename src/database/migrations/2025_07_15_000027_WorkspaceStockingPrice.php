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
    }

    public function down()
    {
        Schema::table("seat_inventory_workspaces",function (Blueprint $table){
            $table->dropColumn("enable_stocking_prices");
        });
    }
};

