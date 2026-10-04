<?php
declare(strict_types=1);
namespace Demo;

final class Api
{
    public function __construct(private SandboxStore $store) {}

    public function dispatch(string $method,string $route): array
    {
        if(in_array($method,['POST','PATCH','DELETE'],true)) {
            if(!hash_equals($this->store->csrf,$_SERVER['HTTP_X_CSRF_TOKEN']??'')) throw new ApiError(403,'csrf_invalid','Fetch the cart first and supply its X-CSRF-Token response header.');
        }
        return $this->store->transaction(function(array &$state) use($method,$route):array {
            if($route==='/products'&&$method==='GET')return [200,$this->listProducts($state)];
            if(preg_match('#^/products/([1-9][0-9]*)$#D',$route,$m)&&$method==='GET')return [200,['data'=>$this->product((int)$m[1],$state)]];
            if($route==='/cart'&&$method==='GET')return [200,['data'=>['cart'=>$this->cart($state)]]];
            if($route==='/cart/items'&&$method==='POST') {
                $body=$this->body();$this->keys($body,['product_id','variant_id','quantity']);
                $product=$this->product($this->integer($body,'product_id'),$state);
                $variant=$this->variant($product,$this->integer($body,'variant_id'));
                $quantity=$this->integer($body,'quantity',1,20);$key=(string)$variant['id'];
                $existing=$state['cart'][$key]['quantity']??0;
                if($existing+$quantity>$variant['stock'])throw new ApiError(409,'insufficient_stock','Not enough stock for this variant.',['available_stock'=>$variant['stock'],'cart_quantity'=>$existing]);
                $state['cart'][$key]=['id'=>$variant['id'],'product_id'=>$product['id'],'variant_id'=>$variant['id'],'quantity'=>$existing+$quantity];
                return [$existing?200:201,['data'=>['cart'=>$this->cart($state)]]];
            }
            if(preg_match('#^/cart/items/([1-9][0-9]*)$#D',$route,$m)&&in_array($method,['PATCH','DELETE'],true)) {
                $key=$m[1];if(!isset($state['cart'][$key]))throw new ApiError(404,'cart_item_not_found','Cart item not found.');
                if($method==='DELETE')unset($state['cart'][$key]);
                else {
                    $body=$this->body();$this->keys($body,['quantity']);$quantity=$this->integer($body,'quantity',1,20);
                    if($quantity>$state['stock'][$key])throw new ApiError(409,'insufficient_stock','Not enough stock for this variant.',['available_stock'=>$state['stock'][$key]]);
                    $state['cart'][$key]['quantity']=$quantity;
                }
                return [200,['data'=>['cart'=>$this->cart($state)]]];
            }
            if($route==='/orders'&&$method==='POST') {
                $body=$this->body();$this->keys($body,['delivery_method','demo_acknowledged']);
                if(!in_array($body['delivery_method']??null,['demo_pickup','demo_shipping'],true)||($body['demo_acknowledged']??null)!==true)throw new ApiError(422,'validation_error','Choose a demo delivery method and acknowledge that no real order is placed.');
                $idempotency=$_SERVER['HTTP_IDEMPOTENCY_KEY']??'';
                if(!preg_match('/^[a-zA-Z0-9_-]{8,80}$/D',$idempotency))throw new ApiError(422,'validation_error','Supply an Idempotency-Key header with 8вЂ“80 letters, digits, underscores or hyphens.');
                $fingerprint=hash('sha256',json_encode(['delivery_method'=>$body['delivery_method'],'demo_acknowledged'=>true],JSON_THROW_ON_ERROR));
                if(isset($state['idempotency'][$idempotency])) {
                    $previous=$state['idempotency'][$idempotency];
                    if($previous['fingerprint']!==$fingerprint)throw new ApiError(409,'idempotency_conflict','This key was already used with a different request.');
                    return [200,['data'=>['order'=>$state['orders'][(string)$previous['id']],'replayed'=>true]]];
                }
                $cart=$this->cart($state);if(!$cart['items'])throw new ApiError(409,'empty_cart','Add an item before creating a demo order.');
                if(count($state['orders'])>=20)throw new ApiError(409,'order_limit','Reset your demo before creating more orders.');
                foreach($cart['items'] as $item)if($item['quantity']>$state['stock'][(string)$item['variant_id']])throw new ApiError(409,'insufficient_stock','Stock changed. Please update your cart.');
                $id=count($state['orders'])+1;
                $order=['id'=>$id,'reference'=>'DEMO-'.str_pad((string)$id,4,'0',STR_PAD_LEFT),'status'=>'demo_created','delivery_method'=>$body['delivery_method'],'currency'=>'USD','total_cents'=>$cart['total_cents'],'items'=>$cart['items'],'created_at'=>gmdate('c'),'demo_only'=>true];
                foreach($cart['items'] as $item)$state['stock'][(string)$item['variant_id']]-=$item['quantity'];
                $state['orders'][(string)$id]=$order;$state['idempotency'][$idempotency]=['id'=>$id,'fingerprint'=>$fingerprint];$state['cart']=[];
                return [201,['data'=>['order'=>$order,'replayed'=>false]]];
            }
            if(preg_match('#^/orders/([1-9][0-9]*)$#D',$route,$m)&&$method==='GET') {
                if(!isset($state['orders'][$m[1]]))throw new ApiError(404,'order_not_found','This order does not exist in your sandbox.');
                return [200,['data'=>['order'=>$state['orders'][$m[1]]]]];
            }
            if($route==='/reset'&&$method==='POST'){$body=$this->body();$this->keys($body,[]);$state=Catalog::initialState();return [200,['data'=>['reset'=>true,'cart'=>$this->cart($state)]]];}
            $known=preg_match('#^/(products(?:/[1-9][0-9]*)?|cart(?:/items(?:/[1-9][0-9]*)?)?|orders(?:/[1-9][0-9]*)?|reset)$#D',$route);
            if($known) {
                $allowed=$route==='/cart/items'||$route==='/orders'||$route==='/reset'?['POST']:(str_starts_with($route,'/cart/items/')?['PATCH','DELETE']:['GET']);
                throw new ApiError(405,'method_not_allowed','This HTTP method is not supported for this endpoint.',['allowed_methods'=>$allowed]);
            }
            throw new ApiError(404,'endpoint_not_found','API endpoint not found.');
        });
    }

    private function listProducts(array $state): array
    {
        $allowed=['q','category','in_stock','sort','page','per_page'];
        foreach($_GET as $key=>$value)if(!in_array($key,$allowed,true)||!is_string($value))throw new ApiError(422,'validation_error','Unknown or invalid query parameter.',['parameter'=>$key]);
        $q=trim($_GET['q']??'');if(strlen($q)>80)throw new ApiError(422,'validation_error','Search is limited to 80 characters.');
        $category=$_GET['category']??'';if(!in_array($category,['','accessories','electronics','workspace'],true))throw new ApiError(422,'validation_error','Unknown category.');
        $inStock=$_GET['in_stock']??'';if(!in_array($inStock,['','true','false'],true))throw new ApiError(422,'validation_error','in_stock must be true or false.');
        $sort=$_GET['sort']??'name_asc';if(!in_array($sort,['name_asc','price_asc','price_desc'],true))throw new ApiError(422,'validation_error','Unsupported sort order.');
        $page=$this->queryInt('page',1,1,100);$perPage=$this->queryInt('per_page',6,1,12);
        $products=[];
        foreach(Catalog::products() as $p) {
            $p=$this->product($p['id'],$state);
            if($category&&$p['category']!==$category)continue;
            if($q&&stripos($p['name'].' '.$p['description'],$q)===false)continue;
            if($inStock==='true'&&$p['total_stock']===0)continue;
            if($inStock==='false'&&$p['total_stock']!==0)continue;
            $products[]=$p;
        }
        usort($products,fn($a,$b)=>$sort==='name_asc'?strcmp($a['name'],$b['name']):(($sort==='price_asc'?$a['price_from_cents']<=>$b['price_from_cents']:$b['price_from_cents']<=>$a['price_from_cents'])?:$a['id']<=>$b['id']));
        $total=count($products);
        return ['data'=>array_slice($products,($page-1)*$perPage,$perPage),'meta'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'total_pages'=>(int)ceil($total/$perPage),'sort'=>$sort]];
    }

    private function product(int $id,array $state): array
    {
        foreach(Catalog::products() as $p)if($p['id']===$id) {
            foreach($p['variants'] as &$v)$v['stock']=$state['stock'][(string)$v['id']];unset($v);
            $p['price_from_cents']=min(array_column($p['variants'],'price_cents'));
            $p['total_stock']=array_sum(array_column($p['variants'],'stock'));return $p;
        }
        throw new ApiError(404,'product_not_found','Product not found.');
    }

    private function variant(array $product,int $id): array
    {
        foreach($product['variants'] as $v)if($v['id']===$id)return $v;
        throw new ApiError(422,'variant_invalid','The variant does not belong to this product.');
    }

    private function cart(array $state): array
    {
        $items=[];$total=0;$quantity=0;
        foreach($state['cart'] as $row) {
            $p=$this->product($row['product_id'],$state);$v=$this->variant($p,$row['variant_id']);
            $subtotal=$v['price_cents']*$row['quantity'];$total+=$subtotal;$quantity+=$row['quantity'];
            $items[]=$row+['product_name'=>$p['name'],'variant_label'=>$v['label'],'unit_price_cents'=>$v['price_cents'],'subtotal_cents'=>$subtotal,'available_stock'=>$v['stock']];
        }
        return ['items'=>$items,'quantity'=>$quantity,'total_cents'=>$total,'currency'=>'USD'];
    }

    private function body(): array
    {
        if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')throw new ApiError(415,'content_type','Use Content-Type: application/json.');
        $raw=file_get_contents('php://input',false,null,0,8193);
        if(strlen($raw)>8192)throw new ApiError(413,'payload_too_large','The demo accepts at most 8 KB per request.');
        try{$body=json_decode($raw,false,20,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new ApiError(400,'invalid_json','Request body must be valid JSON.');}
        if(!$body instanceof \stdClass)throw new ApiError(400,'invalid_json','Request body must be a JSON object.');
        return (array)$body;
    }

    private function keys(array $body,array $allowed): void
    {
        foreach(array_keys($body) as $key)if(!in_array($key,$allowed,true))throw new ApiError(422,'validation_error','Unexpected input field.',['field'=>$key]);
    }

    private function integer(array $body,string $key,int $min=1,int $max=100000): int
    {
        $v=$body[$key]??null;if(!is_int($v)||$v<$min||$v>$max)throw new ApiError(422,'validation_error',"$key must be an integer between $min and $max.",['field'=>$key]);return $v;
    }

    private function queryInt(string $key,int $default,int $min,int $max): int
    {
        if(!isset($_GET[$key]))return $default;
        if(!preg_match('/^[1-9][0-9]*$/D',$_GET[$key]))throw new ApiError(422,'validation_error',"$key must be a positive integer.");
        $v=(int)$_GET[$key];if($v<$min||$v>$max)throw new ApiError(422,'validation_error',"$key must be between $min and $max.");return $v;
    }
}
