<?php

namespace RecursiveTree\Seat\Inventory\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Intervention\Image\Facades\Image;
use RecursiveTree\Seat\Inventory\Helpers\ItemHelper;
use RecursiveTree\Seat\Inventory\Jobs\UpdateStockLevels;
use RecursiveTree\Seat\Inventory\Models\InventoryItem;
use RecursiveTree\Seat\Inventory\Models\InventorySource;
use RecursiveTree\Seat\Inventory\Models\Location;
use RecursiveTree\Seat\TreeLib\Parser\Parser;
use Seat\Web\Http\Controllers\Controller;

class DeliveriesController extends Controller
{
    public function addDeliveries(Request $request){
        $request->validate([
            "items" => "string|required",
            "location"=>"integer|required",
            "workspace"=>"required|integer"
        ]);

        $location_id = $request->location;
        $multibuy = $request->items;

        if (!Location::find($location_id)){
            return response()->json(["message"=>"location not found"],400);
        }

        $parser_result = Parser::parseItems($multibuy);
        if ($parser_result===null){
            return response()->json(["message"=>"Failed to parse multibuy"],400);
        }
        $items = $parser_result->items->simplifyItems();
        if($items->count()<1){
            return response()->json(["message"=>"You can't submit an empty multibuy"],400);
        }

        $source = new InventorySource();
        $source->location_id = $location_id;
        $source->source_name = "Pending Delivery";
        $source->source_type = "in_transport";
        $source->workspace_id = $request->workspace;
        $source->save();

        foreach ($items as $item){
            $source_item = new InventoryItem();
            $source_item->type_id = $item->typeModel->typeID;
            $source_item->amount = $item->amount ?? 1;
            $source_item->source_id = $source->id;
            $source_item->save();
        }

        //update stock levels for new stock
        UpdateStockLevels::dispatch($source->location_id,$request->workspace)->onQueue('default');


        return response()->json(["message"=>"Added"]);
    }

    public function listDeliveries(Request $request){
        $request->validate([
            "workspace"=>"required|integer"
        ]);

        $deliveries = InventorySource::with("location","items.type")
            ->where("workspace_id",$request->workspace)
            ->where("source_type","in_transport")
            ->get();
        return response()->json($deliveries);
    }

    public function deleteDeliveries(Request $request){
        $request->validate([
            "id"=>"integer|required"
        ]);

        $id = $request->id;

        $source = InventorySource::where("id",$id)->where("source_type","in_transport")->first();

        if($source) {
            $source->items()->delete();
            InventorySource::destroy($id);

            //update stock levels for new stock
            UpdateStockLevels::dispatch($source->location_id, $source->workspace_id)->onQueue('default');
        } else {
            return response()->json(["message"=>"Delivery not found!"],400);
        }

        return response()->json();
    }
}