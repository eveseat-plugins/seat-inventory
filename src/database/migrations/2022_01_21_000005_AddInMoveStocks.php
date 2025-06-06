<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveTree\Seat\Inventory\Jobs\UpdateInventory;

class AddInMoveStocks extends Migration
{
    public function up()
    {

        DB::statement("ALTER TABLE `recursive_tree_seat_inventory_inventory_source` CHANGE `source_type` `source_type` ENUM('corporation_hangar', 'contract', 'in_transport');");
    }

    public function down()
    {
        DB::statement("ALTER TABLE `recursive_tree_seat_inventory_inventory_source` CHANGE `source_type` `source_type` ENUM('corporation_hangar', 'contract');");
    }
}

