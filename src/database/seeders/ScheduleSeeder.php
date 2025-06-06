<?php

namespace RecursiveTree\Seat\Inventory\Seeders;

use Seat\Services\Seeding\AbstractScheduleSeeder;

class ScheduleSeeder extends AbstractScheduleSeeder
{
    public function getSchedules(): array
    {
        return [
            [
                'command' => 'inventory:sources:update',
                'expression' => sprintf('%d * * * *',rand(0,59)),
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ]
        ];
    }

    public function getDeprecatedSchedules(): array
    {
        // these commands are no longer in use, remove them
        return [

        ];
    }
}
