<?php
require __DIR__.'/config.php';
try {
  $pdo=db(); $w=["status='Active'"]; $a=[];
  if(!empty($_GET['id'])){$w[]='fg_id=?';$a[]=(int)$_GET['id'];}
  if(!empty($_GET['ids'])){$ids=array_values(array_filter(array_map('intval',explode(',',$_GET['ids'])))); if(!$ids)respond(['currency'=>CURRENCY,'products'=>[],'categories'=>[]]);$w[]='fg_id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$a=array_merge($a,$ids);}
  if(!empty($_GET['category'])){$w[]="LOWER(REPLACE(REPLACE(brand,' ','-'),'&','and'))=?";$a[]=$_GET['category'];}
  foreach(preg_split('/\s+/',trim($_GET['q']??''),-1,PREG_SPLIT_NO_EMPTY) as $word){$w[]='(item_name LIKE ? OR item_code LIKE ? OR brand LIKE ?)';array_push($a,"%$word%","%$word%","%$word%");}
  // Keep the STRIDE storefront's filters; inventory products do not have gender/sale/new flags.
  $sort=['price_asc'=>'item_name ASC','price_desc'=>'item_name DESC','newest'=>'fg_id DESC'][$_GET['sort']??'']??'item_name ASC';
  $limit=isset($_GET['limit'])?max(1,min(60,(int)$_GET['limit'])):60;
  $s=$pdo->prepare('SELECT fg_id,item_code,item_name,brand,size,unit,quantity,description,status FROM inventory_system.finished_goods WHERE '.implode(' AND ',$w).' ORDER BY '.$sort.' LIMIT '.$limit);$s->execute($a);$rows=$s->fetchAll();
  $products=[];
  foreach($rows as $r){$id=(int)$r['fg_id'];$code=(string)$r['item_code'];$safe=preg_match('/^[A-Za-z0-9_-]+$/',$code)?$code:'';$image=$safe && is_file(__DIR__.'/../assets/shoes/cut/'.$safe.'.svg')?'assets/shoes/cut/'.$safe.'.svg':'assets/shoes/cut/RUN-001.svg';
    $products[]=['id'=>$id,'sku'=>$code,'name'=>$r['item_name'],'description'=>$r['description']??('Brand: '.($r['brand']??'').' · Size: '.($r['size']??'').' · Unit: '.($r['unit']??'')),'price'=>0,'sale_price'=>null,'gender'=>'all','is_new'=>false,'image_url'=>$image,'category'=>$r['brand']?:'Products','category_slug'=>'products','brand'=>$r['brand'],'size'=>$r['size'],'unit'=>$r['unit'],'variants'=>[['id'=>$id,'size'=>trim(($r['size']??'').' '.($r['unit']??'')) ?: 'Standard','stock'=>999999]]];
  }
  $cats=$pdo->query("SELECT DISTINCT brand name, LOWER(REPLACE(REPLACE(brand,' ','-'),'&','and')) slug FROM inventory_system.finished_goods WHERE status='Active' AND brand IS NOT NULL AND brand<>'' ORDER BY brand")->fetchAll();
  respond(['currency'=>CURRENCY,'products'=>$products,'categories'=>$cats]);
} catch(Throwable $e){ respond(['error'=>'Could not load products. Check the Inventory System database connection and finished_goods table.'],500); }
