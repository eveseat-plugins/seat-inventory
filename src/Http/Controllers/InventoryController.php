<?php

namespace RecursiveTree\Seat\Inventory\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Intervention\Image\Facades\Image;
use RecursiveTree\Seat\Inventory\Models\StockCategoryMapping;
use RecursiveTree\Seat\Inventory\Models\Workspace;
use RecursiveTree\Seat\TreeLib\Helpers\AllianceIndustryPluginHelper;
use RecursiveTree\Seat\TreeLib\Helpers\FittingPluginHelper;
use RecursiveTree\Seat\Inventory\Helpers\ItemHelper;
use RecursiveTree\Seat\Inventory\Jobs\GenerateStockIcon;
use RecursiveTree\Seat\Inventory\Jobs\UpdateCategoryMembers;
use RecursiveTree\Seat\Inventory\Jobs\UpdateStockLevels;
use RecursiveTree\Seat\Inventory\Models\InventoryItem;
use RecursiveTree\Seat\Inventory\Models\ItemEntryList;
use RecursiveTree\Seat\Inventory\Models\Location;
use RecursiveTree\Seat\Inventory\Models\Stock;
use RecursiveTree\Seat\Inventory\Models\StockCategory;
use RecursiveTree\Seat\Inventory\Models\StockItem;
use RecursiveTree\Seat\TreeLib\Items\EveItem;
use RecursiveTree\Seat\TreeLib\Parser\Parser;
use Seat\Eveapi\Models\Sde\InvType;
use Seat\Web\Http\Controllers\Controller;

class InventoryController extends Controller
{
    public function dashboard(Request $request){
        return view("inventory::dashboard");
    }

    public function getCategories(Request $request){
        $request->validate([
            "workspace"=>"required|integer"
        ]);

        $categories = StockCategory::with([
                "stocks" => function ($query) {
                    $query->orderBy("list_order");
                },
                "stocks.location",
                "stocks.categories",
                "stocks.levels",
                "stocks.items.prices"]
        )
            ->where("workspace_id", $request->workspace)
            ->get();

        foreach ($categories as $category) {
            foreach ($category->stocks as $stock) {
                $stock->missing_price = 0;
                foreach($stock->items as $item){
                    $stock->missing_price += $item->missing_items * $item->prices->sell_price ?? 0;
                }

                if(FittingPluginHelper::pluginIsAvailable()) {
                    $stock->invalid_fitting = $stock->fitting_plugin_fitting_id != null && !$stock->fitting_plugin()->exists();
                } else {
                    $stock->invalid_fitting = false;
                }

                # don't send items along to save bandwidth
                $stock->unsetRelation("items");
            }
        }



        return response()->json($categories->values());
    }

    public function locationLookup(Request $request){
        $request->validate([
            "term"=>"nullable|string",
            "id"=>"nullable|integer"
        ]);

        $query = Location::query();

        if($request->term){
            $query = $query->where("name","like","%$request->term%");
        }

        if($request->id){
            $query = $query->where("id",$request->id);
        }

        $locations = $query->get();

        $suggestions = $locations->map(function ($location){
            return [
                'id' => $location->id,
                'text' => $location->name
            ];
        });

        return response()->json([
            'results'=>$suggestions
        ]);
    }

    public function stockSuggestion(Request $request){
        $request->validate([
            "term"=>"nullable|string",
            "workspace"=>"required|integer"
        ]);

        $location_ids = null;
        if($request->term){
            $location_ids = Location::where("name","like","%$request->term%")->pluck("id");
        }

        $query = Stock::query();

        $query->where("workspace_id",$request->workspace);

        if($request->term){
            $query->where("name", "like", "%$request->term%");
        }
        if($location_ids) {
            $query->orWhereIn("location_id", $location_ids);
        }
        $stocks = $query->get();

        $suggestions = $stocks
            ->map(function ($stock){
                $location = $stock->location->name;
                return [
                    'id' => $stock,
                    'text' => "$stock->name --- $location"
                ];
            });

        return response()->json([
            'results'=>$suggestions
        ]);
    }

    public function saveCategory(Request $request){
        $request->validate([
            "name" => "required|string",
            "id" => "nullable|integer",
            "stocks" => "nullable|array",
            "stocks.*.id" => "required|integer",
            "stocks.*.manually_added"=>"required|boolean",
            "filters" => "nullable|array",
            "workspace"=>"required|integer"
        ]);

        if(!Workspace::where("id",$request->workspace)->exists()){
            return response()->json([],400);
        }

        $category = StockCategory::find($request->id);
        if(!$category){
            $category = new StockCategory();
        }

        foreach ($request->stocks as $id){
            $stock = Stock::find($id);
            if(!$stock) {
                return response()->json([],400);
            }
        }

        $category->name = $request->name;
        $category->filters = json_encode($request->filters);
        $category->workspace_id = $request->workspace;
        $category->save();

        //save stocks after category so the category has an id when creating a new category
        $syncData = [];
        foreach ($request->stocks as $stock){
            $syncData[$stock['id']] = ["manually_added"=>$stock['manually_added']];
        }
        $category->stocks()->sync($syncData);

        //manually update members for this category synchronously. This means we don't have to trigger a complete update
        $category->updateMembers(Stock::all());

        return response()->json();
    }

    public function deleteCategory(Request $request){
        $request->validate([
            "id" => "required|integer"
        ]);

        $category = StockCategory::find($request->id);
        if(!$category){
            return response()->json([],400);
        }

        //remove all linked stocks
        $category->stocks()->detach();
        //actually delete it
        StockCategory::destroy($request->id);

        return response()->json();
    }

    public function removeStockFromCategory(Request $request){
        $request->validate([
            "stock"=>"required|integer",
            "category"=>"required|integer"
        ]);

        $category = StockCategory::find($request->category);
        if(!$category){
            return response()->json([],400);
        }

        $category->stocks()->detach($request->stock);

        return response()->json();
    }

    public function deleteStock(Request $request){
        $request->validate([
            "id"=>"required|integer"
        ]);

        $stock = Stock::find($request->id);

        if(!$stock){
            return response()->json([],400);
        }

        //delete categories
        $stock->categories()->detach();
        //delete items
        $stock->items()->delete();
        $stock->levels()->delete();
        //delete the stock itself
        Stock::destroy($request->id);

        UpdateStockLevels::dispatch($stock->location_id,$stock->workspace_id);

        return response()->json();
    }

    public function saveStock(Request $request){

        //validation
        $request->validate([
            "id"=>"nullable|integer",
            "location"=>"required|integer",
            "amount"=>"required|integer|gt:0",
            "warning_threshold"=>"required|integer|gte:0",
            "contract_stocking_price"=>"required|integer|gte:0",
            "priority"=>"required|integer|gte:0|lte:5",
            "fit"=>"nullable|string",
            "multibuy"=>"nullable|string",
            "plugin_fitting_id"=>"nullable|integer",
            "name"=>"required_with:multibuy|string",
            "workspace"=>"required|integer"
        ]);

        //validate workspace
        if(!Workspace::where("id",$request->workspace)->exists()){
            return response()->json(["message"=>"workspace not found"],400);
        }

        //validate location
        $location = Location::find($request->location);
        if(!$location) return response()->json(["message"=>"location not found"],400);

        //validate type
        if($request->multibuy === null && $request->fit===null && $request->plugin_fitting_id===null) return response()->json(["message"=>"neither fit nor multibuy found"],400);

        $items_text = $request->multibuy;
        if($request->fit){
            $items_text = $request->fit;
        } else if ($request->plugin_fitting_id && FittingPluginHelper::pluginIsAvailable()){
            $fitting = FittingPluginHelper::$FITTING_PLUGIN_FITTING_MODEL::find($request->plugin_fitting_id);

            if(!$fitting){
                return response()->json(["message"=>"Fitting not found"],400);
            }

            $items_text = $fitting->toEve();
        }

        //parse items
        $parser_results = Parser::parseItems($items_text);
        if($parser_results == null){
            return response()->json(["message"=>"Failed to parse fit/items"],400);
        }

        $name = $parser_results->shipName ?? $request->name ?? "unnamed";

        if($parser_results->items->isEmpty()){
            return response()->json(["message"=>"Empty stocks aren't allowed!"],400);
        }

        //data filling stage

        //make sure there aren't any duplicate item stacks
        $items = $parser_results->items->simplifyItems();

        //get the stock
        $stock = Stock::findOrNew($request->id);

        //use a transaction to roll it back if anything fails
        DB::transaction(function () use ($stock, $items, $name, $request) {
            $stock->name = $name;
            $stock->location_id = $request->location;
            $stock->amount = $request->amount;
            $stock->warning_threshold = $request->warning_threshold;
            $stock->contract_stocking_price = $request->contract_stocking_price;
            $stock->priority = $request->priority;
            $stock->fitting_plugin_fitting_id = $request->plugin_fitting_id;
            $stock->available = 0;
            $stock->workspace_id = $request->workspace;

            //make sure we get an id
            $stock->save();

            //remove old items
            $stock->items()->delete();

            $stock->items()->saveMany($items->map(function ($item) use ($stock) {
                $stock_item = new StockItem();
                $stock_item->stock_id = $stock->id;
                $stock_item->type_id = $item->typeModel->typeID;
                $stock_item->amount = $item->amount;
                return $stock_item;
            }));
        });

        //data update phase

        //update stock levels for new stock
        UpdateStockLevels::dispatch($location->id, $request->workspace)->onQueue('default');

        //generate a new icon
        GenerateStockIcon::dispatch($stock->id,null);

        //categorize the stock. We have to update every category, as it might fulfil any condition
        UpdateCategoryMembers::dispatch();

        return response()->json();
    }

    public function doctrineLookup(Request $request){
        $request->validate([
            "term"=>"nullable|string",
            "id"=>"nullable|integer"
        ]);

        if(!FittingPluginHelper::pluginIsAvailable()){
            return response()->json(["results"=>[]]);
        }

        $query = FittingPluginHelper::$FITTING_PLUGIN_DOCTRINE_MODEL::query();

        if($request->term){
            $query = $query->where("name","like","%$request->term%");
        }

        if($request->id){
            $query = $query->where("id",$request->id);
        }

        $suggestions = $query->get();

        $suggestions = $suggestions
            ->map(function ($doctrine){
                return [
                    'id' => $doctrine->id,
                    'text' => "$doctrine->name"
                ];
            });

        return response()->json([
            'results'=>$suggestions
        ]);
    }

    public function itemLookup(Request $request){
        $request->validate([
            "term"=>"nullable|string",
            "id"=>"nullable|integer"
        ]);

        $query = InvType::where("marketGroupID","!=",null);
        if ($request->term){
            $query = $query->where("typeName","like","%$request->term%");
        }
        if ($request->id){
            $query = $query->where("typeID",$request->id);
        }

        $suggestions = $query->limit(100)->get();

        $suggestions = $suggestions
            ->map(function ($item){
                return [
                    'id' => $item->typeID,
                    'text' => "$item->typeName"
                ];
            });

        return response()->json([
            'results'=>$suggestions
        ]);
    }

    public function fittingsLookup(Request $request){
        $request->validate([
            "term"=>"nullable|string",
            "id"=>"nullable|integer"
        ]);

        if(!FittingPluginHelper::pluginIsAvailable()){
            return response()->json(["results"=>[]]);
        }

        $query = FittingPluginHelper::$FITTING_PLUGIN_FITTING_MODEL::query();

        if($request->term){
            $query = $query->where("name","like","%$request->term%");
        }

        if($request->id){
            $query = $query->where("fitting_id",$request->id);
        }

        $suggestions = $query->get();
        $suggestions = $suggestions
            ->map(function ($fitting){
                return [
                    'id' => $fitting->fitting_id,
                    'text' => $fitting->name
                ];
            });

        return response()->json([
            'results'=>$suggestions
        ]);
    }

    public function itemBrowser(){
        return view("inventory::itembrowser");
    }

    public function itemBrowserData(Request $request){
        $request->validate([
            "location"=>"nullable|integer",
            "item"=>"nullable|integer",
            "page"=>"integer",
            "workspace"=>"required|integer"
        ]);

        $query = InventoryItem::with("source.location:id,name","type")
            ->join("seat_inventory_inventory_source","source_id","seat_inventory_inventory_source.id")
            ->where("seat_inventory_inventory_source.workspace_id","=","$request->workspace")
            ->orderBy("source_id")
            ->limit(100)
            ->offset($request->page*100);

        if($request->location){
            $query = $query->where("seat_inventory_inventory_source.location_id",$request->location);
        }

        if($request->item){
            $query = $query->where("type_id",$request->item);
        }

        $data = $query->get();

        return response()->json($data);
    }

    public function exportItems(Request $request){
        $request->validate([
            "stocks" => "required|array",
            "stocks.*" => "integer",
        ]);

        $items = StockItem::whereIn("stock_id",$request->stocks)
            ->select(sprintf("%s.*", StockItem::TABLE),DB::raw(sprintf("(%s.amount * %s.amount) as full_amount", StockItem::TABLE, Stock::TABLE)))
            ->join(Stock::TABLE,"stock_id","=",sprintf("%s.id", Stock::TABLE))
            ->get();

        $item_list = ItemEntryList::fromItemEntries($items);
        $item_list->simplify();

        $missing_item_list = StockItem::fromAlternativeAmountColumn($items,"missing_items");
        $missing_item_list->simplify();

        $all_item_list = StockItem::fromAlternativeAmountColumn($items,"full_amount");
        $all_item_list->simplify();

        return response()->json([
            "items"=>$item_list->asJsonStructure(),
            "missing_items"=>$missing_item_list->asJsonStructure(),
            "all"=>$all_item_list->asJsonStructure()
        ]);
    }

    public function changeStockOrder(Request $request)
    {
        $request->validate([
            "stock_id" => "required|integer",
            "target_id" => "required|integer",
            "category_id" => "required|integer",
            "before" => "required|boolean",
        ]);

        $target = StockCategoryMapping::where("stock_id",$request->target_id) // the drop target
            ->where("category_id", $request->category_id)
            ->first();

        if($target === null) return response()->json([],400);

        // step zero: unstack stacked list_orders by incrementing all stocks, except the target, with a list order >= the target
        DB::table($target->getTable())
            ->where("category_id", $request->category_id)
            ->where("list_order",">=", $target->list_order)
            ->where("stock_id","!=",$request->target_id)
            ->update([
                "list_order"=>DB::raw('list_order+1')
            ]);

        // step two: create space
        // there might be multiple stocks with the same list order, meaning we can't just move all stocks with list_order > target->list_order
        $compare_operation = $request->before?">=":">";
        DB::table($target->getTable())
            ->where("category_id", $request->category_id)
            ->where("list_order",$compare_operation, $target->list_order)
            ->update([
                "list_order"=>DB::raw('list_order+1')
            ]);

        // step three: update list
        DB::table($target->getTable())
            ->where("category_id", $request->category_id)
            ->where("stock_id",$request->stock_id)
            ->update([
                'list_order'=>$target->list_order + ($request->before ? 0 : 1)
            ]);

        return response()->json([]);
    }

    public function about(){
        return view("inventory::about");
    }

    public function stockIcon($id){
        $stock = Stock::findOrFail($id);

        return Image::make($stock->getIcon())->response();
    }

    public function orderItemsAllianceIndustry(Request $request){
        $request->validate([
            "items" => "required|string"
        ]);

        $data = json_decode($request->items, true);

        if(!AllianceIndustryPluginHelper::pluginIsAvailable() || $data === null){
            return redirect()->back();
        }

        $item_list = collect(array_map(function ($item_data){
            $item = EveItem::fromTypeID($item_data["type_id"]);
            $item->amount = $item_data["amount"];
            return $item;
        },$data["items"]));

        return AllianceIndustryPluginHelper::$API::create_orders([
            "items" => $item_list,
            "location" => $data["location"]
        ]);
    }
}