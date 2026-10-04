"""Functional HTTP checks. Uses isolated cookie jars and Python's standard library."""
import json,sys,urllib.request,urllib.error,http.cookiejar,concurrent.futures

BASE=sys.argv[1].rstrip('/') if len(sys.argv)>1 else 'http://127.0.0.1:8080'
results=[]

class Client:
    def __init__(self):
        self.cookies=http.cookiejar.CookieJar()
        self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))
        self.csrf=''

    def request(self,method,path,payload=None,status=200,headers=None,raw=None,csrf=True):
        h=dict(headers or {})
        if method in ['POST','PATCH','DELETE'] and csrf:h['X-CSRF-Token']=self.csrf
        if payload is not None:
            raw=json.dumps(payload).encode();h.setdefault('Content-Type','application/json')
        request=urllib.request.Request(BASE+'/api/demo/v1'+path,data=raw,headers=h,method=method)
        try:response=self.opener.open(request,timeout=15)
        except urllib.error.HTTPError as e:response=e
        body=json.loads(response.read().decode())
        self.csrf=response.headers.get('X-CSRF-Token') or self.csrf
        assert response.status==status,(method,path,response.status,status,body)
        assert response.headers.get_content_type()=='application/json'
        assert response.headers.get('Cache-Control')=='no-store'
        assert response.headers.get('X-Content-Type-Options')=='nosniff'
        if status==405:assert response.headers.get('Allow')=='POST'
        if status>=400:
            assert set(body)=={'error'} and isinstance(body['error']['details'],dict)
            assert isinstance(body['error']['code'],str) and isinstance(body['error']['message'],str)
        else:assert 'data' in body
        results.append({'method':method,'path':path,'status':response.status,'error':body.get('error',{}).get('code')})
        return body

def cart_schema(cart):
    assert cart['currency']=='USD' and isinstance(cart['items'],list)
    assert cart['quantity']==sum(x['quantity'] for x in cart['items'])
    assert cart['total_cents']==sum(x['subtotal_cents'] for x in cart['items'])
    for x in cart['items']:
        assert all(isinstance(x[k],int) and not isinstance(x[k],bool) for k in ['id','product_id','variant_id','quantity','unit_price_cents','subtotal_cents','available_stock'])
        assert x['subtotal_cents']==x['quantity']*x['unit_price_cents']
        assert x['quantity']>0 and x['available_stock']>=x['quantity']

a=Client();b=Client()
catalog=a.request('GET','/products')['data'];assert len(catalog)==6
all_products=a.request('GET','/products?per_page=12&sort=price_asc')['data'];assert len(all_products)==10
assert [p['price_from_cents'] for p in all_products]==sorted(p['price_from_cents'] for p in all_products)
descending=a.request('GET','/products?per_page=12&sort=price_desc')['data']
assert [p['price_from_cents'] for p in descending]==sorted((p['price_from_cents'] for p in descending),reverse=True)
assert a.request('GET','/products?category=electronics')['meta']['total']==3
assert a.request('GET','/products?q=bottle')['meta']['total']==1
assert a.request('GET','/products?page=2')['meta']['page']==2
assert len(a.request('GET','/products?page=2')['data'])==4
assert a.request('GET','/products?in_stock=false')['data']==[]
assert len(a.request('GET','/products?in_stock=true&per_page=12')['data'])==10
p=a.request('GET','/products/1')['data'];assert len(p['variants'])==3 and p['variants'][0]['stock']==8
for path in ['/products?page=0','/products?per_page=13','/products?sort=unknown','/products?category=invalid','/products?in_stock=1','/products?q[]=x','/products?unexpected=1']:
    a.request('GET',path,status=422)
a.request('GET','/products/999999',status=404)
a.request('GET','/unknown',status=404)
a.request('GET','/products',status=403,headers={'Origin':'https://example.invalid'})
a.request('GET','/cart/items',status=405)
cart_schema(a.request('GET','/cart')['data']['cart'])
add={'product_id':1,'variant_id':11,'quantity':2}
a.request('POST','/cart/items',add,status=403,csrf=False)
for quantity in [0,-1,21,1.5,'1',True]:
    a.request('POST','/cart/items',{'product_id':1,'variant_id':11,'quantity':quantity},status=422)
a.request('POST','/cart/items',{'product_id':99999,'variant_id':11,'quantity':1},status=404)
a.request('POST','/cart/items',{'product_id':1,'variant_id':21,'quantity':1},status=422)
a.request('POST','/cart/items',{'product_id':1,'variant_id':13,'quantity':1},status=409)
a.request('POST','/cart/items',{'product_id':1,'variant_id':11,'quantity':9},status=409)
a.request('POST','/cart/items',status=400,raw=b'{',headers={'Content-Type':'application/json'})
a.request('POST','/cart/items',status=400,raw=b'[]',headers={'Content-Type':'application/json'})
a.request('POST','/cart/items',status=415,raw=b'hello',headers={'Content-Type':'text/plain'})
a.request('POST','/cart/items',status=413,raw=b'x'*8193,headers={'Content-Type':'application/json'})
a.request('POST','/cart/items',{**add,'price_cents':1},status=422)
cart=a.request('POST','/cart/items',add,status=201)['data']['cart'];cart_schema(cart);assert cart['total_cents']==6400
cart=a.request('POST','/cart/items',{'product_id':1,'variant_id':11,'quantity':1})['data']['cart'];assert cart['quantity']==3
cart=a.request('PATCH','/cart/items/11',{'quantity':4})['data']['cart'];cart_schema(cart);assert cart['total_cents']==12800
a.request('PATCH','/cart/items/11',{'quantity':9},status=409)
a.request('PATCH','/cart/items/11',{'quantity':0},status=422)
a.request('PATCH','/cart/items/999',{'quantity':1},status=404)
a.request('DELETE','/cart/items/999',status=404)
assert a.request('DELETE','/cart/items/11')['data']['cart']['items']==[]
order_body={'delivery_method':'demo_pickup','demo_acknowledged':True}
idem={'Idempotency-Key':'test-checkout-001'}
a.request('POST','/orders',order_body,status=409,headers=idem)
a.request('POST','/orders',{'delivery_method':'real_shipping','demo_acknowledged':True},status=422,headers=idem)
a.request('POST','/orders',{'delivery_method':'demo_pickup','demo_acknowledged':False},status=422,headers=idem)
a.request('POST','/orders',order_body,status=422)
a.request('POST','/cart/items',{'product_id':1,'variant_id':11,'quantity':8},status=201)
order=a.request('POST','/orders',order_body,status=201,headers=idem)['data']['order']
assert order['id']==1 and order['demo_only'] is True and order['total_cents']==25600
assert a.request('GET','/orders/1')['data']['order']==order
assert a.request('GET','/cart')['data']['cart']['items']==[]
assert a.request('GET','/products/1')['data']['variants'][0]['stock']==0
replay=a.request('POST','/orders',order_body,headers=idem)['data'];assert replay['replayed'] is True and replay['order']==order
assert a.request('POST','/orders',{'demo_acknowledged':True,'delivery_method':'demo_pickup'},headers=idem)['data']['order']==order
a.request('POST','/orders',{'delivery_method':'demo_shipping','demo_acknowledged':True},status=409,headers=idem)
a.request('GET','/orders/999',status=404)
assert b.request('GET','/products/1')['data']['variants'][0]['stock']==8
b.request('GET','/orders/1',status=404)
assert b.request('GET','/cart')['data']['cart']['items']==[]
assert a.request('POST','/reset',{})['data']['reset'] is True
assert a.request('GET','/products/1')['data']['variants'][0]['stock']==8
a.request('GET','/orders/1',status=404)
a.request('POST','/reset',{'unexpected':True},status=422)

# Retry safety is verified with two concurrent HTTP submissions using the same key.
c=Client();c.request('GET','/cart');c.request('POST','/cart/items',{'product_id':1,'variant_id':11,'quantity':1},status=201)
cookie='; '.join(f'{x.name}={x.value}' for x in c.cookies)
def checkout_once(_):
    req=urllib.request.Request(BASE+'/api/demo/v1/orders',data=json.dumps(order_body).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':c.csrf,'Cookie':cookie,'Idempotency-Key':'parallel-checkout-001'},method='POST')
    with urllib.request.urlopen(req,timeout=15) as r:return r.status,json.loads(r.read())
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:parallel=list(pool.map(checkout_once,range(2)))
assert sorted(x[0] for x in parallel)==[200,201]
assert parallel[0][1]['data']['order']==parallel[1][1]['data']['order']
assert c.request('GET','/products/1')['data']['variants'][0]['stock']==7
c.request('POST','/reset',{});b.request('POST','/reset',{})
print(json.dumps({'base':BASE,'checks':len(results),'parallel_checkout_statuses':sorted(x[0] for x in parallel),'all_passed':True,'results':results},indent=2))
