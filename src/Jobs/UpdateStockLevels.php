<?php

namespace RecursiveTree\Seat\Inventory\Jobs;

use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RecursiveTree\Seat\Inventory\Helpers\ItemHelper;
use RecursiveTree\Seat\Inventory\Models\InventoryItem;
use RecursiveTree\Seat\Inventory\Models\InventorySource;
use RecursiveTree\Seat\Inventory\Models\ItemEntryBasic;
use RecursiveTree\Seat\Inventory\Models\ItemEntryList;
use RecursiveTree\Seat\Inventory\Models\Stock;
use RecursiveTree\Seat\Inventory\Models\StockLevel;

class UpdateStockLevels implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function tags()
    {
        return [ 'location:' . $this->location_id,'workspace:'.$this->workspace_id, "seat-inventory", "stock-levels" ];
    }

    private $location_id;
    private $sync;
    private $workspace_id;

    public function __construct($location_id,$workspace,$sync=false){
        $this->location_id = $location_id;
        $this->sync = $sync;
        $this->workspace_id = $workspace;
    }

    public function middleware(): array
    {
        return array_merge(
            [
                (new WithoutOverlapping($this->workspace_id))->releaseAfter(60),
            ]
        );
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes(15);
    }

    public function handle()
    {
        if(!$this->sync) {
            Redis::funnel("seat.inventory.stock.update.lock.location.$this->location_id.workspace.$this->workspace_id")->limit(1)->then(
                function () {
                    $this->updateStockLevels();
                },
                function () {
                    //update already in progress, delete the job
                    $this->delete();
                }
            );
        } else {
            $this->updateStockLevels();
        }
    }

    private function matchUnitSource($source, $stock){
        //get a map of the items
        $map = ItemEntryList::fromItemEntries($source->items)->asItemMap();

        //go over all required items
        foreach ($stock->items as $item){
            //get the type id
            $type_id = $item->getTypeId();
            //check if source has type
            if($map->has($type_id)){
                //get amount
                $amount = $map->get($type_id);
                //check if amount is sufficient
                if($amount < $item->getAmount()){
                    return false;
                }
            } else {
                return false;
            }
        }

        //no failure until now -> success
        return true;
    }

    private function saveStock($stock_data,$time){

        $stock = $stock_data["stock"];
        $stock->last_updated = $time;
        $stock->available = $stock->amount - $stock_data["remaining"];

        foreach ($stock->items as $item){
            $item->save();
        }

        $old_stock_levels = $stock->levels()->whereNotIn("source_type",$stock_data["source_types"]->keys())->get();
        foreach ($old_stock_levels as $old_stock_level){
            $old_stock_level->delete();
        }


        foreach ($stock_data["source_types"] as $type=>$amount){
            $level = $stock->levels()->where("source_type",$type)->first();
            if(!$level) {
                $level = new StockLevel();
                $level->stock_id = $stock->id;
                $level->source_type=$type;
            }
            $level->amount = $amount;
            $level->save();
        }

        $stock->save();
    }

    private function updateStockLevels()
    {
        //fetch and group stocks
        $stock_priority_groups = Stock::with("items", "levels")
            ->where("location_id", $this->location_id)
            ->where("workspace_id",$this->workspace_id)
            ->get()
            //create wrapper holding temp info
            ->map(function ($stock) {
                return [
                    "stock" => $stock,
                    "remaining" => $stock->amount,
                    "priority" => $stock->priority,
                    "virtual" => 0,
                    "real" => 0,
                    "source_types" => collect()
                ];
            })
            ->groupBy("priority")
            ->sortKeysDesc()
            ->toArray();

        //get sources
        $sources = InventorySource::with("items")
            ->where("location_id", $this->location_id)
            ->where("workspace_id",$this->workspace_id)
            ->get();

        $oldest_datapoint = now();
        foreach ($sources as $source) {
            if($source->last_updated !== null && $source->last_updated->lt($oldest_datapoint)) {
                $oldest_datapoint = $source->last_updated;
            }
        }

        //place real, non-virtual sources first, so they get preferred when iterating over source to see if they are fulfilled
        $sources = $sources->sortBy(function ($source) {
            $source_type = $source->getSourceType();

            if ($source_type["virtual"]) return 2;
            return 1;
        })
        //add temp data required during computation
        ->map(function ($source) {
            $source_type = $source->getSourceType();
            return [
                "source" => $source,
                "used" => false,
                "pooled" => $source_type["pooled"],
                "virtual" => $source_type["virtual"],
                "type_name" => $source->source_type
            ];
        });

        //prepare to sort sources
        $pooled_items = collect();
        $unit_sources = collect();

        //sort sources
        foreach ($sources as &$source) {
            if ($source["pooled"]) {
                $pooled_items = $pooled_items->merge($source["source"]->items);
            } else {
                $unit_sources->push($source);
            }
        }

        //prepare pooled items
        $pooled_items = ItemEntryList::fromItemEntries($pooled_items);
        $pooled_items->simplify();
        $pooled_items = $pooled_items->asItemMap()->toArray();


        //prepare unit sources
        $unit_sources = $unit_sources->toArray();

        //calculate stock levels
        foreach ($stock_priority_groups as &$stock_priority_group) {
            //try to fulfill stocks with unit groups
            foreach ($stock_priority_group as &$stock) {
                //if the stock is fulfilled, we can already ignore it
                if ($stock["remaining"] < 1) continue;


                foreach ($unit_sources as &$source) {
                    //due to difficult bug caused by mutating a list while iterating it, we use a used flag to mark used sources
                    if ($source["used"]) continue;

                    $is_match = $this->matchUnitSource($source["source"], $stock["stock"]);
                    if ($is_match) {
                        //decrease remaining required amount
                        $stock["remaining"] -= 1;
                        //mark source as used
                        $source["used"] = true;
                        //stock: count virtual and real stocks
                        if ($source["virtual"]) {
                            $stock["virtual"] += 1;
                        } else {
                            $stock["real"] += 1;
                        }
                        //stock: add source type
                        $name = $source["type_name"];
                        $types = $stock["source_types"];
                        if ($types->has($name)) {
                            $types->put($name, $types->get($name) + 1);
                        } else {
                            $types->put($name, 1);
                        }

                        //if we fulfill the stock, there is no need to continue checking
                        if ($stock["remaining"] < 1) break;
                    }
                }
            }
        }

        //calculate pooled items
        foreach ($stock_priority_groups as &$stock_priority_group) {
            //calculate demand for this priority group
            $item_demand = collect();
            foreach ($stock_priority_group as &$stock) {
                //skip stocks which are fulfilled
                if ($stock["remaining"] < 1) continue;

                //adjust items for remaining amount
                $item_demand->push($stock["stock"]->items->map(function ($item) use ($stock) {
                    return new ItemEntryBasic($item->getTypeId(), $item->getAmount() * $stock["remaining"]);
                }));
            }

            $item_demand = ItemEntryList::fromItemEntries($item_demand->flatten());
            $item_demand->simplify();
            $item_demand = $item_demand->asItemMap()->toArray();
            foreach ($item_demand as $type_id=>&$demand){
                $available = ($pooled_items[$type_id] ?? 0);
                $demand = $available / $demand;
            }

            //item bonus for pooled items. Reset it after each priority group
            $item_bonus = [];

            foreach ($stock_priority_group as &$stock) {

                //first, fill remaining item, then save the stock. stock saving is done regardless of whether  we have missing items, as it also save all other data we've processed
                if ($stock["remaining"] > 0) {

                    $required = $stock["remaining"];
                    $possible = $required;

                    foreach ($stock["stock"]->items as &$item) {

                        $type_id = $item->getTypeId();
                        $amount = $item->getAmount();

                        $item_amount = $amount * $required;

                        $fair_amount = $item_demand[$type_id];

                        //we can fulfil it without problems
                        if ($fair_amount < 1) {
                            $bonus = $item_bonus[$type_id] ?? 0;
                            $scheduled_items = $item_amount * $fair_amount + $bonus;
                            $effective_items = floor($scheduled_items);

                            //update bonus
                            $item_bonus[$type_id] = $scheduled_items - $effective_items;

                            //decrease amount of possible stocks if required
                            $fulfilled = intdiv($effective_items, $amount);
                            if ($fulfilled < $possible) {
                                $possible = $fulfilled;
                            }

                            //decrease available items
                            $new_value = ($pooled_items[$type_id] ?? 0) - $effective_items;
                            if ($new_value < 0) {
                                $data = json_encode(["items" => $pooled_items, "stocks" => json_encode($stock_priority_groups)]);
                                throw new \Exception("Trying to distribute non-existing items! $data");
                            }
                            $pooled_items[$type_id] = $new_value;

                            //update missing items
                            $item->missing_items = $item_amount - $effective_items;

                        } else {
                            //we can fulfill the demanded amount
                            $item->missing_items = 0;

                            //decrease available items
                            $new_value = ($pooled_items[$type_id] ?? 0) - $item_amount;
                            if ($new_value < 0) {
                                $data = json_encode(["items" => $pooled_items, "stocks" => json_encode($stock_priority_groups)]);
                                throw new \Exception("Trying to distribute non-existing items! $data");
                            }
                            $pooled_items[$type_id] = $new_value;
                        }
                    }

                    $stock["remaining"] -= $possible;
                    $stock["source_types"]->put("item_pool", $possible);
                }

                //save the stock
                $this->saveStock($stock, $oldest_datapoint);
            }
        }
    }
}