<?php
// ============================================================
// Blackview SA Portal — Invoice Helper Functions
// ============================================================

/**
 * finaliseDraftStock()
 * Deducts stock for all physical line items on a draft invoice.
 * Auto-assigns serials (FIFO) for serialised products.
 * Throws Exception on insufficient stock.
 */
function finaliseDraftStock(PDO $pdo, int $invoiceId, string $invoiceNo, string $channel): void {

    // Load all line items with product details
    $itemsQ = $pdo->prepare(
        "SELECT ii.id, ii.product_id, ii.qty, ii.unit_price, ii.vat_rate, ii.vat_amount, ii.line_total,
                COALESCE(ii.serial_no,'') AS serial_no,
                p.name AS prod_name,
                COALESCE(p.product_type,'physical') AS product_type,
                COALESCE(p.is_serialised,1)         AS is_serialised
         FROM invoice_items ii
         JOIN products p ON p.id = ii.product_id
         WHERE ii.invoice_id = :id"
    );
    $itemsQ->execute([':id' => $invoiceId]);
    $lineItems = $itemsQ->fetchAll();

    $uid = $_SESSION['user_id'] ?? null;

    // Prepared statements (reused across loop)
    $updStockSold  = $pdo->prepare("UPDATE stock_items SET status='sold' WHERE serial_no=:sn");
    $decInv        = $pdo->prepare("UPDATE inventory_stock SET qty=GREATEST(0,qty-:qty) WHERE product_id=:pid AND warehouse_id=:wh");
    $updItemSnWh   = $pdo->prepare("UPDATE invoice_items SET serial_no=:sn, warehouse_id=:wh, qty=1 WHERE id=:id");
    $updItemWh     = $pdo->prepare("UPDATE invoice_items SET warehouse_id=:wh WHERE id=:id");
    $insExtraItem  = $pdo->prepare(
        "INSERT INTO invoice_items (invoice_id,product_id,serial_no,warehouse_id,qty,unit_price,vat_rate,vat_amount,line_total)
         VALUES (:inv,:prod,:sn,:wh,1,:price,:vr,:va,:lt)"
    );
    $insMov        = $pdo->prepare(
        "INSERT INTO stock_movements (product_id,from_warehouse_id,to_warehouse_id,qty,moved_by,invoice_no,channel,notes,moved_at)
         VALUES (:prod,:fwh,NULL,:qty,:uid,:inv,:ch,:notes,NOW())"
    );
    $insMovSn      = $pdo->prepare("INSERT INTO movement_serials (movement_id,serial_no) VALUES (:mid,:sn)");

    foreach ($lineItems as $li) {
        if ($li['product_type'] === 'service') continue;

        $iid = (int)$li['id'];
        $pid = (int)$li['product_id'];
        $qty = (int)$li['qty'];

        if ($li['is_serialised']) {
            // --------------------------------------------------
            // SERIALISED — auto-assign oldest available serials
            // --------------------------------------------------
            $snQ = $pdo->prepare(
                "SELECT serial_no, warehouse_id FROM stock_items
                 WHERE product_id = :pid AND status = 'in_stock'
                 ORDER BY id ASC LIMIT :lim"
            );
            $snQ->bindValue(':pid', $pid, PDO::PARAM_INT);
            $snQ->bindValue(':lim', $qty, PDO::PARAM_INT);
            $snQ->execute();
            $serials = $snQ->fetchAll();

            if (count($serials) < $qty) {
                throw new Exception(
                    "Not enough serialised stock for \"{$li['prod_name']}\". " .
                    "Need {$qty}, only " . count($serials) . " available."
                );
            }

            // Update first serial onto the existing invoice_items row
            $s0 = $serials[0];
            $updItemSnWh->execute([':sn' => $s0['serial_no'], ':wh' => $s0['warehouse_id'], ':id' => $iid]);
            $updStockSold->execute([':sn' => $s0['serial_no']]);
            $decInv->execute([':qty' => 1, ':pid' => $pid, ':wh' => $s0['warehouse_id']]);
            $insMov->execute([
                ':prod'  => $pid, ':fwh'  => $s0['warehouse_id'], ':qty' => 1,
                ':uid'   => $uid, ':inv'  => $invoiceNo, ':ch'  => $channel,
                ':notes' => "Sale — Invoice $invoiceNo",
            ]);
            $insMovSn->execute([':mid' => (int)$pdo->lastInsertId(), ':sn' => $s0['serial_no']]);

            // Insert additional rows for remaining serials
            for ($k = 1; $k < count($serials); $k++) {
                $sk = $serials[$k];
                $insExtraItem->execute([
                    ':inv'   => $invoiceId, ':prod' => $pid,
                    ':sn'    => $sk['serial_no'], ':wh'  => $sk['warehouse_id'],
                    ':price' => $li['unit_price'],  ':vr'  => $li['vat_rate'],
                    ':va'    => $li['vat_amount'],  ':lt'  => $li['line_total'],
                ]);
                $updStockSold->execute([':sn' => $sk['serial_no']]);
                $decInv->execute([':qty' => 1, ':pid' => $pid, ':wh' => $sk['warehouse_id']]);
                $insMov->execute([
                    ':prod'  => $pid, ':fwh'  => $sk['warehouse_id'], ':qty' => 1,
                    ':uid'   => $uid, ':inv'  => $invoiceNo, ':ch'  => $channel,
                    ':notes' => "Sale — Invoice $invoiceNo",
                ]);
                $insMovSn->execute([':mid' => (int)$pdo->lastInsertId(), ':sn' => $sk['serial_no']]);
            }

        } else {
            // --------------------------------------------------
            // NON-SERIALISED — pick warehouse with most stock
            // --------------------------------------------------
            $whQ = $pdo->prepare(
                "SELECT warehouse_id, qty FROM inventory_stock
                 WHERE product_id = :pid AND qty >= :qty
                 ORDER BY qty DESC LIMIT 1"
            );
            $whQ->execute([':pid' => $pid, ':qty' => $qty]);
            $whRow = $whQ->fetch();

            if (!$whRow) {
                throw new Exception(
                    "Insufficient stock for \"{$li['prod_name']}\" (need {$qty})."
                );
            }

            $updItemWh->execute([':wh' => $whRow['warehouse_id'], ':id' => $iid]);
            $decInv->execute([':qty' => $qty, ':pid' => $pid, ':wh' => $whRow['warehouse_id']]);
            $insMov->execute([
                ':prod'  => $pid, ':fwh'  => $whRow['warehouse_id'], ':qty' => $qty,
                ':uid'   => $uid, ':inv'  => $invoiceNo, ':ch'  => $channel,
                ':notes' => "Sale — Invoice $invoiceNo",
            ]);
        }
    }
}
