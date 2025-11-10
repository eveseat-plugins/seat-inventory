<?php

namespace RecursiveTree\Seat\Inventory\Jobs;

use ErrorException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Intervention\Image\Exception\NotReadableException;
use Intervention\Image\Facades\Image;
use RecursiveTree\Seat\Inventory\Helpers\DescribeItems;
use RecursiveTree\Seat\Inventory\Models\Stock;


class GenerateStockIcon implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, DescribeItems;

    private $stock_id;
    private $ship_type_id;

    private const ICON_SIZE = 512;

    public const TECH_LEVEL_BADGE_SIZE = 85;

    public function __construct($stock_id)
    {
        $this->stock_id = $stock_id;
    }

    public function tags()
    {
        return ["seat-inventory", "stock","icon"];
    }

    private function fetchEveImage($type_id){
        try {

            //query available image types
            $client = new Client([
                'timeout'  => 5.0,
            ]);
            $response = $client->request('GET', "https://images.evetech.net/types/$type_id");
            //decode request
            $data = json_decode($response->getBody());

            if(in_array("render",$data)){
                $image_type = "render";
            } else {
                $image_type = $data[0];
            }

            $image = Image::make("https://images.evetech.net/types/$type_id/$image_type?size=512");
            return $image->resize(self::ICON_SIZE,self::ICON_SIZE);

        } catch (NotReadableException | GuzzleException |  ErrorException $e){
            //could not fetch image, return null
        }

        return null;
    }

    public function handle()
    {
        $image_type = null;

        $stock = Stock::find($this->stock_id);
        if(!$stock) {
            $this->delete();
        }

        $description = $this->describeItemList($stock->items);
        $isBundle = $stock->bundle_size > 1;

        if($description->first()) {
            $image_type = $description->first()["item"];
        }

        $image = null;

        if($image_type){
            $image = $this->fetchEveImage($image_type->typeID);
        }

        if(!$image){
            $image = Image::canvas(self::ICON_SIZE,self::ICON_SIZE,"#eee");
        }

        $image = $image->rectangle(0, 427, self::ICON_SIZE, self::ICON_SIZE, function ($draw) {
            $draw->background('rgba(150, 150, 150, 0.3)');
        });

        if($isBundle) {
            $image = $image->polygon([
                self::ICON_SIZE - self::TECH_LEVEL_BADGE_SIZE, 0,
                self::ICON_SIZE, 0,
                self::ICON_SIZE, self::TECH_LEVEL_BADGE_SIZE
            ], function ($draw) {
                $draw->background('rgba(66, 135, 245, 0.5)');
            });
        }

        $image = $image->text($stock->name,16,448,function ($font){
            $font->file(__DIR__."/../resources/fonts/Roboto-Regular.ttf");
            $font->valign("top");
            $font->align("left");
            $font->size(48);
            $font->color([255, 255, 255, 1]);
        });
        if($isBundle) {
            $image = $image->text("B", self::ICON_SIZE-16, 16, function ($font) {
                $font->file(__DIR__ . "/../resources/fonts/Roboto-Regular.ttf");
                $font->valign("top");
                $font->align("right");
                $font->size(32);
                $font->color([255, 255, 255, 1]);
            });
        }

        $stock->setIcon($image);
        $stock->save();
    }
}