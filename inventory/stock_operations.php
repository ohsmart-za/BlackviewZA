<?php
// ============================================================
// Blackview SA Portal — Stock Operations (Scan In / Move / Take Out)
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/csv_helpers.php';

requireLogin();

// ---- CSV template downloads ----
if (isset($_GET['dl_template'])) {
    switch ($_GET['dl_template']) {
        case 'scan_in':
            csvTemplateDownload('scan_in_template.csv', ['product_sku','warehouse_name','serial_no','qty'],
                [['BV-A95-256','Head Office','SN123456789',''],['BV-CASE-01','Head Office','','10']]); exit;
        case 'move_stock':
            csvTemplateDownload('move_stock_template.csv',
                ['product_sku','from_warehouse','to_warehouse','channel','invoice_no','serial_no','notes'],
                [['BV-A95-256','Head Office','Takealot Warehouse','transfer','','SN123456789','optional']]); exit;
        case 'take_out':
            csvTemplateDownload('take_out_template.csv',
                ['product_sku','warehouse_name','channel','invoice_no','serial_no','qty','notes'],
                [['BV-A95-256','Head Office','instore','INV-001','SN123','',''],
                 ['BV-CASE-01','Head Office','other','','','5','write-off']]); exit;
    }
}

$pdo       = getDB();
$pageTitle = 'Stock Operations';

// ============================================================
// AJAX
// ============================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    switch ($_GET['ajax']) {

        case 'search_product':
            $q = trim($_GET['q'] ?? '');
            $exactOnly = !empty($_GET['exact']);
            if ($q === '') { echo json_encode([]); exit; }
            // Try exact barcode or SKU match first
            $exactQ = $pdo->prepare("SELECT id,sku,barcode,name,COALESCE(is_serialised,1) AS is_serialised FROM products WHERE (barcode=:q OR sku=:q2) AND is_active=1 AND COALESCE(product_type,'physical')='physical' LIMIT 1");
            $exactQ->execute([':q'=>$q,':q2'=>$q]);
            $res = $exactQ->fetchAll(PDO::FETCH_ASSOC);
            if (empty($res) && !$exactOnly) {
                $like = $pdo->prepare("SELECT id,sku,barcode,name,COALESCE(is_serialised,1) AS is_serialised FROM products WHERE (name LIKE :q OR sku LIKE :q2 OR barcode LIKE :q3) AND is_active=1 AND COALESCE(product_type,'physical')='physical' ORDER BY name LIMIT 10");
                $like->execute([':q'=>'%'.$q.'%',':q2'=>'%'.$q.'%',':q3'=>'%'.$q.'%']);
                $res = $like->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($res as &$r) $r['is_serialised']=(int)$r['is_serialised']; unset($r);
            echo json_encode($res); exit;

        case 'check_serial':
            $sn = trim($_GET['sn'] ?? '');
            if ($sn==='') { echo json_encode(['exists'=>false]); exit; }
            $s = $pdo->prepare("SELECT serial_no FROM stock_items WHERE serial_no=:sn LIMIT 1");
            $s->execute([':sn'=>$sn]);
            echo json_encode(['exists'=>(bool)$s->fetch()]); exit;

        case 'lookup_serial':
            $sn = trim($_GET['sn'] ?? '');
            if ($sn==='') { echo json_encode(['found'=>false]); exit; }
            $s = $pdo->prepare(
                "SELECT si.serial_no,si.warehouse_id,w.name AS warehouse_name,
                        p.id AS product_id,p.name AS product_name,p.sku,
                        COALESCE(p.is_serialised,1) AS is_serialised
                 FROM stock_items si
                 JOIN products p ON p.id=si.product_id
                 JOIN warehouses w ON w.id=si.warehouse_id
                 WHERE si.serial_no=:sn AND si.status='in_stock' LIMIT 1");
            $s->execute([':sn'=>$sn]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            echo $row ? json_encode(['found'=>true,'serial_no'=>$row['serial_no'],
                'product_id'=>$row['product_id'],'product_name'=>$row['product_name'],
                'sku'=>$row['sku'],'warehouse_id'=>$row['warehouse_id'],
                'warehouse_name'=>$row['warehouse_name'],'is_serialised'=>(int)$row['is_serialised']])
                : json_encode(['found'=>false,'message'=>'Serial not found or not in stock.']);
            exit;
    }
    exit;
}

// ============================================================
// Data
// ============================================================
$products   = $pdo->query("SELECT id,sku,name,COALESCE(is_serialised,1) AS is_serialised FROM products WHERE is_active=1 AND COALESCE(product_type,'physical')='physical' ORDER BY name")->fetchAll();
$warehouses = $pdo->query('SELECT id,name FROM warehouses WHERE is_active=1 ORDER BY name')->fetchAll();
$openPOs    = $pdo->query(
    "SELECT po.id,po.po_number,s.name AS supplier_name,w.name AS warehouse_name,w.id AS warehouse_id
     FROM purchase_orders po LEFT JOIN suppliers s ON s.id=po.supplier_id LEFT JOIN warehouses w ON w.id=po.warehouse_id
     WHERE po.status NOT IN ('cancelled','received') ORDER BY po.created_at DESC")->fetchAll();

$productById = [];
foreach ($products as $p) $productById[$p['id']] = $p;

$validMoveChannels    = ['takealot','makro','instore','email','transfer','other'];
$validTakeOutChannels = ['takealot','makro','instore','email','other'];

// ============================================================
// POST: Scan In
// ============================================================
$scanErrors  = []; $scanSuccess = false; $scanSummary = [];
$csvImported = 0;  $csvErrors   = []; $csvShowResult = false;

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['csv_action']??'')==='scan_in') {
    $csvShowResult = true;
    $parsed = parseUploadedCsv($_FILES['csv_file']??[]);
    if ($parsed['error']!==null) { $csvErrors[]=$parsed['error']; }
    else {
        $missing = array_diff(['product_sku','warehouse_name'],$parsed['headers']);
        if (!empty($missing)) $csvErrors[]='CSV missing: '.implode(', ',$missing);
    }
    $productBySku=[]; $warehouseByName=[];
    foreach ($products as $p) $productBySku[strtolower(trim($p['sku']))]=$p;
    foreach ($warehouses as $w) $warehouseByName[strtolower(trim($w['name']))]=$w;
    if (empty($csvErrors)) {
        $serialRows=[]; $bulkRows=[]; $rowErrors=[]; $seen=[];
        foreach ($parsed['rows'] as $idx=>$row) {
            $rn=$idx+2; $sku=trim($row['product_sku']??''); $wh=trim($row['warehouse_name']??'');
            $sn=trim($row['serial_no']??''); $qty=trim($row['qty']??'');
            if ($sku===''&&$wh===''&&$sn===''&&$qty==='') continue;
            $prod=$productBySku[strtolower($sku)]??null; $whouse=$warehouseByName[strtolower($wh)]??null;
            if (!$prod)   { $rowErrors[]="Row $rn: SKU \"$sku\" not found."; continue; }
            if (!$whouse) { $rowErrors[]="Row $rn: Warehouse \"$wh\" not found."; continue; }
            if ((int)$prod['is_serialised']) {
                if ($sn==='') { $rowErrors[]="Row $rn: serial required."; }
                elseif (isset($seen[$sn])) { $rowErrors[]="Row $rn: serial \"$sn\" duplicated."; }
                else { $seen[$sn]=true; $serialRows[]=['product'=>$prod,'warehouse'=>$whouse,'serial'=>$sn]; }
            } else {
                $q2=(int)$qty; if ($q2<=0) { $rowErrors[]="Row $rn: qty required."; continue; }
                $k=$prod['id'].'_'.$whouse['id'];
                if (!isset($bulkRows[$k])) $bulkRows[$k]=['product'=>$prod,'warehouse'=>$whouse,'qty'=>0];
                $bulkRows[$k]['qty']+=$q2;
            }
        }
        if (!empty($serialRows)) {
            $inSql=implode(',',array_fill(0,count($serialRows),'?'));
            $chk=$pdo->prepare("SELECT serial_no FROM stock_items WHERE serial_no IN ($inSql)");
            $chk->execute(array_column($serialRows,'serial'));
            $ex=array_flip($chk->fetchAll(PDO::FETCH_COLUMN)); $clean=[];
            foreach ($serialRows as $sr) { if (isset($ex[$sr['serial']])) $rowErrors[]='Serial "'.$sr['serial'].'" already exists — skipped.'; else $clean[]=$sr; }
            $serialRows=$clean;
        }
        $csvErrors=array_merge($csvErrors,$rowErrors);
        if (!empty($serialRows)||!empty($bulkRows)) {
            try {
                $pdo->beginTransaction();
                $ins=$pdo->prepare('INSERT INTO stock_items (product_id,warehouse_id,serial_no,status,created_at) VALUES (:pid,:wid,:sn,"in_stock",NOW())');
                $up=$pdo->prepare('INSERT INTO inventory_stock (product_id,warehouse_id,qty,updated_at) VALUES (:pid,:wid,:qty,NOW()) ON DUPLICATE KEY UPDATE qty=qty+:qty2,updated_at=NOW()');
                $cq=[]; $cm=[];
                foreach ($serialRows as $sr) {
                    $ins->execute([':pid'=>$sr['product']['id'],':wid'=>$sr['warehouse']['id'],':sn'=>$sr['serial']]);
                    $k=$sr['product']['id'].'_'.$sr['warehouse']['id']; $cq[$k]=($cq[$k]??0)+1; $cm[$k]=['pid'=>$sr['product']['id'],'wid'=>$sr['warehouse']['id']];
                }
                foreach ($cq as $k=>$q) $up->execute([':pid'=>$cm[$k]['pid'],':wid'=>$cm[$k]['wid'],':qty'=>$q,':qty2'=>$q]);
                foreach ($bulkRows as $br) $up->execute([':pid'=>$br['product']['id'],':wid'=>$br['warehouse']['id'],':qty'=>$br['qty'],':qty2'=>$br['qty']]);
                $csvImported=count($serialRows)+array_sum(array_column($bulkRows,'qty'));
                logAudit($pdo,'csv_scan_in','stock_items',null,"CSV: $csvImported unit(s) scanned in.");
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); $csvErrors[]='DB error: '.$e->getMessage(); }
        }
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='scan_in_items') {
    $warehouseId=(int)($_POST['warehouse_id']??0); $sourceType=trim($_POST['source_type']??'manual');
    $linkedPoId=(int)($_POST['po_id']??0); $sourceRef=trim($_POST['source_ref']??'');
    $itemProductIds=array_map('intval',$_POST['item_product_id']??[]);
    $itemSerials=$_POST['item_serial_no']??[]; $itemQtys=array_map('intval',$_POST['item_qty']??[]);
    if ($warehouseId<=0) $scanErrors[]='Please select a warehouse.';
    if (empty($itemProductIds)) $scanErrors[]='No items added.';
    $poData=null;
    if ($sourceType==='po') {
        if ($linkedPoId<=0) { $scanErrors[]='Please select a PO.'; }
        else {
            $pc=$pdo->prepare("SELECT id,po_number FROM purchase_orders WHERE id=:id AND status NOT IN ('cancelled','received') LIMIT 1");
            $pc->execute([':id'=>$linkedPoId]); $poData=$pc->fetch();
            if (!$poData) $scanErrors[]='Selected PO is invalid.';
        }
    }
    $items=[];
    foreach ($itemProductIds as $i=>$pid) {
        if ($pid<=0) continue;
        $prod=$productById[$pid]??null; if (!$prod) continue;
        $serial=trim($itemSerials[$i]??''); $qty=max(1,$itemQtys[$i]??1);
        $items[]=['product'=>$prod,'serial'=>(int)$prod['is_serialised']?$serial:'','qty'=>(int)$prod['is_serialised']?1:$qty,'is_serialised'=>(int)$prod['is_serialised']];
    }
    if (empty($items)&&empty($scanErrors)) $scanErrors[]='No valid items.';
    if (empty($scanErrors)) {
        $sns=array_filter(array_column($items,'serial'));
        if (!empty($sns)) {
            $inSql=implode(',',array_fill(0,count($sns),'?'));
            $dc=$pdo->prepare("SELECT serial_no FROM stock_items WHERE serial_no IN ($inSql)");
            $dc->execute(array_values($sns)); $ex=$dc->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($ex)) $scanErrors[]='Already in system: '.implode(', ',$ex);
        }
    }
    if (empty($scanErrors)) {
        try {
            $pdo->beginTransaction();
            $poId=($sourceType==='po'&&$linkedPoId>0)?$linkedPoId:null;
            $ins=$pdo->prepare('INSERT INTO stock_items (product_id,warehouse_id,po_id,serial_no,status,created_at) VALUES (:pid,:wid,:po,:sn,"in_stock",NOW())');
            $up=$pdo->prepare('INSERT INTO inventory_stock (product_id,warehouse_id,qty,updated_at) VALUES (:pid,:wid,:qty,NOW()) ON DUPLICATE KEY UPDATE qty=qty+:qty2,updated_at=NOW()');
            $combo=[]; $sAdded=[];
            foreach ($items as $item) {
                $pid=$item['product']['id'];
                if ($item['is_serialised']) { $ins->execute([':pid'=>$pid,':wid'=>$warehouseId,':po'=>$poId,':sn'=>$item['serial']]); $sAdded[]=$item['serial']; }
                $k=$pid.'_'.$warehouseId; $combo[$k]=($combo[$k]??['pid'=>$pid,'wid'=>$warehouseId,'qty'=>0]); $combo[$k]['qty']+=$item['qty'];
            }
            foreach ($combo as $c) $up->execute([':pid'=>$c['pid'],':wid'=>$c['wid'],':qty'=>$c['qty'],':qty2'=>$c['qty']]);
            if ($poId) {
                $pq=[]; foreach ($items as $item) $pq[$item['product']['id']]=($pq[$item['product']['id']]??0)+$item['qty'];
                $pl=$pdo->prepare("UPDATE purchase_order_items SET qty_received=qty_received+:n WHERE po_id=:po AND product_id=:prod");
                foreach ($pq as $pid=>$n) $pl->execute([':n'=>$n,':po'=>$poId,':prod'=>$pid]);
                $pt=$pdo->prepare("SELECT SUM(qty_ordered) AS ord,SUM(qty_received) AS rec FROM purchase_order_items WHERE po_id=:po");
                $pt->execute([':po'=>$poId]); $tot=$pt->fetch();
                $pdo->prepare("UPDATE purchase_orders SET status=:s WHERE id=:id")->execute([':s'=>((int)$tot['rec']>=(int)$tot['ord']?'received':'partial'),':id'=>$poId]);
            }
            $whName=''; foreach ($warehouses as $w) { if ($w['id']==$warehouseId) $whName=$w['name']; }
            $totalQty=array_sum(array_column($items,'qty'));
            $srcDesc=$poData?"PO #{$poData['po_number']}":('Manual — '.($sourceRef?:'no ref'));
            logAudit($pdo,'scan_in','stock_items',null,"Scanned in $totalQty unit(s) into \"$whName\". Source: $srcDesc.".(!empty($sAdded)?' Serials: '.implode(', ',$sAdded):''));
            $pdo->commit();
            $scanSummary=['qty'=>$totalQty,'warehouse'=>$whName,'source'=>$srcDesc,'items'=>$items]; $scanSuccess=true;
        } catch (Throwable $e) { $pdo->rollBack(); $scanErrors[]='DB error: '.$e->getMessage(); }
    }
}

// ============================================================
// POST: Move Stock
// ============================================================
$moveErrors=[]; $moveSuccess=false; $moveSummary=[];
$moveCsvImported=0; $moveCsvErrors=[]; $moveCsvShow=false;

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['csv_action']??'')==='move_stock') {
    $moveCsvShow=true;
    $productBySku=[]; $warehouseByName=[];
    foreach ($products as $p) $productBySku[strtolower(trim($p['sku']))]=$p;
    foreach ($warehouses as $w) $warehouseByName[strtolower(trim($w['name']))]=$w;
    $parsed=parseUploadedCsv($_FILES['csv_file']??[]);
    if ($parsed['error']!==null) { $moveCsvErrors[]=$parsed['error']; }
    else {
        $req=['product_sku','from_warehouse','to_warehouse','channel','serial_no'];
        $miss=array_diff($req,$parsed['headers']);
        if (!empty($miss)) $moveCsvErrors[]='Missing columns: '.implode(', ',$miss);
    }
    if (empty($moveCsvErrors)) {
        $validRows=[]; $rowErrors=[]; $seen=[];
        foreach ($parsed['rows'] as $idx=>$row) {
            $rn=$idx+2; $sku=trim($row['product_sku']??''); $fr=trim($row['from_warehouse']??'');
            $to=trim($row['to_warehouse']??''); $ch=trim($row['channel']??'');
            $sn=trim($row['serial_no']??''); $inv=trim($row['invoice_no']??''); $notes=trim($row['notes']??'');
            if ($sku===''&&$fr===''&&$to===''&&$sn==='') continue;
            $prod=$productBySku[strtolower($sku)]??null; $fromW=$warehouseByName[strtolower($fr)]??null; $toW=$warehouseByName[strtolower($to)]??null;
            $ok=true;
            if (!$prod) { $rowErrors[]="Row $rn: SKU not found."; $ok=false; }
            if (!$fromW) { $rowErrors[]="Row $rn: from-warehouse not found."; $ok=false; }
            if (!$toW)   { $rowErrors[]="Row $rn: to-warehouse not found."; $ok=false; }
            if ($fromW&&$toW&&$fromW['id']===$toW['id']) { $rowErrors[]="Row $rn: same warehouse."; $ok=false; }
            if (!in_array(strtolower($ch),$validMoveChannels,true)) { $rowErrors[]="Row $rn: invalid channel."; $ok=false; }
            if ($sn==='') { $rowErrors[]="Row $rn: serial empty."; $ok=false; }
            elseif (isset($seen[$sn])) { $rowErrors[]="Row $rn: serial duplicated."; $ok=false; }
            else $seen[$sn]=true;
            if ($ok) $validRows[]=['product'=>$prod,'fromWarehouse'=>$fromW,'toWarehouse'=>$toW,'channel'=>strtolower($ch),'invoice_no'=>$inv,'serial'=>$sn,'notes'=>$notes];
        }
        if (!empty($validRows)) {
            $clean=[];
            foreach ($validRows as $vr) {
                $c=$pdo->prepare("SELECT id FROM stock_items WHERE serial_no=:sn AND product_id=:pid AND warehouse_id=:wid AND status='in_stock' LIMIT 1");
                $c->execute([':sn'=>$vr['serial'],':pid'=>$vr['product']['id'],':wid'=>$vr['fromWarehouse']['id']]);
                if ($c->fetch()) $clean[]=$vr; else $rowErrors[]='Serial "'.$vr['serial'].'" not in_stock at "'.$vr['fromWarehouse']['name'].'" — skipped.';
            }
            $validRows=$clean;
        }
        $moveCsvErrors=array_merge($moveCsvErrors,$rowErrors);
        if (!empty($validRows)) {
            $groups=[];
            foreach ($validRows as $vr) {
                $k=implode('|',[$vr['product']['id'],$vr['fromWarehouse']['id'],$vr['toWarehouse']['id'],$vr['channel'],$vr['invoice_no']]);
                if (!isset($groups[$k])) $groups[$k]=['product'=>$vr['product'],'fromWarehouse'=>$vr['fromWarehouse'],'toWarehouse'=>$vr['toWarehouse'],'channel'=>$vr['channel'],'invoice_no'=>$vr['invoice_no'],'notes'=>$vr['notes'],'serials'=>[]];
                $groups[$k]['serials'][]=$vr['serial'];
            }
            try {
                $pdo->beginTransaction();
                $im=$pdo->prepare('INSERT INTO stock_movements (product_id,from_warehouse_id,to_warehouse_id,qty,moved_by,invoice_no,channel,notes,moved_at) VALUES (:pid,:fw,:tw,:qty,:uid,:inv,:ch,:notes,NOW())');
                $ms=$pdo->prepare('INSERT INTO movement_serials (movement_id,serial_no) VALUES (:mid,:sn)');
                $us=$pdo->prepare("UPDATE stock_items SET warehouse_id=:wid,status='in_stock' WHERE serial_no=:sn");
                $dc=$pdo->prepare('INSERT INTO inventory_stock (product_id,warehouse_id,qty,updated_at) VALUES (:pid,:wid,0,NOW()) ON DUPLICATE KEY UPDATE qty=GREATEST(0,qty-:qty),updated_at=NOW()');
                $ic=$pdo->prepare('INSERT INTO inventory_stock (product_id,warehouse_id,qty,updated_at) VALUES (:pid,:wid,:qty,NOW()) ON DUPLICATE KEY UPDATE qty=qty+:qty2,updated_at=NOW()');
                $tot=0;
                foreach ($groups as $g) {
                    $qty=count($g['serials']);
                    $im->execute([':pid'=>$g['product']['id'],':fw'=>$g['fromWarehouse']['id'],':tw'=>$g['toWarehouse']['id'],':qty'=>$qty,':uid'=>$_SESSION['user_id'],':inv'=>$g['invoice_no'],':ch'=>$g['channel'],':notes'=>$g['notes']]);
                    $mid=(int)$pdo->lastInsertId();
                    foreach ($g['serials'] as $sn) { $ms->execute([':mid'=>$mid,':sn'=>$sn]); $us->execute([':wid'=>$g['toWarehouse']['id'],':sn'=>$sn]); }
                    $dc->execute([':pid'=>$g['product']['id'],':wid'=>$g['fromWarehouse']['id'],':qty'=>$qty]);
                    $ic->execute([':pid'=>$g['product']['id'],':wid'=>$g['toWarehouse']['id'],':qty'=>$qty,':qty2'=>$qty]);
                    logAudit($pdo,'csv_move_stock','stock_movements',$mid,"CSV: moved $qty x \"{$g['product']['name']}\" → \"{$g['toWarehouse']['name']}\" via {$g['channel']}.");
                    $tot+=$qty;
                }
                $pdo->commit(); $moveCsvImported=$tot;
            } catch (Throwable $e) { $pdo->rollBack(); $moveCsvErrors[]='DB error: '.$e->getMessage(); }
        }
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='move_items') {
    $toWid=(int)($_POST['to_warehouse_id']??0); $ch=trim($_POST['move_channel']??'');
    $inv=trim($_POST['move_invoice_no']??''); $notes=trim($_POST['move_notes']??'');
    $ipids=array_map('intval',$_POST['item_product_id']??[]);
    $isns=$_POST['item_serial_no']??[]; $ifwids=array_map('intval',$_POST['item_from_warehouse_id']??[]);
    $iqtys=array_map('intval',$_POST['item_qty']??[]); $iiss=array_map('intval',$_POST['item_is_serialised']??[]);
    if ($toWid<=0) $moveErrors[]='Select a destination warehouse.';
    if (!in_array($ch,$validMoveChannels,true)) $moveErrors[]='Select a channel.';
    if (empty($ipids)) $moveErrors[]='No items added.';
    $mitems=[];
    foreach ($ipids as $i=>$pid) {
        if ($pid<=0) continue;
        $sn=trim($isns[$i]??''); $fwid=$ifwids[$i]??0; $qty=max(1,$iqtys[$i]??1); $isSer=$iiss[$i]??1;
        if ($fwid==$toWid) { $moveErrors[]="Item ".($sn?:"#".($i+1)).": source and destination are the same warehouse."; continue; }
        $mitems[]=['pid'=>$pid,'serial'=>$sn,'from_wid'=>$fwid,'qty'=>$qty,'is_serialised'=>$isSer];
    }
    if (empty($mitems)&&empty($moveErrors)) $moveErrors[]='No valid items.';
    if (empty($moveErrors)) {
        foreach ($mitems as $mi) {
            if ($mi['is_serialised']&&$mi['serial']!=='') {
                $c=$pdo->prepare("SELECT id FROM stock_items WHERE serial_no=:sn AND warehouse_id=:wid AND status='in_stock' LIMIT 1");
                $c->execute([':sn'=>$mi['serial'],':wid'=>$mi['from_wid']]);
                if (!$c->fetch()) $moveErrors[]="Serial {$mi['serial']} is no longer in stock.";
            }
        }
    }
    if (empty($moveErrors)) {
        try {
            $pdo->beginTransaction();
            $groups=[];
            foreach ($mitems as $mi) {
                $k=$mi['pid'].'_'.$mi['from_wid']; if (!isset($groups[$k])) $groups[$k]=['pid'=>$mi['pid'],'fwid'=>$mi['from_wid'],'items'=>[]];
                $groups[$k]['items'][]=$mi;
            }
            $im=$pdo->prepare('INSERT INTO stock_movements (product_id,from_warehouse_id,to_warehouse_id,qty,moved_by,invoice_no,channel,notes,moved_at) VALUES (:pid,:fw,:tw,:qty,:uid,:inv,:ch,:notes,NOW())');
            $ms=$pdo->prepare('INSERT INTO movement_serials (movement_id,serial_no) VALUES (:mid,:sn)');
            $us=$pdo->prepare("UPDATE stock_items SET warehouse_id=:wid,status='in_stock' WHERE serial_no=:sn");
            $dc=$pdo->prepare('INSERT INTO inventory_stock (product_id,warehouse_id,qty,updated_at) VALUES (:pid,:wid,0,NOW()) ON DUPLICATE KEY UPDATE qty=GREATEST(0,qty-:qty),updated_at=NOW()');
            $ic=$pdo->prepare('INSERT INTO inventory_stock (product_id,warehouse_id,qty,updated_at) VALUES (:pid,:wid,:qty,NOW()) ON DUPLICATE KEY UPDATE qty=qty+:qty2,updated_at=NOW()');
            $totalQty=0; $sAdded=[];
            foreach ($groups as $g) {
                $qty=array_sum(array_column($g['items'],'qty'));
                $im->execute([':pid'=>$g['pid'],':fw'=>$g['fwid'],':tw'=>$toWid,':qty'=>$qty,':uid'=>$_SESSION['user_id'],':inv'=>$inv,':ch'=>$ch,':notes'=>$notes]);
                $mid=(int)$pdo->lastInsertId();
                foreach ($g['items'] as $mi) {
                    if ($mi['is_serialised']&&$mi['serial']!=='') { $ms->execute([':mid'=>$mid,':sn'=>$mi['serial']]); $us->execute([':wid'=>$toWid,':sn'=>$mi['serial']]); $sAdded[]=$mi['serial']; }
                }
                $dc->execute([':pid'=>$g['pid'],':wid'=>$g['fwid'],':qty'=>$qty]);
                $ic->execute([':pid'=>$g['pid'],':wid'=>$toWid,':qty'=>$qty,':qty2'=>$qty]);
                logAudit($pdo,'move_stock','stock_movements',$mid,"Moved $qty unit(s) → warehouse #$toWid via $ch.".(!empty($sAdded)?' Serials: '.implode(', ',$sAdded):''));
                $totalQty+=$qty;
            }
            $toWhName=''; foreach ($warehouses as $w) { if ($w['id']==$toWid) $toWhName=$w['name']; }
            $pdo->commit();
            $moveSummary=['qty'=>$totalQty,'to'=>$toWhName,'channel'=>$ch,'invoice'=>$inv]; $moveSuccess=true;
        } catch (Throwable $e) { $pdo->rollBack(); $moveErrors[]='DB error: '.$e->getMessage(); }
    }
}

// ============================================================
// POST: Take Out
// ============================================================
$toErrors=[]; $toSuccess=false; $toSummary=[];
$toCsvImported=0; $toCsvErrors=[]; $toCsvShow=false;

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['csv_action']??'')==='take_out') {
    $toCsvShow=true;
    $productBySku=[]; $warehouseByName=[];
    foreach ($products as $p) $productBySku[strtolower(trim($p['sku']))]=$p;
    foreach ($warehouses as $w) $warehouseByName[strtolower(trim($w['name']))]=$w;
    $parsed=parseUploadedCsv($_FILES['csv_file']??[]);
    if ($parsed['error']!==null) { $toCsvErrors[]=$parsed['error']; }
    else {
        $miss=array_diff(['product_sku','warehouse_name','channel'],$parsed['headers']);
        if (!empty($miss)) $toCsvErrors[]='Missing: '.implode(', ',$miss);
    }
    if (empty($toCsvErrors)) {
        $validRows=[]; $rowErrors=[]; $seen=[];
        foreach ($parsed['rows'] as $idx=>$row) {
            $rn=$idx+2; $sku=trim($row['product_sku']??''); $wh=trim($row['warehouse_name']??'');
            $ch=trim($row['channel']??''); $sn=trim($row['serial_no']??''); $qty=(int)trim($row['qty']??'0');
            $inv=trim($row['invoice_no']??''); $notes=trim($row['notes']??'');
            if ($sku===''&&$wh===''&&$sn==='') continue;
            $prod=$productBySku[strtolower($sku)]??null; $whouse=$warehouseByName[strtolower($wh)]??null;
            $ok=true;
            if (!$prod) { $rowErrors[]="Row $rn: SKU not found."; $ok=false; }
            if (!$whouse) { $rowErrors[]="Row $rn: Warehouse not found."; $ok=false; }
            if (!in_array(strtolower($ch),$validTakeOutChannels,true)) { $rowErrors[]="Row $rn: invalid channel."; $ok=false; }
            if ($sn===''&&$qty<=0) { $rowErrors[]="Row $rn: need serial or qty."; $ok=false; }
            if ($sn!==''&&isset($seen[$sn])) { $rowErrors[]="Row $rn: serial dup."; $ok=false; }
            if ($sn!=='') $seen[$sn]=true;
            if ($ok) $validRows[]=['product'=>$prod,'warehouse'=>$whouse,'channel'=>strtolower($ch),'invoice_no'=>$inv,'serial'=>$sn,'qty'=>$qty,'notes'=>$notes];
        }
        $toCsvErrors=array_merge($toCsvErrors,$rowErrors);
        if (!empty($validRows)) {
            $groups=[];
            foreach ($validRows as $vr) {
                $k=implode('|',[$vr['product']['id'],$vr['warehouse']['id'],$vr['channel'],$vr['invoice_no']]);
                if (!isset($groups[$k])) $groups[$k]=['product'=>$vr['product'],'warehouse'=>$vr['warehouse'],'channel'=>$vr['channel'],'invoice_no'=>$vr['invoice_no'],'notes'=>$vr['notes'],'serials'=>[],'bulkQty'=>0];
                if ($vr['serial']!=='') $groups[$k]['serials'][]=$vr['serial']; else $groups[$k]['bulkQty']+=$vr['qty'];
            }
            try {
                $pdo->beginTransaction();
                $im=$pdo->prepare('INSERT INTO stock_movements (product_id,from_warehouse_id,to_warehouse_id,qty,moved_by,invoice_no,channel,notes,moved_at) VALUES (:pid,:fwid,NULL,:qty,:uid,:inv,:ch,:notes,NOW())');
                $ms=$pdo->prepare('INSERT INTO movement_serials (movement_id,serial_no) VALUES (:mid,:sn)');
                $us=$pdo->prepare("UPDATE stock_items SET status='sold' WHERE serial_no=:sn");
                $dc=$pdo->prepare('INSERT INTO inventory_stock (product_id,warehouse_id,qty,updated_at) VALUES (:pid,:wid,0,NOW()) ON DUPLICATE KEY UPDATE qty=GREATEST(0,qty-:qty),updated_at=NOW()');
                $tot=0;
                foreach ($groups as $g) {
                    $qty=count($g['serials'])+$g['bulkQty'];
                    $im->execute([':pid'=>$g['product']['id'],':fwid'=>$g['warehouse']['id'],':qty'=>$qty,':uid'=>$_SESSION['user_id'],':inv'=>$g['invoice_no'],':ch'=>$g['channel'],':notes'=>$g['notes']]);
                    $mid=(int)$pdo->lastInsertId();
                    foreach ($g['serials'] as $sn) { $ms->execute([':mid'=>$mid,':sn'=>$sn]); $us->execute([':sn'=>$sn]); }
                    $dc->execute([':pid'=>$g['product']['id'],':wid'=>$g['warehouse']['id'],':qty'=>$qty]);
                    logAudit($pdo,'csv_take_out','stock_movements',$mid,"CSV: took out $qty x \"{$g['product']['name']}\" from \"{$g['warehouse']['name']}\" via {$g['channel']}.");
                    $tot+=$qty;
                }
                $pdo->commit(); $toCsvImported=$tot;
            } catch (Throwable $e) { $pdo->rollBack(); $toCsvErrors[]='DB error: '.$e->getMessage(); }
        }
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='take_out_items') {
    $ch=trim($_POST['takeout_channel']??''); $inv=trim($_POST['takeout_invoice_no']??''); $notes=trim($_POST['takeout_notes']??'');
    $ipids=array_map('intval',$_POST['item_product_id']??[]);
    $isns=$_POST['item_serial_no']??[]; $iwids=array_map('intval',$_POST['item_warehouse_id']??[]);
    $iqtys=array_map('intval',$_POST['item_qty']??[]); $iiss=array_map('intval',$_POST['item_is_serialised']??[]);
    if (!in_array($ch,$validTakeOutChannels,true)) $toErrors[]='Select a channel / reason.';
    if (empty($ipids)) $toErrors[]='No items added.';
    $toitems=[];
    foreach ($ipids as $i=>$pid) {
        if ($pid<=0) continue;
        $sn=trim($isns[$i]??''); $wid=$iwids[$i]??0; $qty=max(1,$iqtys[$i]??1); $isSer=$iiss[$i]??1;
        $toitems[]=['pid'=>$pid,'serial'=>$sn,'wid'=>$wid,'qty'=>$qty,'is_serialised'=>$isSer];
    }
    if (empty($toitems)&&empty($toErrors)) $toErrors[]='No valid items.';
    if (empty($toErrors)) {
        foreach ($toitems as $ti) {
            if ($ti['is_serialised']&&$ti['serial']!=='') {
                $c=$pdo->prepare("SELECT id FROM stock_items WHERE serial_no=:sn AND status='in_stock' LIMIT 1");
                $c->execute([':sn'=>$ti['serial']]);
                if (!$c->fetch()) $toErrors[]="Serial {$ti['serial']} is no longer in stock.";
            }
        }
    }
    if (empty($toErrors)) {
        try {
            $pdo->beginTransaction();
            $groups=[];
            foreach ($toitems as $ti) {
                $k=$ti['pid'].'_'.$ti['wid']; if (!isset($groups[$k])) $groups[$k]=['pid'=>$ti['pid'],'wid'=>$ti['wid'],'items'=>[]];
                $groups[$k]['items'][]=$ti;
            }
            $im=$pdo->prepare('INSERT INTO stock_movements (product_id,from_warehouse_id,to_warehouse_id,qty,moved_by,invoice_no,channel,notes,moved_at) VALUES (:pid,:fwid,NULL,:qty,:uid,:inv,:ch,:notes,NOW())');
            $ms=$pdo->prepare('INSERT INTO movement_serials (movement_id,serial_no) VALUES (:mid,:sn)');
            $us=$pdo->prepare("UPDATE stock_items SET status='sold' WHERE serial_no=:sn");
            $dc=$pdo->prepare('INSERT INTO inventory_stock (product_id,warehouse_id,qty,updated_at) VALUES (:pid,:wid,0,NOW()) ON DUPLICATE KEY UPDATE qty=GREATEST(0,qty-:qty),updated_at=NOW()');
            $totalQty=0; $sAdded=[];
            foreach ($groups as $g) {
                $qty=array_sum(array_column($g['items'],'qty'));
                $im->execute([':pid'=>$g['pid'],':fwid'=>$g['wid'],':qty'=>$qty,':uid'=>$_SESSION['user_id'],':inv'=>$inv,':ch'=>$ch,':notes'=>$notes]);
                $mid=(int)$pdo->lastInsertId();
                foreach ($g['items'] as $ti) {
                    if ($ti['is_serialised']&&$ti['serial']!=='') { $ms->execute([':mid'=>$mid,':sn'=>$ti['serial']]); $us->execute([':sn'=>$ti['serial']]); $sAdded[]=$ti['serial']; }
                }
                $dc->execute([':pid'=>$g['pid'],':wid'=>$g['wid'],':qty'=>$qty]);
                logAudit($pdo,'take_out_stock','stock_movements',$mid,"Took out $qty unit(s) from warehouse #{$g['wid']} via $ch.".(!empty($sAdded)?' Serials: '.implode(', ',$sAdded):''));
                $totalQty+=$qty;
            }
            $pdo->commit();
            $toSummary=['qty'=>$totalQty,'channel'=>$ch,'invoice'=>$inv]; $toSuccess=true;
        } catch (Throwable $e) { $pdo->rollBack(); $toErrors[]='DB error: '.$e->getMessage(); }
    }
}

// Determine active mode (from POST, GET, or default)
$activeMode = 'scan_in';
if (!empty($_POST['action'])) {
    if ($_POST['action']==='move_items') $activeMode='move';
    elseif ($_POST['action']==='take_out_items') $activeMode='take_out';
}
if (!empty($_POST['csv_action'])) {
    if ($_POST['csv_action']==='move_stock') $activeMode='move';
    elseif ($_POST['csv_action']==='take_out') $activeMode='take_out';
}
if (isset($_GET['mode'])) $activeMode=$_GET['mode'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
    <div>
        <h2 class="page-title">Stock Operations</h2>
        <p class="page-subtitle">Scan in new stock, move between warehouses, or remove from inventory.</p>
    </div>
</div>

<!-- ============================================================
     MODE SWITCHER
     ============================================================ -->
<div style="display:flex;gap:.5rem;margin-bottom:1.25rem;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:.35rem;">
    <button type="button" class="mode-tab" data-mode="scan_in"
            style="flex:1;padding:.55rem 1rem;border-radius:7px;border:none;cursor:pointer;font-size:.92rem;font-weight:600;transition:all .15s;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="vertical-align:-.15em;margin-right:.3rem;"><path d="M12 5v14M5 12l7-7 7 7"/></svg>
        Scan In
    </button>
    <button type="button" class="mode-tab" data-mode="move"
            style="flex:1;padding:.55rem 1rem;border-radius:7px;border:none;cursor:pointer;font-size:.92rem;font-weight:600;transition:all .15s;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="vertical-align:-.15em;margin-right:.3rem;"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        Move Stock
    </button>
    <button type="button" class="mode-tab" data-mode="take_out"
            style="flex:1;padding:.55rem 1rem;border-radius:7px;border:none;cursor:pointer;font-size:.92rem;font-weight:600;transition:all .15s;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="vertical-align:-.15em;margin-right:.3rem;"><path d="M12 19V5M5 12l7 7 7-7"/></svg>
        Take Out
    </button>
</div>

<!-- ============================================================
     PANEL: SCAN IN
     ============================================================ -->
<div class="mode-panel" id="panel-scan_in" style="display:none;">

<?php foreach ($scanErrors as $e): ?><div class="alert alert-error"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
<?php if ($scanSuccess): ?>
<div class="alert alert-success">
    <strong>✓ <?= $scanSummary['qty'] ?> unit(s) scanned into <?= htmlspecialchars($scanSummary['warehouse']) ?>.</strong>
    Source: <?= htmlspecialchars($scanSummary['source']) ?>
</div>
<?php endif; ?>

<div class="pos-layout" style="display:grid;grid-template-columns:1fr 320px;gap:1.25rem;align-items:start;">
<div><!-- LEFT -->

    <!-- Settings bar -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body" style="padding:.75rem 1rem;">
            <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;">
                <div class="form-group" style="margin:0;min-width:200px;">
                    <label class="form-label" style="font-size:.8rem;">Source</label>
                    <div style="display:flex;gap:.75rem;margin-top:.3rem;">
                        <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer;font-size:.9rem;"><input type="radio" name="si_src" value="manual" id="si-src-manual" checked> Manual</label>
                        <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer;font-size:.9rem;"><input type="radio" name="si_src" value="po" id="si-src-po"> Purchase Order</label>
                    </div>
                </div>
                <div id="si-po-wrap" style="display:none;min-width:260px;">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" style="font-size:.8rem;">Purchase Order</label>
                        <select id="si-po-sel" class="form-control form-select" style="margin-top:.25rem;">
                            <option value="">— Select PO —</option>
                            <?php foreach ($openPOs as $op): ?>
                            <option value="<?= $op['id'] ?>" data-wid="<?= $op['warehouse_id'] ?>">
                                <?= htmlspecialchars($op['po_number']) ?> — <?= htmlspecialchars($op['supplier_name']??'') ?> (<?= htmlspecialchars($op['warehouse_name']) ?>)
                            </option>
                            <?php endforeach; ?>
                            <?php if (empty($openPOs)): ?><option disabled>No open POs</option><?php endif; ?>
                        </select>
                    </div>
                </div>
                <div id="si-ref-wrap" style="min-width:200px;">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" style="font-size:.8rem;">Reference (optional)</label>
                        <input type="text" id="si-ref" class="form-control" style="margin-top:.25rem;" placeholder="e.g. Initial stock, Return…">
                    </div>
                </div>
                <div class="form-group" style="margin:0;min-width:200px;">
                    <label class="form-label" style="font-size:.8rem;">Destination Warehouse <span class="required">*</span></label>
                    <select id="si-warehouse" class="form-control form-select" style="margin-top:.25rem;">
                        <option value="">— Select warehouse —</option>
                        <?php foreach ($warehouses as $w): ?>
                        <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Scan bar -->
    <div class="card" style="margin-bottom:1rem;overflow:visible;">
        <div class="card-body" style="padding:.85rem 1rem 1rem;overflow:visible;">
            <label class="form-label" style="font-size:.8rem;color:#6B7280;display:block;margin-bottom:.4rem;">SCAN BARCODE OR SEARCH PRODUCT</label>
            <div style="position:relative;">
                <span style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:#9CA3AF;pointer-events:none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                </span>
                <input type="text" id="si-search" class="form-control" style="padding-left:2.2rem;font-size:1.05rem;height:46px;"
                       placeholder="Scan barcode or type product name / SKU…" autocomplete="off" autocorrect="off" spellcheck="false">
                <div id="si-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;background:#fff;border:1px solid #D1D5DB;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);max-height:280px;overflow-y:auto;margin-top:2px;"></div>
            </div>
        </div>
    </div>

    <!-- New serial detected panel (shown when scanned value doesn't match any product) -->
    <div id="si-serial-mode" style="display:none;margin-bottom:1rem;">
        <div class="card" style="border:2px solid #16A34A;background:#F0FDF4;">
            <div class="card-body" style="padding:1rem;">
                <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.85rem;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <span style="background:#16A34A;color:#fff;border-radius:6px;padding:.2rem .6rem;font-size:.75rem;font-weight:700;">NEW SERIAL</span>
                        <code id="si-sm-serial" style="background:#DCFCE7;color:#15803D;border-radius:6px;padding:.3rem .75rem;font-size:1rem;font-weight:700;letter-spacing:.03em;border:1px solid #BBF7D0;">—</code>
                    </div>
                    <div style="color:#374151;font-size:.875rem;flex:1;">Which product does this serial belong to?</div>
                    <button type="button" onclick="SI.clearSerialMode()" style="background:none;border:none;color:#9CA3AF;cursor:pointer;font-size:1.1rem;line-height:1;" title="Cancel">✕</button>
                </div>
                <div style="position:relative;">
                    <input type="text" id="si-sm-product-search" class="form-control"
                           placeholder="Search product name or SKU…" autocomplete="off"
                           style="font-size:.95rem;">
                    <div id="si-sm-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;
                         background:#fff;border:1px solid #D1D5DB;border-radius:8px;
                         box-shadow:0 4px 16px rgba(0,0,0,.12);max-height:280px;overflow-y:auto;margin-top:2px;"></div>
                </div>
                <div style="font-size:.78rem;color:#6B7280;margin-top:.4rem;">
                    Select a product above to assign this serial and add it to the batch.
                </div>
            </div>
        </div>
    </div>

    <!-- Product input panel -->
    <div id="si-product-panel" style="display:none;margin-bottom:1rem;">
        <div class="card" style="border:2px solid #3B82F6;">
            <div class="card-body" style="padding:1rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;">
                    <div>
                        <div style="font-weight:700;font-size:1rem;" id="si-panel-name">—</div>
                        <div style="font-size:.82rem;color:#6B7280;" id="si-panel-sku">—</div>
                    </div>
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <span id="si-panel-badge" style="background:#EFF6FF;color:#3B82F6;padding:.2rem .6rem;border-radius:6px;font-size:.78rem;font-weight:600;"></span>
                        <button type="button" onclick="SI.clearPanel()" style="background:none;border:none;color:#9CA3AF;cursor:pointer;font-size:1.1rem;" title="Clear">✕</button>
                    </div>
                </div>
                <div id="si-serial-section">
                    <div style="display:flex;gap:.75rem;align-items:flex-end;">
                        <div class="form-group" style="margin:0;flex:1;">
                            <label class="form-label" style="font-size:.82rem;">Serial Number <span style="color:#9CA3AF;font-weight:400;">— scan the barcode or type</span></label>
                            <input type="text" id="si-serial-input" class="form-control" style="margin-top:.3rem;font-family:monospace;font-size:1rem;" placeholder="Scan serial…" autocomplete="off">
                            <div id="si-serial-status" style="font-size:.8rem;margin-top:.25rem;min-height:1rem;"></div>
                        </div>
                        <button type="button" class="btn btn-primary" style="height:42px;padding:0 1.25rem;" onclick="SI.addItem()">Add ▶</button>
                    </div>
                    <small style="color:#9CA3AF;margin-top:.4rem;display:block;">Press <kbd style="background:#F3F4F6;border:1px solid #D1D5DB;border-radius:3px;padding:.1rem .3rem;font-size:.8rem;">Enter</kbd> to auto-add and scan next.</small>
                </div>
                <div id="si-bulk-section" style="display:none;">
                    <div style="display:flex;gap:.75rem;align-items:flex-end;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:.82rem;">Quantity to add</label>
                            <input type="number" id="si-bulk-qty" class="form-control" min="1" step="1" value="1" style="margin-top:.3rem;max-width:130px;font-size:1.1rem;text-align:center;">
                        </div>
                        <button type="button" class="btn btn-primary" style="height:42px;padding:0 1.25rem;" onclick="SI.addItem()">Add ▶</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Items list -->
    <div class="card" id="si-items-card" style="display:none;">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3 class="card-title">Items to Scan In <span id="si-count-badge" style="background:#DBEAFE;color:#1D4ED8;border-radius:10px;padding:.1rem .55rem;font-size:.8rem;margin-left:.4rem;font-weight:700;">0</span></h3>
            <button type="button" onclick="SI.clearAll()" style="background:none;border:none;color:#DC2626;font-size:.85rem;cursor:pointer;">Clear All</button>
        </div>
        <div class="table-responsive">
            <table class="table" style="margin:0;">
                <thead><tr><th>#</th><th>Product</th><th>Serial / Qty</th><th style="width:48px;"></th></tr></thead>
                <tbody id="si-items-tbody"></tbody>
            </table>
        </div>
    </div>

    <!-- CSV Import -->
    <div id="si-csv-panel" style="display:none;margin-top:1rem;">
        <div class="card">
            <div class="card-header"><h3 class="card-title">CSV Import</h3></div>
            <div class="card-body">
                <p style="font-size:.875rem;color:#6B7280;margin-bottom:1rem;">Columns: <code>product_sku</code>, <code>warehouse_name</code>, <code>serial_no</code>, <code>qty</code></p>
                <a href="?dl_template=scan_in&mode=scan_in" class="btn btn-sm btn-outline" style="margin-bottom:1rem;">Download Template</a>
                <form method="POST" action="?mode=scan_in" enctype="multipart/form-data">
                    <input type="hidden" name="csv_action" value="scan_in">
                    <div class="form-group"><input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required></div>
                    <button type="submit" class="btn btn-primary">Import CSV</button>
                </form>
                <?php if ($csvShowResult): ?>
                <?php if ($csvImported>0): ?><div class="alert alert-success" style="margin-top:.75rem;"><strong><?= $csvImported ?> unit(s) imported.</strong></div><?php endif; ?>
                <?php if (!empty($csvErrors)): ?><div class="alert alert-error" style="margin-top:.75rem;"><strong>Errors:</strong><ul style="margin:.25rem 0 0 1rem;"><?php foreach ($csvErrors as $ce): ?><li><?= htmlspecialchars($ce) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div><!-- /LEFT -->

<!-- RIGHT: Summary -->
<div style="position:sticky;top:80px;">
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h3 class="card-title">Summary</h3></div>
        <div class="card-body" style="padding:.75rem 1rem;">
            <div id="si-summary-empty" style="color:#9CA3AF;font-size:.875rem;text-align:center;padding:.5rem 0;">Scan a product to start adding items.</div>
            <div id="si-summary-stats" style="display:none;">
                <div style="display:flex;justify-content:space-between;font-size:.875rem;margin-bottom:.35rem;"><span style="color:#6B7280;">Total Units</span><span id="si-stat-units" style="font-weight:700;">0</span></div>
                <div style="display:flex;justify-content:space-between;font-size:.875rem;margin-bottom:.35rem;"><span style="color:#6B7280;">Serialised</span><span id="si-stat-serials">0</span></div>
                <div style="display:flex;justify-content:space-between;font-size:.875rem;margin-bottom:.75rem;"><span style="color:#6B7280;">Bulk</span><span id="si-stat-bulk">0</span></div>
                <div style="border-top:1px solid #E5E7EB;padding-top:.75rem;">
                    <div style="font-size:.8rem;color:#6B7280;margin-bottom:.25rem;">Destination</div>
                    <div id="si-stat-warehouse" style="font-weight:600;font-size:.9rem;">—</div>
                </div>
            </div>
        </div>
    </div>
    <button type="button" id="si-commit-btn" class="btn btn-primary" style="width:100%;padding:.65rem;font-size:1rem;margin-bottom:.5rem;" disabled onclick="SI.commit()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" style="vertical-align:-.15em;margin-right:.3rem;"><path d="M12 5v14M5 12l7-7 7 7" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Scan In Stock
    </button>
    <button type="button" class="btn btn-outline" style="width:100%;margin-bottom:.5rem;" onclick="document.getElementById('si-csv-panel').style.display=document.getElementById('si-csv-panel').style.display==='none'?'block':'none'">CSV Import</button>
    <a href="<?= BASE_URL ?>/inventory/view_stock.php" class="btn btn-outline" style="width:100%;display:block;text-align:center;">View Stock</a>
    <!-- Hidden form for submission -->
    <form id="si-form" method="POST" action="?mode=scan_in" style="display:none;">
        <input type="hidden" name="action" value="scan_in_items">
        <input type="hidden" name="warehouse_id" id="si-fld-warehouse">
        <input type="hidden" name="source_type" id="si-fld-src-type" value="manual">
        <input type="hidden" name="po_id" id="si-fld-po">
        <input type="hidden" name="source_ref" id="si-fld-ref">
        <div id="si-hidden-items"></div>
    </form>
</div>
</div><!-- /grid -->
</div><!-- /panel-scan_in -->


<!-- ============================================================
     PANEL: MOVE STOCK
     ============================================================ -->
<div class="mode-panel" id="panel-move" style="display:none;">

<?php foreach ($moveErrors as $e): ?><div class="alert alert-error"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
<?php if ($moveSuccess): ?>
<div class="alert alert-success">
    <strong>✓ <?= $moveSummary['qty'] ?> unit(s) moved to <?= htmlspecialchars($moveSummary['to']) ?>.</strong>
    Channel: <?= htmlspecialchars($moveSummary['channel']) ?><?= $moveSummary['invoice']?' · Ref: '.htmlspecialchars($moveSummary['invoice']):'' ?>
</div>
<?php endif; ?>

<div class="pos-layout" style="display:grid;grid-template-columns:1fr 320px;gap:1.25rem;align-items:start;">
<div><!-- LEFT -->

    <!-- Scan bar -->
    <div class="card" style="margin-bottom:1rem;overflow:visible;">
        <div class="card-body" style="padding:.85rem 1rem 1rem;overflow:visible;">
            <label class="form-label" style="font-size:.8rem;color:#6B7280;display:block;margin-bottom:.4rem;">SCAN SERIAL / BARCODE OR SEARCH BY NAME</label>
            <div style="position:relative;">
                <span style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:#9CA3AF;pointer-events:none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                </span>
                <input type="text" id="mv-serial-input" class="form-control" style="padding-left:2.2rem;font-size:1.05rem;height:46px;"
                       placeholder="Scan serial, barcode, SKU or type product name…" autocomplete="off" autocorrect="off" spellcheck="false">
                <div id="mv-scan-drop" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;background:#fff;border:1px solid #D1D5DB;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.12);max-height:220px;overflow-y:auto;margin-top:2px;"></div>
            </div>
            <div id="mv-serial-status" style="font-size:.85rem;margin-top:.4rem;min-height:1.2rem;color:#6B7280;"></div>
        </div>
    </div>

    <!-- Bulk add toggle -->
    <div style="margin-bottom:1rem;">
        <button type="button" onclick="document.getElementById('mv-bulk-form').style.display=document.getElementById('mv-bulk-form').style.display==='none'?'block':'none'"
                style="background:none;border:1px dashed #D1D5DB;border-radius:8px;padding:.5rem 1rem;width:100%;cursor:pointer;color:#6B7280;font-size:.875rem;text-align:left;">
            ＋ Add bulk (non-serialised) item…
        </button>
        <div id="mv-bulk-form" style="display:none;margin-top:.5rem;">
            <div class="card" style="border:1px dashed #D1D5DB;overflow:visible;">
                <div class="card-body" style="padding:.85rem 1rem;overflow:visible;">
                    <div style="display:grid;grid-template-columns:1fr 1fr auto auto;gap:.75rem;align-items:flex-end;">
                        <div class="form-group" style="margin:0;position:relative;">
                            <label class="form-label" style="font-size:.8rem;">Product</label>
                            <input type="text" id="mv-bulk-product-search" class="form-control" style="margin-top:.25rem;" placeholder="Search SKU or name…" autocomplete="off">
                            <div id="mv-bulk-product-drop" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;background:#fff;border:1px solid #D1D5DB;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.1);max-height:200px;overflow-y:auto;"></div>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:.8rem;">From Warehouse</label>
                            <select id="mv-bulk-from-wh" class="form-control form-select" style="margin-top:.25rem;">
                                <option value="">— Select —</option>
                                <?php foreach ($warehouses as $w): ?><option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:.8rem;">Qty</label>
                            <input type="number" id="mv-bulk-qty" class="form-control" min="1" value="1" style="margin-top:.25rem;width:80px;">
                        </div>
                        <button type="button" class="btn btn-primary" style="height:38px;" onclick="MV.addBulk()">Add</button>
                    </div>
                    <div id="mv-bulk-product-hidden" data-id="" data-name="" data-sku=""></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Items list -->
    <div class="card" id="mv-items-card" style="display:none;">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3 class="card-title">Items to Move <span id="mv-count-badge" style="background:#FEF9C3;color:#854D0E;border-radius:10px;padding:.1rem .55rem;font-size:.8rem;margin-left:.4rem;font-weight:700;">0</span></h3>
            <button type="button" onclick="MV.clearAll()" style="background:none;border:none;color:#DC2626;font-size:.85rem;cursor:pointer;">Clear All</button>
        </div>
        <div class="table-responsive">
            <table class="table" style="margin:0;">
                <thead><tr><th>#</th><th>Product</th><th>Serial / Qty</th><th>From</th><th style="width:48px;"></th></tr></thead>
                <tbody id="mv-items-tbody"></tbody>
            </table>
        </div>
    </div>

    <!-- CSV Import -->
    <div id="mv-csv-panel" style="display:none;margin-top:1rem;">
        <div class="card">
            <div class="card-header"><h3 class="card-title">CSV Import</h3></div>
            <div class="card-body">
                <p style="font-size:.875rem;color:#6B7280;margin-bottom:1rem;">Columns: <code>product_sku</code>, <code>from_warehouse</code>, <code>to_warehouse</code>, <code>channel</code>, <code>invoice_no</code>, <code>serial_no</code>, <code>notes</code></p>
                <a href="?dl_template=move_stock&mode=move" class="btn btn-sm btn-outline" style="margin-bottom:1rem;">Download Template</a>
                <form method="POST" action="?mode=move" enctype="multipart/form-data">
                    <input type="hidden" name="csv_action" value="move_stock">
                    <div class="form-group"><input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required></div>
                    <button type="submit" class="btn btn-primary">Import CSV</button>
                </form>
                <?php if ($moveCsvShow): ?>
                <?php if ($moveCsvImported>0): ?><div class="alert alert-success" style="margin-top:.75rem;"><strong><?= $moveCsvImported ?> serial(s) moved.</strong></div><?php endif; ?>
                <?php if (!empty($moveCsvErrors)): ?><div class="alert alert-error" style="margin-top:.75rem;"><strong>Errors:</strong><ul style="margin:.25rem 0 0 1rem;"><?php foreach ($moveCsvErrors as $ce): ?><li><?= htmlspecialchars($ce) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div><!-- /LEFT -->

<!-- RIGHT: Settings + Summary -->
<div style="position:sticky;top:80px;">
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h3 class="card-title">Move Settings</h3></div>
        <div class="card-body" style="padding:.75rem 1rem;">
            <div class="form-group" style="margin-bottom:.75rem;">
                <label class="form-label" style="font-size:.85rem;">To Warehouse <span class="required">*</span></label>
                <select id="mv-to-warehouse" class="form-control form-select" style="margin-top:.25rem;">
                    <option value="">— Select destination —</option>
                    <?php foreach ($warehouses as $w): ?><option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:.75rem;">
                <label class="form-label" style="font-size:.85rem;">Channel <span class="required">*</span></label>
                <select id="mv-channel" class="form-control form-select" style="margin-top:.25rem;">
                    <option value="">— Select —</option>
                    <option value="transfer">Transfer</option>
                    <option value="takealot">Takealot</option>
                    <option value="makro">Makro</option>
                    <option value="instore">In-Store</option>
                    <option value="email">Email Order</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:.75rem;">
                <label class="form-label" style="font-size:.85rem;">Invoice / Ref</label>
                <input type="text" id="mv-invoice" class="form-control" style="margin-top:.25rem;" placeholder="Optional">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" style="font-size:.85rem;">Notes</label>
                <textarea id="mv-notes" class="form-control" rows="2" style="margin-top:.25rem;" placeholder="Optional…"></textarea>
            </div>
        </div>
    </div>
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body" style="padding:.75rem 1rem;">
            <div id="mv-summary-empty" style="color:#9CA3AF;font-size:.875rem;text-align:center;padding:.5rem 0;">Scan serials to start.</div>
            <div id="mv-summary-stats" style="display:none;">
                <div style="display:flex;justify-content:space-between;font-size:.875rem;margin-bottom:.35rem;"><span style="color:#6B7280;">Items</span><span id="mv-stat-items" style="font-weight:700;">0</span></div>
                <div style="display:flex;justify-content:space-between;font-size:.875rem;"><span style="color:#6B7280;">Total Units</span><span id="mv-stat-units" style="font-weight:700;">0</span></div>
            </div>
        </div>
    </div>
    <button type="button" id="mv-commit-btn" class="btn btn-warning" style="width:100%;padding:.65rem;font-size:1rem;margin-bottom:.5rem;" disabled onclick="MV.commit()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" style="vertical-align:-.15em;margin-right:.3rem;"><path d="M5 12h14M12 5l7 7-7 7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Move Stock
    </button>
    <button type="button" class="btn btn-outline" style="width:100%;margin-bottom:.5rem;" onclick="document.getElementById('mv-csv-panel').style.display=document.getElementById('mv-csv-panel').style.display==='none'?'block':'none'">CSV Import</button>
    <!-- Hidden form -->
    <form id="mv-form" method="POST" action="?mode=move" style="display:none;">
        <input type="hidden" name="action" value="move_items">
        <input type="hidden" name="to_warehouse_id" id="mv-fld-to-wh">
        <input type="hidden" name="move_channel" id="mv-fld-channel">
        <input type="hidden" name="move_invoice_no" id="mv-fld-invoice">
        <input type="hidden" name="move_notes" id="mv-fld-notes">
        <div id="mv-hidden-items"></div>
    </form>
</div>
</div><!-- /grid -->
</div><!-- /panel-move -->


<!-- ============================================================
     PANEL: TAKE OUT
     ============================================================ -->
<div class="mode-panel" id="panel-take_out" style="display:none;">

<?php foreach ($toErrors as $e): ?><div class="alert alert-error"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
<?php if ($toSuccess): ?>
<div class="alert alert-success">
    <strong>✓ <?= $toSummary['qty'] ?> unit(s) removed from stock.</strong>
    Channel: <?= htmlspecialchars($toSummary['channel']) ?><?= $toSummary['invoice']?' · Ref: '.htmlspecialchars($toSummary['invoice']):'' ?>
</div>
<?php endif; ?>

<div class="pos-layout" style="display:grid;grid-template-columns:1fr 320px;gap:1.25rem;align-items:start;">
<div><!-- LEFT -->

    <!-- Scan bar -->
    <div class="card" style="margin-bottom:1rem;overflow:visible;">
        <div class="card-body" style="padding:.85rem 1rem 1rem;overflow:visible;">
            <label class="form-label" style="font-size:.8rem;color:#6B7280;display:block;margin-bottom:.4rem;">SCAN SERIAL / BARCODE OR SEARCH BY NAME</label>
            <div style="position:relative;">
                <span style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:#9CA3AF;pointer-events:none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                </span>
                <input type="text" id="to-serial-input" class="form-control" style="padding-left:2.2rem;font-size:1.05rem;height:46px;"
                       placeholder="Scan serial, barcode, SKU or type product name…" autocomplete="off" autocorrect="off" spellcheck="false">
                <div id="to-scan-drop" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;background:#fff;border:1px solid #D1D5DB;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.12);max-height:220px;overflow-y:auto;margin-top:2px;"></div>
            </div>
            <div id="to-serial-status" style="font-size:.85rem;margin-top:.4rem;min-height:1.2rem;color:#6B7280;"></div>
        </div>
    </div>

    <!-- Bulk add toggle -->
    <div style="margin-bottom:1rem;">
        <button type="button" onclick="document.getElementById('to-bulk-form').style.display=document.getElementById('to-bulk-form').style.display==='none'?'block':'none'"
                style="background:none;border:1px dashed #D1D5DB;border-radius:8px;padding:.5rem 1rem;width:100%;cursor:pointer;color:#6B7280;font-size:.875rem;text-align:left;">
            ＋ Add bulk (non-serialised) item…
        </button>
        <div id="to-bulk-form" style="display:none;margin-top:.5rem;">
            <div class="card" style="border:1px dashed #D1D5DB;overflow:visible;">
                <div class="card-body" style="padding:.85rem 1rem;overflow:visible;">
                    <div style="display:grid;grid-template-columns:1fr 1fr auto auto;gap:.75rem;align-items:flex-end;">
                        <div class="form-group" style="margin:0;position:relative;">
                            <label class="form-label" style="font-size:.8rem;">Product</label>
                            <input type="text" id="to-bulk-product-search" class="form-control" style="margin-top:.25rem;" placeholder="Search SKU or name…" autocomplete="off">
                            <div id="to-bulk-product-drop" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;background:#fff;border:1px solid #D1D5DB;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.1);max-height:200px;overflow-y:auto;"></div>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:.8rem;">Warehouse</label>
                            <select id="to-bulk-wh" class="form-control form-select" style="margin-top:.25rem;">
                                <option value="">— Select —</option>
                                <?php foreach ($warehouses as $w): ?><option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:.8rem;">Qty</label>
                            <input type="number" id="to-bulk-qty" class="form-control" min="1" value="1" style="margin-top:.25rem;width:80px;">
                        </div>
                        <button type="button" class="btn btn-danger" style="height:38px;" onclick="TO.addBulk()">Add</button>
                    </div>
                    <div id="to-bulk-product-hidden" data-id="" data-name="" data-sku=""></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Items list -->
    <div class="card" id="to-items-card" style="display:none;">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3 class="card-title">Items to Remove <span id="to-count-badge" style="background:#FEE2E2;color:#991B1B;border-radius:10px;padding:.1rem .55rem;font-size:.8rem;margin-left:.4rem;font-weight:700;">0</span></h3>
            <button type="button" onclick="TO.clearAll()" style="background:none;border:none;color:#DC2626;font-size:.85rem;cursor:pointer;">Clear All</button>
        </div>
        <div class="table-responsive">
            <table class="table" style="margin:0;">
                <thead><tr><th>#</th><th>Product</th><th>Serial / Qty</th><th>Warehouse</th><th style="width:48px;"></th></tr></thead>
                <tbody id="to-items-tbody"></tbody>
            </table>
        </div>
    </div>

    <!-- CSV Import -->
    <div id="to-csv-panel" style="display:none;margin-top:1rem;">
        <div class="card">
            <div class="card-header"><h3 class="card-title">CSV Import</h3></div>
            <div class="card-body">
                <p style="font-size:.875rem;color:#6B7280;margin-bottom:1rem;">Columns: <code>product_sku</code>, <code>warehouse_name</code>, <code>channel</code>, <code>invoice_no</code>, <code>serial_no</code>, <code>qty</code>, <code>notes</code></p>
                <a href="?dl_template=take_out&mode=take_out" class="btn btn-sm btn-outline" style="margin-bottom:1rem;">Download Template</a>
                <form method="POST" action="?mode=take_out" enctype="multipart/form-data">
                    <input type="hidden" name="csv_action" value="take_out">
                    <div class="form-group"><input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required></div>
                    <button type="submit" class="btn btn-primary">Import CSV</button>
                </form>
                <?php if ($toCsvShow): ?>
                <?php if ($toCsvImported>0): ?><div class="alert alert-success" style="margin-top:.75rem;"><strong><?= $toCsvImported ?> unit(s) removed.</strong></div><?php endif; ?>
                <?php if (!empty($toCsvErrors)): ?><div class="alert alert-error" style="margin-top:.75rem;"><strong>Errors:</strong><ul style="margin:.25rem 0 0 1rem;"><?php foreach ($toCsvErrors as $ce): ?><li><?= htmlspecialchars($ce) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div><!-- /LEFT -->

<!-- RIGHT: Settings + Summary -->
<div style="position:sticky;top:80px;">
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h3 class="card-title">Remove Details</h3></div>
        <div class="card-body" style="padding:.75rem 1rem;">
            <div class="form-group" style="margin-bottom:.75rem;">
                <label class="form-label" style="font-size:.85rem;">Channel / Reason <span class="required">*</span></label>
                <select id="to-channel" class="form-control form-select" style="margin-top:.25rem;">
                    <option value="">— Select —</option>
                    <option value="takealot">Takealot</option>
                    <option value="makro">Makro</option>
                    <option value="instore">In-Store Sale</option>
                    <option value="email">Email Order</option>
                    <option value="other">Other / Write-off</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:.75rem;">
                <label class="form-label" style="font-size:.85rem;">Invoice / Ref</label>
                <input type="text" id="to-invoice" class="form-control" style="margin-top:.25rem;" placeholder="Optional">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" style="font-size:.85rem;">Notes</label>
                <textarea id="to-notes" class="form-control" rows="2" style="margin-top:.25rem;" placeholder="Optional…"></textarea>
            </div>
        </div>
    </div>
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body" style="padding:.75rem 1rem;">
            <div id="to-summary-empty" style="color:#9CA3AF;font-size:.875rem;text-align:center;padding:.5rem 0;">Scan serials to start.</div>
            <div id="to-summary-stats" style="display:none;">
                <div style="display:flex;justify-content:space-between;font-size:.875rem;margin-bottom:.35rem;"><span style="color:#6B7280;">Items</span><span id="to-stat-items" style="font-weight:700;">0</span></div>
                <div style="display:flex;justify-content:space-between;font-size:.875rem;"><span style="color:#6B7280;">Total Units</span><span id="to-stat-units" style="font-weight:700;">0</span></div>
            </div>
        </div>
    </div>
    <button type="button" id="to-commit-btn" class="btn btn-danger" style="width:100%;padding:.65rem;font-size:1rem;margin-bottom:.5rem;" disabled onclick="TO.commit()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" style="vertical-align:-.15em;margin-right:.3rem;"><path d="M12 19V5M5 12l7 7 7-7" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Confirm Take Out
    </button>
    <button type="button" class="btn btn-outline" style="width:100%;margin-bottom:.5rem;" onclick="document.getElementById('to-csv-panel').style.display=document.getElementById('to-csv-panel').style.display==='none'?'block':'none'">CSV Import</button>
    <!-- Hidden form -->
    <form id="to-form" method="POST" action="?mode=take_out" style="display:none;">
        <input type="hidden" name="action" value="take_out_items">
        <input type="hidden" name="takeout_channel" id="to-fld-channel">
        <input type="hidden" name="takeout_invoice_no" id="to-fld-invoice">
        <input type="hidden" name="takeout_notes" id="to-fld-notes">
        <div id="to-hidden-items"></div>
    </form>
</div>
</div><!-- /grid -->
</div><!-- /panel-take_out -->


<style>
.mode-tab { background: transparent; color: #6B7280; }
.mode-tab.active { background: #fff; color: #1D4ED8; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
.btn-warning { background:#F59E0B;color:#fff;border:none; }
.btn-warning:hover { background:#D97706; }
.btn-warning:disabled { background:#FCD34D;cursor:not-allowed; }
</style>

<script>
var BASE_URL = '<?= BASE_URL ?>';
var WAREHOUSES = <?= json_encode(array_map(fn($w)=>['id'=>(int)$w['id'],'name'=>$w['name']], $warehouses)) ?>;

// ============================================================
// MODE SWITCHER
// ============================================================
var activeMode = '<?= htmlspecialchars($activeMode) ?>';
(function () {
    var tabs   = document.querySelectorAll('.mode-tab');
    var panels = document.querySelectorAll('.mode-panel');
    function activate(mode) {
        activeMode = mode;
        tabs.forEach(function(t){ t.classList.toggle('active', t.dataset.mode===mode); });
        panels.forEach(function(p){ p.style.display = p.id==='panel-'+mode ? '' : 'none'; });
        history.replaceState(null,'','?mode='+mode);
        // Focus scan input of active panel
        var inp = document.getElementById(mode==='scan_in'?'si-search':mode==='move'?'mv-serial-input':'to-serial-input');
        if (inp) setTimeout(function(){ inp.focus(); },50);
    }
    tabs.forEach(function(t){ t.addEventListener('click',function(){ activate(t.dataset.mode); }); });
    activate(activeMode);
}());

// ============================================================
// SCAN IN (SI)
// ============================================================
var SI = (function(){
    var items = [];
    var selectedProduct = null;
    var _pendingSerial  = null;   // serial captured in serial-mode

    var searchInput  = document.getElementById('si-search');
    var dropdown     = document.getElementById('si-dropdown');
    var serialInput  = document.getElementById('si-serial-input');
    var serialStatus = document.getElementById('si-serial-status');
    var smPanel      = document.getElementById('si-serial-mode');
    var smSerialLbl  = document.getElementById('si-sm-serial');
    var smSearch     = document.getElementById('si-sm-product-search');
    var smDrop       = document.getElementById('si-sm-dropdown');
    var _debounce    = null;
    var _smDebounce  = null;
    var _serialChk   = null;

    // Source toggle
    document.querySelectorAll('input[name="si_src"]').forEach(function(r){
        r.addEventListener('change',function(){
            var isPo = this.value==='po';
            document.getElementById('si-po-wrap').style.display  = isPo?'':'none';
            document.getElementById('si-ref-wrap').style.display = isPo?'none':'';
        });
    });
    document.getElementById('si-po-sel').addEventListener('change',function(){
        var opt = this.options[this.selectedIndex];
        var wid = opt ? opt.getAttribute('data-wid') : null;
        var wh  = document.getElementById('si-warehouse');
        if (wid && wh) {
            for (var i=0;i<wh.options.length;i++) { if (wh.options[i].value==wid){ wh.selectedIndex=i; wh.disabled=true; break; } }
        } else if (wh) { wh.disabled=false; }
    });

    // Product search
    searchInput.addEventListener('keydown',function(e){
        if (e.key==='Enter'){
            e.preventDefault();
            clearTimeout(_debounce);
            exactSearch(this.value.trim());
        }
    });
    searchInput.addEventListener('input',function(){
        var v=this.value.trim();
        clearTimeout(_debounce);
        if (!v) { dropdown.style.display='none'; return; }
        _debounce=setTimeout(function(){ doSearch(v); },280);
    });
    document.addEventListener('click',function(e){ if (!searchInput.contains(e.target)&&!dropdown.contains(e.target)) dropdown.style.display='none'; });

    function exactSearch(q){
        if (!q) return;
        fetch(BASE_URL+'/inventory/stock_operations.php?ajax=search_product&q='+encodeURIComponent(q))
            .then(function(r){return r.json();}).then(function(d){
                if (d.length===1){ selectProduct(d[0]); dropdown.style.display='none'; }
                else if (d.length>1){ renderDropdown(d); }
                else {
                    // Not a known product barcode/SKU — treat the scanned value as a serial number
                    dropdown.style.display = 'none';
                    showSerialMode(q);
                }
            });
    }

    // Show the "new serial detected" panel and wait for user to pick a product
    function showSerialMode(sn) {
        _pendingSerial = sn;
        smSerialLbl.textContent = sn;
        smSearch.value = '';
        smDrop.style.display = 'none';
        document.getElementById('si-product-panel').style.display = 'none';
        smPanel.style.display = '';
        searchInput.value = '';
        setTimeout(function(){ smSearch.focus(); }, 60);
    }
    function doSearch(q){
        fetch(BASE_URL+'/inventory/stock_operations.php?ajax=search_product&q='+encodeURIComponent(q))
            .then(function(r){return r.json();}).then(function(d){ renderDropdown(d); });
    }
    function renderDropdown(items){
        if (!items.length){ dropdown.style.display='none'; return; }
        dropdown.innerHTML='';
        items.forEach(function(p){
            var d=document.createElement('div');
            d.style.cssText='padding:.6rem 1rem;cursor:pointer;border-bottom:1px solid #F3F4F6;font-size:.9rem;display:flex;justify-content:space-between;align-items:center;';
            d.innerHTML='<div><span style="font-weight:600;">'+escHtml(p.name)+'</span> <span style="color:#9CA3AF;font-size:.8rem;">'+escHtml(p.sku)+'</span></div>'
                +'<span style="background:'+(p.is_serialised?'#EFF6FF':'#F0FDF4')+';color:'+(p.is_serialised?'#3B82F6':'#16A34A')+';padding:.15rem .45rem;border-radius:4px;font-size:.75rem;font-weight:600;">'+(p.is_serialised?'Serial':'Bulk')+'</span>';
            d.addEventListener('mousedown',function(e){e.preventDefault();selectProduct(p);dropdown.style.display='none';searchInput.value='';});
            d.addEventListener('mouseover',function(){this.style.background='#F9FAFB';});
            d.addEventListener('mouseout',function(){this.style.background='';});
            dropdown.appendChild(d);
        });
        dropdown.style.display='';
    }

    function selectProduct(p){
        selectedProduct=p;
        document.getElementById('si-panel-name').textContent=p.name;
        document.getElementById('si-panel-sku').textContent='['+p.sku+']';
        document.getElementById('si-panel-badge').textContent=p.is_serialised?'Serialised':'Bulk';
        document.getElementById('si-serial-section').style.display=p.is_serialised?'':'none';
        document.getElementById('si-bulk-section').style.display=p.is_serialised?'none':'';
        document.getElementById('si-product-panel').style.display='';
        if (p.is_serialised) { serialInput.value=''; serialStatus.textContent=''; setTimeout(function(){serialInput.focus();},50); }
        else { setTimeout(function(){ document.getElementById('si-bulk-qty').focus(); },50); }
    }

    function clearPanel(){
        selectedProduct=null;
        document.getElementById('si-product-panel').style.display='none';
        searchInput.value=''; searchInput.focus();
    }

    // Serial-mode product search
    smSearch.addEventListener('input', function(){
        var v = this.value.trim();
        smDrop.style.display = 'none';
        if (!v) return;
        clearTimeout(_smDebounce);
        _smDebounce = setTimeout(function(){
            fetch(BASE_URL+'/inventory/stock_operations.php?ajax=search_product&q='+encodeURIComponent(v))
                .then(function(r){return r.json();}).then(function(d){ renderSmDropdown(d); });
        }, 280);
    });
    document.addEventListener('click', function(e){
        if (!smSearch.contains(e.target) && !smDrop.contains(e.target)) smDrop.style.display='none';
    });

    function renderSmDropdown(items){
        if (!items.length){ smDrop.style.display='none'; return; }
        smDrop.innerHTML='';
        items.forEach(function(p){
            var d=document.createElement('div');
            d.style.cssText='padding:.6rem 1rem;cursor:pointer;border-bottom:1px solid #F3F4F6;font-size:.9rem;display:flex;justify-content:space-between;align-items:center;';
            d.innerHTML='<div><span style="font-weight:600;">'+escHtml(p.name)+'</span> <span style="color:#9CA3AF;font-size:.8rem;">'+escHtml(p.sku)+'</span></div>'
                +'<span style="background:'+(p.is_serialised?'#EFF6FF':'#F0FDF4')+';color:'+(p.is_serialised?'#3B82F6':'#16A34A')+';padding:.15rem .45rem;border-radius:4px;font-size:.75rem;font-weight:600;">'+(p.is_serialised?'Serial':'Bulk')+'</span>';
            d.addEventListener('mousedown', function(e){
                e.preventDefault();
                smDrop.style.display='none';
                assignSerialToProduct(p);
            });
            d.addEventListener('mouseover', function(){ this.style.background='#F9FAFB'; });
            d.addEventListener('mouseout',  function(){ this.style.background=''; });
            smDrop.appendChild(d);
        });
        smDrop.style.display='';
    }

    function assignSerialToProduct(p){
        if (!_pendingSerial) return;
        var sn = _pendingSerial;
        if (p.is_serialised){
            // Duplicate check
            if (items.some(function(it){ return it.serial===sn; })){
                smSearch.value=''; smDrop.style.display='none';
                smPanel.style.backgroundColor='#FEF2F2';
                smSerialLbl.style.background='#FEE2E2'; smSerialLbl.style.color='#DC2626';
                smSearch.placeholder='Already in this batch — scan another';
                return;
            }
            items.push({product_id:p.id, product_name:p.name, sku:p.sku, serial:sn, qty:1, is_serialised:1});
        } else {
            // Bulk product — add 1 unit (serial is ignored for bulk)
            var ex = items.find(function(it){ return it.product_id===p.id && !it.is_serialised; });
            if (ex) ex.qty++; else items.push({product_id:p.id, product_name:p.name, sku:p.sku, serial:'', qty:1, is_serialised:0});
        }
        _pendingSerial=null;
        smPanel.style.display='none';
        render(); updateSummary();
        searchInput.value=''; searchInput.focus();
    }

    function clearSerialMode(){
        _pendingSerial=null;
        smPanel.style.display='none';
        smSearch.value=''; smDrop.style.display='none';
        searchInput.value=''; searchInput.focus();
    }

    // Serial input
    serialInput.addEventListener('keydown',function(e){ if(e.key==='Enter'){e.preventDefault();addItem();} });
    serialInput.addEventListener('input',function(){
        var sn=this.value.trim();
        clearTimeout(_serialChk);
        if (!sn){serialStatus.textContent='';return;}
        // local dup check
        if (items.some(function(it){return it.serial===sn;})){
            serialStatus.innerHTML='<span style="color:#EF4444;">⚠ Already in this batch</span>'; return;
        }
        _serialChk=setTimeout(function(){
            fetch(BASE_URL+'/inventory/stock_operations.php?ajax=check_serial&sn='+encodeURIComponent(sn))
                .then(function(r){return r.json();}).then(function(d){
                    serialStatus.innerHTML=d.exists
                        ?'<span style="color:#EF4444;">✕ Already in system</span>'
                        :'<span style="color:#16A34A;">✓ Available</span>';
                });
        },300);
    });

    function addItem(){
        if (!selectedProduct) return;
        if (selectedProduct.is_serialised) {
            var sn=serialInput.value.trim();
            if (!sn){ serialInput.focus(); return; }
            if (items.some(function(it){return it.serial===sn;})){
                serialStatus.innerHTML='<span style="color:#EF4444;">⚠ Already added</span>'; return;
            }
            items.push({product_id:selectedProduct.id,product_name:selectedProduct.name,sku:selectedProduct.sku,serial:sn,qty:1,is_serialised:1});
            serialInput.value=''; serialStatus.textContent=''; serialInput.focus();
        } else {
            var qty=parseInt(document.getElementById('si-bulk-qty').value)||1;
            if (qty<1) return;
            // Merge same product
            var ex=items.find(function(it){return it.product_id===selectedProduct.id&&!it.is_serialised;});
            if (ex) ex.qty+=qty; else items.push({product_id:selectedProduct.id,product_name:selectedProduct.name,sku:selectedProduct.sku,serial:'',qty:qty,is_serialised:0});
        }
        render(); updateSummary();
    }

    function removeItem(idx){ items.splice(idx,1); render(); updateSummary(); }

    function clearAll(){ items=[]; render(); updateSummary(); }

    function render(){
        var tb=document.getElementById('si-items-tbody');
        var card=document.getElementById('si-items-card');
        document.getElementById('si-count-badge').textContent=items.length;
        card.style.display=items.length?'':'none';
        if (!items.length){tb.innerHTML='';return;}
        tb.innerHTML=items.map(function(it,i){
            return '<tr><td style="color:#9CA3AF;">'+(i+1)+'</td>'
                +'<td><div style="font-weight:600;font-size:.875rem;">'+escHtml(it.product_name)+'</div><div style="font-size:.78rem;color:#9CA3AF;font-family:monospace;">'+escHtml(it.sku)+'</div></td>'
                +'<td>'+(it.is_serialised?'<span style="font-family:monospace;font-size:.875rem;">'+escHtml(it.serial)+'</span>':'<span style="background:#F0FDF4;color:#16A34A;padding:.15rem .5rem;border-radius:4px;font-size:.8rem;font-weight:600;">×'+it.qty+' units</span>')+'</td>'
                +'<td><button type="button" onclick="SI.removeItem('+i+')" style="background:none;border:none;color:#EF4444;cursor:pointer;font-size:1rem;">✕</button></td></tr>';
        }).join('');
    }

    function updateSummary(){
        var empty=document.getElementById('si-summary-empty');
        var stats=document.getElementById('si-summary-stats');
        var btn  =document.getElementById('si-commit-btn');
        var whId =document.getElementById('si-warehouse').value;
        var whName='—'; WAREHOUSES.forEach(function(w){if(w.id==whId)whName=w.name;});
        if (!items.length){empty.style.display='';stats.style.display='none';btn.disabled=true;return;}
        var total=items.reduce(function(s,it){return s+it.qty;},0);
        var serials=items.filter(function(it){return it.is_serialised;}).length;
        var bulk=items.filter(function(it){return !it.is_serialised;}).reduce(function(s,it){return s+it.qty;},0);
        empty.style.display='none'; stats.style.display='';
        document.getElementById('si-stat-units').textContent=total;
        document.getElementById('si-stat-serials').textContent=serials;
        document.getElementById('si-stat-bulk').textContent=bulk;
        document.getElementById('si-stat-warehouse').textContent=whName;
        btn.disabled=!items.length||!whId;
    }
    document.getElementById('si-warehouse').addEventListener('change',updateSummary);

    function commit(){
        var whId=document.getElementById('si-warehouse').value;
        if (!whId){alert('Please select a destination warehouse.');return;}
        if (!items.length){alert('No items added.');return;}
        var isPo=document.querySelector('input[name="si_src"]:checked').value==='po';
        document.getElementById('si-fld-warehouse').value=whId;
        document.getElementById('si-fld-src-type').value=isPo?'po':'manual';
        document.getElementById('si-fld-po').value=isPo?document.getElementById('si-po-sel').value:'';
        document.getElementById('si-fld-ref').value=document.getElementById('si-ref').value;
        var cont=document.getElementById('si-hidden-items'); cont.innerHTML='';
        items.forEach(function(it){
            cont.innerHTML+='<input type="hidden" name="item_product_id[]" value="'+it.product_id+'">'
                +'<input type="hidden" name="item_serial_no[]" value="'+escAttr(it.serial)+'">'
                +'<input type="hidden" name="item_qty[]" value="'+it.qty+'">';
        });
        document.getElementById('si-form').submit();
    }

    return {addItem:addItem,removeItem:removeItem,clearAll:clearAll,clearPanel:clearPanel,clearSerialMode:clearSerialMode,commit:commit};
}());

// ============================================================
// MOVE STOCK (MV)
// ============================================================
var MV = (function(){
    var items=[];
    var serialInput=document.getElementById('mv-serial-input');
    var serialStatus=document.getElementById('mv-serial-status');
    var scanDrop=document.getElementById('mv-scan-drop');
    var _t=null, _st=null;

    // Live name search as user types
    serialInput.addEventListener('input',function(){
        clearTimeout(_st);
        scanDrop.style.display='none';
        var v=this.value.trim();
        if (!v) return;
        _st=setTimeout(function(){
            fetch(BASE_URL+'/inventory/stock_operations.php?ajax=search_product&q='+encodeURIComponent(v))
                .then(function(r){return r.json();}).then(function(prods){
                    if (!prods.length){scanDrop.style.display='none';return;}
                    scanDrop.innerHTML='';
                    prods.forEach(function(p){
                        var item=document.createElement('div');
                        item.style.cssText='padding:.6rem 1rem;cursor:pointer;border-bottom:1px solid #F3F4F6;font-size:.875rem;';
                        item.innerHTML='<span style="font-weight:600;">'+escHtml(p.name)+'</span> <span style="color:#9CA3AF;font-size:.8rem;">'+escHtml(p.sku)+'</span>'
                            +(p.barcode?'<span style="color:#CBD5E1;font-size:.75rem;margin-left:.4rem;">'+escHtml(p.barcode)+'</span>':'')
                            +(p.is_serialised?'':'<span style="background:#FEF9C3;color:#854D0E;padding:1px 5px;border-radius:3px;font-size:.72rem;margin-left:.4rem;">Bulk</span>');
                        item.addEventListener('mousedown',function(e){e.preventDefault();selectScanProduct(p);});
                        scanDrop.appendChild(item);
                    });
                    scanDrop.style.display='';
                });
        },280);
    });

    serialInput.addEventListener('keydown',function(e){
        if (e.key==='Enter'){e.preventDefault();scanDrop.style.display='none';lookupAndAdd(this.value.trim());}
        if (e.key==='Escape'){scanDrop.style.display='none';}
    });

    document.addEventListener('click',function(e){
        if (!serialInput.contains(e.target)&&!scanDrop.contains(e.target)) scanDrop.style.display='none';
    });

    function selectScanProduct(p){
        scanDrop.style.display='none';
        serialInput.value='';
        if (p.is_serialised){
            serialStatus.innerHTML='<span style="color:#3B82F6;">Selected: <strong>'+escHtml(p.name)+'</strong> — now scan each unit\'s serial number.</span>';
            serialInput.focus();
        } else {
            bpHidden.dataset.id=p.id; bpHidden.dataset.name=p.name; bpHidden.dataset.sku=p.sku;
            bpSearch.value=p.name+' ('+p.sku+')';
            bpDrop.style.display='none';
            document.getElementById('mv-bulk-form').style.display='block';
            serialStatus.innerHTML='<span style="color:#16A34A;">✓ Bulk product selected: <strong>'+escHtml(p.name)+'</strong> — choose source warehouse &amp; qty below.</span>';
        }
    }

    function lookupAndAdd(sn){
        if (!sn) return;
        if (items.some(function(it){return it.serial===sn;})){
            serialStatus.innerHTML='<span style="color:#F59E0B;">⚠ Already added: '+escHtml(sn)+'</span>'; return;
        }
        serialStatus.innerHTML='<span style="color:#9CA3AF;">Looking up '+escHtml(sn)+'…</span>';
        fetch(BASE_URL+'/inventory/stock_operations.php?ajax=lookup_serial&sn='+encodeURIComponent(sn))
            .then(function(r){return r.json();}).then(function(d){
                if (!d.found){
                    // Fallback: try exact barcode / SKU product lookup
                    return fetch(BASE_URL+'/inventory/stock_operations.php?ajax=search_product&exact=1&q='+encodeURIComponent(sn))
                        .then(function(r){return r.json();}).then(function(products){
                            if (!products.length){
                                serialStatus.innerHTML='<span style="color:#EF4444;">✕ Not found as serial, barcode, or SKU.</span>';
                                return;
                            }
                            selectScanProduct(products[0]);
                        });
                }
                var toWid=parseInt(document.getElementById('mv-to-warehouse').value)||0;
                if (toWid && d.warehouse_id==toWid){
                    serialStatus.innerHTML='<span style="color:#F59E0B;">⚠ Already at the destination warehouse.</span>'; return;
                }
                items.push({product_id:d.product_id,product_name:d.product_name,sku:d.sku,serial:d.serial_no,from_wid:d.warehouse_id,from_wname:d.warehouse_name,qty:1,is_serialised:1});
                serialStatus.innerHTML='<span style="color:#16A34A;">✓ Added: '+escHtml(d.product_name)+' ('+escHtml(d.serial_no)+') from '+escHtml(d.warehouse_name)+'</span>';
                serialInput.value=''; serialInput.focus();
                render(); updateSummary();
            }).catch(function(){serialStatus.innerHTML='<span style="color:#EF4444;">Network error.</span>';});
    }

    // Bulk product search
    var bpSearch=document.getElementById('mv-bulk-product-search');
    var bpDrop=document.getElementById('mv-bulk-product-drop');
    var bpHidden=document.getElementById('mv-bulk-product-hidden');
    var _bpt=null;
    bpSearch.addEventListener('input',function(){
        clearTimeout(_bpt); var v=this.value.trim();
        if (!v){bpDrop.style.display='none';return;}
        _bpt=setTimeout(function(){
            fetch(BASE_URL+'/inventory/stock_operations.php?ajax=search_product&q='+encodeURIComponent(v))
                .then(function(r){return r.json();}).then(function(d){
                    renderBulkDrop(d,bpDrop,bpSearch,bpHidden);
                });
        },280);
    });

    function addBulk(){
        var pid=parseInt(bpHidden.dataset.id)||0;
        var pname=bpHidden.dataset.name||''; var psku=bpHidden.dataset.sku||'';
        var fromWid=parseInt(document.getElementById('mv-bulk-from-wh').value)||0;
        var qty=parseInt(document.getElementById('mv-bulk-qty').value)||1;
        if (!pid){alert('Select a product.');return;}
        if (!fromWid){alert('Select a source warehouse.');return;}
        if (qty<1){alert('Qty must be at least 1.');return;}
        var toWid=parseInt(document.getElementById('mv-to-warehouse').value)||0;
        if (toWid&&fromWid==toWid){alert('Source and destination are the same.');return;}
        var whName=''; WAREHOUSES.forEach(function(w){if(w.id==fromWid)whName=w.name;});
        items.push({product_id:pid,product_name:pname,sku:psku,serial:'',from_wid:fromWid,from_wname:whName,qty:qty,is_serialised:0});
        render(); updateSummary();
        bpSearch.value=''; bpHidden.dataset.id=''; bpHidden.dataset.name=''; bpHidden.dataset.sku='';
        document.getElementById('mv-bulk-qty').value=1;
    }

    function removeItem(i){items.splice(i,1);render();updateSummary();}
    function clearAll(){items=[];render();updateSummary();}

    function render(){
        var tb=document.getElementById('mv-items-tbody');
        var card=document.getElementById('mv-items-card');
        document.getElementById('mv-count-badge').textContent=items.length;
        card.style.display=items.length?'':'none';
        if (!items.length){tb.innerHTML='';return;}
        tb.innerHTML=items.map(function(it,i){
            return '<tr><td style="color:#9CA3AF;">'+(i+1)+'</td>'
                +'<td><div style="font-weight:600;font-size:.875rem;">'+escHtml(it.product_name)+'</div><div style="font-size:.78rem;color:#9CA3AF;font-family:monospace;">'+escHtml(it.sku)+'</div></td>'
                +'<td>'+(it.is_serialised?'<span style="font-family:monospace;font-size:.875rem;">'+escHtml(it.serial)+'</span>':'<span style="background:#FEF9C3;color:#854D0E;padding:.15rem .5rem;border-radius:4px;font-size:.8rem;font-weight:600;">×'+it.qty+' units</span>')+'</td>'
                +'<td style="font-size:.85rem;color:#6B7280;">'+escHtml(it.from_wname)+'</td>'
                +'<td><button type="button" onclick="MV.removeItem('+i+')" style="background:none;border:none;color:#EF4444;cursor:pointer;font-size:1rem;">✕</button></td></tr>';
        }).join('');
    }

    function updateSummary(){
        var e=document.getElementById('mv-summary-empty'),s=document.getElementById('mv-summary-stats'),b=document.getElementById('mv-commit-btn');
        if (!items.length){e.style.display='';s.style.display='none';b.disabled=true;return;}
        e.style.display='none';s.style.display='';
        document.getElementById('mv-stat-items').textContent=items.length;
        document.getElementById('mv-stat-units').textContent=items.reduce(function(x,it){return x+it.qty;},0);
        b.disabled=false;
    }

    function commit(){
        var toWid=document.getElementById('mv-to-warehouse').value;
        var ch=document.getElementById('mv-channel').value;
        if (!toWid){alert('Select a destination warehouse.');return;}
        if (!ch){alert('Select a channel.');return;}
        if (!items.length){alert('No items added.');return;}
        document.getElementById('mv-fld-to-wh').value=toWid;
        document.getElementById('mv-fld-channel').value=ch;
        document.getElementById('mv-fld-invoice').value=document.getElementById('mv-invoice').value;
        document.getElementById('mv-fld-notes').value=document.getElementById('mv-notes').value;
        var cont=document.getElementById('mv-hidden-items'); cont.innerHTML='';
        items.forEach(function(it){
            cont.innerHTML+='<input type="hidden" name="item_product_id[]" value="'+it.product_id+'">'
                +'<input type="hidden" name="item_serial_no[]" value="'+escAttr(it.serial)+'">'
                +'<input type="hidden" name="item_from_warehouse_id[]" value="'+it.from_wid+'">'
                +'<input type="hidden" name="item_qty[]" value="'+it.qty+'">'
                +'<input type="hidden" name="item_is_serialised[]" value="'+it.is_serialised+'">';
        });
        document.getElementById('mv-form').submit();
    }

    return {addBulk:addBulk,removeItem:removeItem,clearAll:clearAll,commit:commit};
}());

// ============================================================
// TAKE OUT (TO)
// ============================================================
var TO = (function(){
    var items=[];
    var serialInput=document.getElementById('to-serial-input');
    var serialStatus=document.getElementById('to-serial-status');
    var scanDrop=document.getElementById('to-scan-drop');
    var _st=null;

    // Live name search as user types
    serialInput.addEventListener('input',function(){
        clearTimeout(_st);
        scanDrop.style.display='none';
        var v=this.value.trim();
        if (!v) return;
        _st=setTimeout(function(){
            fetch(BASE_URL+'/inventory/stock_operations.php?ajax=search_product&q='+encodeURIComponent(v))
                .then(function(r){return r.json();}).then(function(prods){
                    if (!prods.length){scanDrop.style.display='none';return;}
                    scanDrop.innerHTML='';
                    prods.forEach(function(p){
                        var item=document.createElement('div');
                        item.style.cssText='padding:.6rem 1rem;cursor:pointer;border-bottom:1px solid #F3F4F6;font-size:.875rem;';
                        item.innerHTML='<span style="font-weight:600;">'+escHtml(p.name)+'</span> <span style="color:#9CA3AF;font-size:.8rem;">'+escHtml(p.sku)+'</span>'
                            +(p.barcode?'<span style="color:#CBD5E1;font-size:.75rem;margin-left:.4rem;">'+escHtml(p.barcode)+'</span>':'')
                            +(p.is_serialised?'':'<span style="background:#FEF9C3;color:#854D0E;padding:1px 5px;border-radius:3px;font-size:.72rem;margin-left:.4rem;">Bulk</span>');
                        item.addEventListener('mousedown',function(e){e.preventDefault();selectScanProduct(p);});
                        scanDrop.appendChild(item);
                    });
                    scanDrop.style.display='';
                });
        },280);
    });

    serialInput.addEventListener('keydown',function(e){
        if (e.key==='Enter'){e.preventDefault();scanDrop.style.display='none';lookupAndAdd(this.value.trim());}
        if (e.key==='Escape'){scanDrop.style.display='none';}
    });

    document.addEventListener('click',function(e){
        if (!serialInput.contains(e.target)&&!scanDrop.contains(e.target)) scanDrop.style.display='none';
    });

    function selectScanProduct(p){
        scanDrop.style.display='none';
        serialInput.value='';
        if (p.is_serialised){
            serialStatus.innerHTML='<span style="color:#3B82F6;">Selected: <strong>'+escHtml(p.name)+'</strong> — now scan each unit\'s serial number.</span>';
            serialInput.focus();
        } else {
            var tBpHidden=document.getElementById('to-bulk-product-hidden');
            var tBpSearch=document.getElementById('to-bulk-product-search');
            var tBpDrop=document.getElementById('to-bulk-product-drop');
            tBpHidden.dataset.id=p.id; tBpHidden.dataset.name=p.name; tBpHidden.dataset.sku=p.sku;
            tBpSearch.value=p.name+' ('+p.sku+')';
            tBpDrop.style.display='none';
            document.getElementById('to-bulk-form').style.display='block';
            serialStatus.innerHTML='<span style="color:#16A34A;">✓ Bulk product selected: <strong>'+escHtml(p.name)+'</strong> — choose warehouse &amp; qty below.</span>';
        }
    }

    function lookupAndAdd(sn){
        if (!sn) return;
        if (items.some(function(it){return it.serial===sn;})){
            serialStatus.innerHTML='<span style="color:#F59E0B;">⚠ Already added.</span>'; return;
        }
        serialStatus.innerHTML='<span style="color:#9CA3AF;">Looking up…</span>';
        fetch(BASE_URL+'/inventory/stock_operations.php?ajax=lookup_serial&sn='+encodeURIComponent(sn))
            .then(function(r){return r.json();}).then(function(d){
                if (!d.found){
                    // Fallback: try exact barcode / SKU product lookup
                    return fetch(BASE_URL+'/inventory/stock_operations.php?ajax=search_product&exact=1&q='+encodeURIComponent(sn))
                        .then(function(r){return r.json();}).then(function(products){
                            if (!products.length){
                                serialStatus.innerHTML='<span style="color:#EF4444;">✕ Not found as serial, barcode, or SKU.</span>';
                                return;
                            }
                            selectScanProduct(products[0]);
                        });
                }
                items.push({product_id:d.product_id,product_name:d.product_name,sku:d.sku,serial:d.serial_no,wid:d.warehouse_id,wname:d.warehouse_name,qty:1,is_serialised:1});
                serialStatus.innerHTML='<span style="color:#16A34A;">✓ Added: '+escHtml(d.product_name)+' ('+escHtml(d.serial_no)+') from '+escHtml(d.warehouse_name)+'</span>';
                serialInput.value=''; serialInput.focus();
                render(); updateSummary();
            }).catch(function(){serialStatus.innerHTML='<span style="color:#EF4444;">Network error.</span>';});
    }

    // Bulk
    var bpSearch=document.getElementById('to-bulk-product-search');
    var bpDrop=document.getElementById('to-bulk-product-drop');
    var bpHidden=document.getElementById('to-bulk-product-hidden');
    var _bpt=null;
    bpSearch.addEventListener('input',function(){
        clearTimeout(_bpt); var v=this.value.trim();
        if (!v){bpDrop.style.display='none';return;}
        _bpt=setTimeout(function(){
            fetch(BASE_URL+'/inventory/stock_operations.php?ajax=search_product&q='+encodeURIComponent(v))
                .then(function(r){return r.json();}).then(function(d){
                    renderBulkDrop(d,bpDrop,bpSearch,bpHidden);
                });
        },280);
    });

    function addBulk(){
        var pid=parseInt(bpHidden.dataset.id)||0;
        var pname=bpHidden.dataset.name||''; var psku=bpHidden.dataset.sku||'';
        var wid=parseInt(document.getElementById('to-bulk-wh').value)||0;
        var qty=parseInt(document.getElementById('to-bulk-qty').value)||1;
        if (!pid){alert('Select a product.');return;}
        if (!wid){alert('Select a warehouse.');return;}
        if (qty<1){alert('Qty must be at least 1.');return;}
        var whName=''; WAREHOUSES.forEach(function(w){if(w.id==wid)whName=w.name;});
        items.push({product_id:pid,product_name:pname,sku:psku,serial:'',wid:wid,wname:whName,qty:qty,is_serialised:0});
        render(); updateSummary();
        bpSearch.value=''; bpHidden.dataset.id=''; bpHidden.dataset.name=''; bpHidden.dataset.sku='';
        document.getElementById('to-bulk-qty').value=1;
    }

    function removeItem(i){items.splice(i,1);render();updateSummary();}
    function clearAll(){items=[];render();updateSummary();}

    function render(){
        var tb=document.getElementById('to-items-tbody');
        var card=document.getElementById('to-items-card');
        document.getElementById('to-count-badge').textContent=items.length;
        card.style.display=items.length?'':'none';
        if (!items.length){tb.innerHTML='';return;}
        tb.innerHTML=items.map(function(it,i){
            return '<tr><td style="color:#9CA3AF;">'+(i+1)+'</td>'
                +'<td><div style="font-weight:600;font-size:.875rem;">'+escHtml(it.product_name)+'</div><div style="font-size:.78rem;color:#9CA3AF;font-family:monospace;">'+escHtml(it.sku)+'</div></td>'
                +'<td>'+(it.is_serialised?'<span style="font-family:monospace;font-size:.875rem;">'+escHtml(it.serial)+'</span>':'<span style="background:#FEE2E2;color:#991B1B;padding:.15rem .5rem;border-radius:4px;font-size:.8rem;font-weight:600;">×'+it.qty+' units</span>')+'</td>'
                +'<td style="font-size:.85rem;color:#6B7280;">'+escHtml(it.wname)+'</td>'
                +'<td><button type="button" onclick="TO.removeItem('+i+')" style="background:none;border:none;color:#EF4444;cursor:pointer;font-size:1rem;">✕</button></td></tr>';
        }).join('');
    }

    function updateSummary(){
        var e=document.getElementById('to-summary-empty'),s=document.getElementById('to-summary-stats'),b=document.getElementById('to-commit-btn');
        if (!items.length){e.style.display='';s.style.display='none';b.disabled=true;return;}
        e.style.display='none';s.style.display='';
        document.getElementById('to-stat-items').textContent=items.length;
        document.getElementById('to-stat-units').textContent=items.reduce(function(x,it){return x+it.qty;},0);
        b.disabled=false;
    }

    function commit(){
        var ch=document.getElementById('to-channel').value;
        if (!ch){alert('Select a channel / reason.');return;}
        if (!items.length){alert('No items added.');return;}
        if (!confirm('Remove '+items.length+' item(s) from stock? This cannot be undone.')) return;
        document.getElementById('to-fld-channel').value=ch;
        document.getElementById('to-fld-invoice').value=document.getElementById('to-invoice').value;
        document.getElementById('to-fld-notes').value=document.getElementById('to-notes').value;
        var cont=document.getElementById('to-hidden-items'); cont.innerHTML='';
        items.forEach(function(it){
            cont.innerHTML+='<input type="hidden" name="item_product_id[]" value="'+it.product_id+'">'
                +'<input type="hidden" name="item_serial_no[]" value="'+escAttr(it.serial)+'">'
                +'<input type="hidden" name="item_warehouse_id[]" value="'+it.wid+'">'
                +'<input type="hidden" name="item_qty[]" value="'+it.qty+'">'
                +'<input type="hidden" name="item_is_serialised[]" value="'+it.is_serialised+'">';
        });
        document.getElementById('to-form').submit();
    }

    return {addBulk:addBulk,removeItem:removeItem,clearAll:clearAll,commit:commit};
}());

// ============================================================
// SHARED HELPERS
// ============================================================
function escHtml(s){ var d=document.createElement('div');d.appendChild(document.createTextNode(s||''));return d.innerHTML; }
function escAttr(s){ return (s||'').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function renderBulkDrop(results, drop, input, hidden){
    if (!results.length){drop.style.display='none';return;}
    drop.innerHTML='';
    results.forEach(function(p){
        var d=document.createElement('div');
        d.style.cssText='padding:.5rem .75rem;cursor:pointer;border-bottom:1px solid #F3F4F6;font-size:.875rem;';
        d.innerHTML='<strong>'+escHtml(p.name)+'</strong> <span style="color:#9CA3AF;font-size:.8rem;">'+escHtml(p.sku)+'</span>';
        d.addEventListener('mousedown',function(e){
            e.preventDefault();
            input.value=p.name;
            hidden.dataset.id=p.id; hidden.dataset.name=p.name; hidden.dataset.sku=p.sku;
            drop.style.display='none';
        });
        d.addEventListener('mouseover',function(){this.style.background='#F9FAFB';});
        d.addEventListener('mouseout',function(){this.style.background='';});
        drop.appendChild(d);
    });
    drop.style.display='';
}
document.addEventListener('click',function(e){
    ['mv-bulk-product-drop','to-bulk-product-drop'].forEach(function(id){
        var el=document.getElementById(id); if(el) el.style.display='none';
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
