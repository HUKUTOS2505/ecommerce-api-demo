<?php
declare(strict_types=1);
namespace Demo;

final class Catalog
{
    public static function products(): array
    {
        $rows = [
            ['Canvas Carryall', 'accessories', 'A simple everyday tote with a roomy interior.', 'bag', ['Natural','Ink','Terracotta'], [3200,3400,3400], [8,4,0]],
            ['Studio Headphones', 'electronics', 'A fictional pair of over-ear headphones for the demo catalog.', 'headphones', ['Graphite','Sand','Mint'], [8900,8900,9400], [6,3,2]],
            ['Travel Bottle', 'accessories', 'An insulated bottle in three capacities.', 'bottle', ['350 ml','500 ml','750 ml'], [2400,2900,3500], [12,7,3]],
            ['Compact Keyboard', 'electronics', 'A compact desktop keyboard with three demo finishes.', 'keyboard', ['Slate','Cream','Forest'], [7500,7800,7800], [5,2,0]],
            ['Desk Lamp', 'workspace', 'A small adjustable light for a comfortable workspace.', 'lamp', ['White','Black','Sage'], [4900,4900,5400], [7,3,1]],
            ['Pocket Notebook', 'workspace', 'A notebook with three paper options.', 'notebook', ['Plain','Lined','Dotted'], [1200,1200,1400], [15,10,6]],
            ['Minimal Watch', 'accessories', 'A fictional everyday watch with interchangeable strap options.', 'watch', ['Navy','Brown','Black'], [6900,7200,6900], [4,2,1]],
            ['Wireless Speaker', 'electronics', 'A compact speaker used to demonstrate variant pricing.', 'speaker', ['Charcoal','Clay','Blue'], [5900,6200,5900], [6,0,2]],
            ['Desk Organizer', 'workspace', 'An organizer with separate spaces for daily essentials.', 'organizer', ['Small','Medium','Large'], [2200,3000,3800], [9,5,2]],
            ['Weekend Pouch', 'accessories', 'A compact pouch for cables and small travel essentials.', 'pouch', ['Olive','Stone','Orange'], [1800,1800,2100], [8,3,0]],
        ];
        $products = [];
        foreach ($rows as $i => [$name,$category,$description,$art,$labels,$prices,$stocks]) {
            $variants=[];
            foreach ($labels as $j=>$label) {
                $id=($i+1)*10+$j+1;
                $variants[]=['id'=>$id,'label'=>$label,'sku'=>'DEMO-'.($i+1).'-'.($j+1),'price_cents'=>$prices[$j],'stock'=>$stocks[$j]];
            }
            $products[]=['id'=>$i+1,'name'=>$name,'category'=>$category,'description'=>$description,'image'=>'/en/lab/ecommerce-api/assets/images/'.$art.'.svg','currency'=>'USD','variants'=>$variants];
        }
        return $products;
    }

    public static function initialState(): array
    {
        $stock=[];
        foreach(self::products() as $p) foreach($p['variants'] as $v) $stock[(string)$v['id']]=$v['stock'];
        return ['stock'=>$stock,'cart'=>[],'orders'=>[],'idempotency'=>[]];
    }
}
