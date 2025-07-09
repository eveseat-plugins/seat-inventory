<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up()
    {
        Schema::table("seat_inventory_tracked_corporations",function (Blueprint $table){
            $table->boolean("include_to_corporation")->default(true);
            $table->boolean("include_from_corporation")->default(false);
        });
    }

    public function down()
    {
        Schema::table("seat_inventory_tracked_corporations",function (Blueprint $table){
            $table->dropColumn("include_to_corporation");
            $table->dropColumn("include_from_corporation");
        });
    }
};

