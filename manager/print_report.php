<?php
// manager/print_report.php
// Shows a styled browser preview of the report.
// ?download=1 streams the .docx file via PhpWord.
require_once '../includes/config.php';
require_once '../includes/session.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Style\Table as TableStyle;

SessionManager::requireManagerOrStaff();

$functions  = new SystemFunctions();
$userId     = SessionManager::getUserId();
$user       = $functions->getUserById($userId);

$reportType             = $_GET['type']              ?? 'daily';
$reportDate             = $_GET['date']              ?? date('Y-m-d');
$dateFrom               = $_GET['date_from']         ?? date('Y-m-d', strtotime('-30 days'));
$dateTo                 = $_GET['date_to']            ?? date('Y-m-d');
$unpaidFilterEmployee   = $_GET['unpaid_employee']   ?? '';
$unpaidFilterDepartment = $_GET['unpaid_department'] ?? '';
$unpaidFilterStatus     = $_GET['unpaid_status']     ?? '';
$isDownload             = isset($_GET['download']) && $_GET['download'] === '1';

/* ══════════════════════════════════════════════════════════════════════════════
   DATA FETCH
══════════════════════════════════════════════════════════════════════════════ */
$transactions     = [];
$salesLedger      = [];
$unpaidDeductions = [];
$summary = [
    'total_sales'        => 0, 'total_transactions'  => 0,
    'cash_payments'      => 0, 'gcash_payments'      => 0,
    'bank_payments'      => 0, 'card_payments'       => 0,
    'paylater_payments'  => 0, 'salary_payments'     => 0,
    'total_quantity'     => 0, 'average_transaction' => 0,
    'total_unpaid'       => 0, 'total_unpaid_count'  => 0,
];

try {
    $db = (new Database())->getConnection();

    if ($reportType === 'unpaid') {
        $whereConditions = ["sd.deduction_status IN ('pending','partial','active')"];
        $params = [];
        if (!empty($unpaidFilterEmployee)) {
            $whereConditions[] = "(u.full_name LIKE :employee OR u.email LIKE :employee OR u.employee_id LIKE :employee)";
            $params[':employee'] = '%' . $unpaidFilterEmployee . '%';
        }
        if (!empty($unpaidFilterDepartment)) {
            $whereConditions[] = "u.department = :department";
            $params[':department'] = $unpaidFilterDepartment;
        }
        if (!empty($unpaidFilterStatus)) {
            $whereConditions[] = "sd.deduction_status = :status";
            $params[':status'] = $unpaidFilterStatus;
        }
        $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
        $stmt = $db->prepare("SELECT sd.*, u.full_name as customer_name, u.email,
                                      u.department, u.position, u.employee_id,
                                      o.order_date, o.total_amount as order_total
                               FROM salary_deductions sd
                               JOIN users u ON sd.user_id = u.user_id
                               LEFT JOIN orders o ON sd.order_id = o.order_id
                               $whereClause
                               AND sd.deduction_id IN (
                                   SELECT MAX(deduction_id) FROM salary_deductions
                                   WHERE deduction_status IN ('pending','partial','active')
                                   GROUP BY order_id
                               )
                               ORDER BY sd.remaining_balance DESC");
        $stmt->execute($params);
        $unpaidDeductions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary['total_unpaid_count'] = count($unpaidDeductions);
        $summary['total_unpaid']       = array_sum(array_column($unpaidDeductions, 'remaining_balance'));

        foreach ($unpaidDeductions as &$d) {
            $h = $db->prepare("SELECT * FROM deduction_history WHERE deduction_id=:id ORDER BY created_at DESC");
            $h->execute([':id' => $d['deduction_id']]);
            $d['payment_history'] = $h->fetchAll(PDO::FETCH_ASSOC);
            $d['total_paid']      = array_sum(array_column($d['payment_history'], 'amount_deducted'));
        }
        unset($d);

    } else {
        $baseCols = "o.order_id, o.user_id, o.total_amount, o.payment_method,
                     o.order_status, o.order_date, o.created_at, o.remarks,
                     u.full_name as customer_name, u.email, u.department,
                     oi.quantity, oi.subtotal, fp.fish_name, fp.price_per_kg,
                     'cash' as source, o.order_date as transaction_date";
        $salCols  = "sd.order_id, sd.user_id, sd.total_amount,
                     'salary_deduction' as payment_method, 'completed' as order_status,
                     sd.completed_at as order_date, sd.created_at, sd.remarks,
                     u.full_name as customer_name, u.email, u.department,
                     oi.quantity, (oi.quantity*oi.price_per_kg) as subtotal,
                     fp.fish_name, oi.price_per_kg as price_per_kg,
                     'salary_deduction' as source, sd.completed_at as transaction_date";
        $salFilter = "AND sd.deduction_id IN (
                          SELECT MAX(deduction_id) FROM salary_deductions
                          WHERE deduction_status='completed' GROUP BY order_id
                      )";

        if ($reportType === 'daily') {
            $sql = "SELECT * FROM (
                        SELECT $baseCols FROM orders o
                        JOIN users u ON o.user_id=u.user_id
                        JOIN order_items oi ON o.order_id=oi.order_id
                        JOIN fish_products fp ON oi.product_id=fp.product_id
                        WHERE DATE(o.order_date)=:d1
                          AND o.order_status='completed'
                          AND o.payment_method!='salary_deduction'
                        UNION ALL
                        SELECT $salCols FROM salary_deductions sd
                        JOIN users u ON sd.user_id=u.user_id
                        JOIN order_items oi ON sd.order_id=oi.order_id
                        JOIN fish_products fp ON oi.product_id=fp.product_id
                        WHERE DATE(sd.completed_at)=:d2
                          AND sd.deduction_status='completed' $salFilter
                    ) c ORDER BY order_date DESC, order_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([':d1' => $reportDate, ':d2' => $reportDate]);
        } else {
            $sql = "SELECT * FROM (
                        SELECT $baseCols FROM orders o
                        JOIN users u ON o.user_id=u.user_id
                        JOIN order_items oi ON o.order_id=oi.order_id
                        JOIN fish_products fp ON oi.product_id=fp.product_id
                        WHERE DATE(o.order_date) BETWEEN :df1 AND :dt1
                          AND o.order_status='completed'
                          AND o.payment_method!='salary_deduction'
                        UNION ALL
                        SELECT $salCols FROM salary_deductions sd
                        JOIN users u ON sd.user_id=u.user_id
                        JOIN order_items oi ON sd.order_id=oi.order_id
                        JOIN fish_products fp ON oi.product_id=fp.product_id
                        WHERE DATE(sd.completed_at) BETWEEN :df2 AND :dt2
                          AND sd.deduction_status='completed' $salFilter
                    ) c ORDER BY order_date DESC, order_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([':df1'=>$dateFrom,':dt1'=>$dateTo,':df2'=>$dateFrom,':dt2'=>$dateTo]);
        }

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($transactions as $t) {
            $pn = $t['fish_name'] ?? 'Unknown';
            if (!isset($salesLedger[$pn]))
                $salesLedger[$pn] = ['transactions'=>[],'total_quantity'=>0,'total_revenue'=>0];
            $salesLedger[$pn]['transactions'][] = $t;
            $salesLedger[$pn]['total_quantity'] += $t['quantity'] ?? 0;
            $salesLedger[$pn]['total_revenue']  += $t['subtotal'] ?? 0;
        }

        $summary['total_transactions'] = count($transactions);
        $summary['total_quantity']     = array_sum(array_column($transactions,'quantity'));
        $summary['total_sales']        = array_sum(array_column($transactions,'subtotal'));
        foreach ($transactions as $t) {
            $a = $t['subtotal'] ?? 0;
            switch ($t['payment_method'] ?? '') {
                case 'cash':             $summary['cash_payments']    += $a; break;
                case 'gcash':            $summary['gcash_payments']   += $a; break;
                case 'bank_transfer':    $summary['bank_payments']    += $a; break;
                case 'card':             $summary['card_payments']    += $a; break;
                case 'pay_later':        $summary['paylater_payments']+= $a; break;
                case 'salary_deduction': $summary['salary_payments']  += $a; break;
            }
        }
        $summary['average_transaction'] = $summary['total_transactions'] > 0
            ? $summary['total_sales'] / $summary['total_transactions'] : 0;
    }
} catch (PDOException $e) {
    error_log("Reports error: " . $e->getMessage());
}

/* ══════════════════════════════════════════════════════════════════════════════
   SHARED HELPERS
══════════════════════════════════════════════════════════════════════════════ */
function peso($n) { return '₱' . number_format($n, 2); }
function kg($n)   { return number_format($n, 2) . ' kg'; }
function pmLabel($pm) {
    if ($pm === 'salary_deduction') return 'Salary Deduct';
    return ucfirst(str_replace('_', ' ', $pm ?: 'N/A'));
}

// PHP 7.x compatible title assignment (no match expression)
if ($reportType === 'unpaid') {
    $reportTitle = 'Unpaid Deductions Report';
} elseif ($reportType === 'range') {
    $reportTitle = 'Sales Summary Report';
} else {
    $reportTitle = 'Daily Sales Report';
}

if ($reportType === 'daily') {
    $periodDisplay = date('F d, Y', strtotime($reportDate));
} elseif ($reportType === 'unpaid') {
    $periodDisplay = 'Active Unpaid Deductions';
} else {
    $periodDisplay = date('F d, Y', strtotime($dateFrom)) . ' – ' . date('F d, Y', strtotime($dateTo));
}

$preparedBy   = htmlspecialchars($user['full_name'] ?? 'System Administrator');
$preparedDate = date('F d, Y');
$docId        = 'RPT-' . strtoupper($reportType) . '-' . date('Ymd-His');

/* ══════════════════════════════════════════════════════════════════════════════
   WORD DOWNLOAD  (?download=1)
══════════════════════════════════════════════════════════════════════════════ */
if ($isDownload) {
    $phpWord = new PhpWord();
    $phpWord->setDefaultFontName('Arial');
    $phpWord->setDefaultFontSize(10);

    $section = $phpWord->addSection([
        'paperSize'    => 'Legal',
        'marginTop'    => 720,
        'marginBottom' => 720,
        'marginLeft'   => 720,
        'marginRight'  => 720,
    ]);

    $footer = $section->addFooter();
    $footer->addPreserveText('Page {PAGE} of {NUMPAGES}',
        ['size' => 8, 'color' => '888888'], ['alignment' => Jc::CENTER]);

    $DARK  = '0F2B5C';
    $MED   = '1E3A8A';
    $LGRAY = 'E5E7EB';
    $ALT   = 'F8FAFC';
    $CW    = 10000;

    // FIXED: These functions now return arrays correctly
    function docxBorderAll($c = '000000', $s = 6) {
        return [
            'borderSize' => $s,
            'borderColor' => $c,
            'borderTopSize' => $s,
            'borderTopColor' => $c,
            'borderBottomSize' => $s,
            'borderBottomColor' => $c,
            'borderLeftSize' => $s,
            'borderLeftColor' => $c,
            'borderRightSize' => $s,
            'borderRightColor' => $c
        ];
    }
    
    function docxBorderNone() {
        return [
            'borderSize' => 0,
            'borderColor' => 'FFFFFF',
            'borderTopSize' => 0,
            'borderTopColor' => 'FFFFFF',
            'borderBottomSize' => 0,
            'borderBottomColor' => 'FFFFFF',
            'borderLeftSize' => 0,
            'borderLeftColor' => 'FFFFFF',
            'borderRightSize' => 0,
            'borderRightColor' => 'FFFFFF'
        ];
    }
    
    function docxHdrCell($c) {
        $color = $c ?? '0F2B5C';
        $border = docxBorderAll($color);
        $border['bgColor'] = $color;
        return $border;
    }

    $sub  = docxBorderAll();
    $sub['bgColor'] = $LGRAY;
    $tot  = docxBorderAll($DARK);
    $tot['bgColor'] = $DARK;
    
    $hF   = ['bold'=>true,'color'=>'FFFFFF','size'=>9];
    $dF   = ['size'=>9];
    $tF   = ['bold'=>true,'color'=>'FFFFFF','size'=>9];
    $sF   = ['bold'=>true,'size'=>9];
    $ctr  = ['alignment'=>Jc::CENTER];
    $end  = ['alignment'=>Jc::END];

    // University header
    if (file_exists(__DIR__.'/../assets/header.jpg')) {
        $section->addImage('../assets/header.jpg',['width'=>680,'height'=>80,'alignment'=>Jc::CENTER]);
    } else {
        $section->addText('BOHOL ISLAND STATE UNIVERSITY',['bold'=>true,'size'=>16,'color'=>$DARK],$ctr);
        $section->addText('Candijay Campus  |  Balance · Integrity · Stewardship · Uprightness',['size'=>9,'italic'=>true,'color'=>'444444'],$ctr);
    }

    $section->addText($reportTitle,['bold'=>true,'size'=>18,'color'=>$DARK],['alignment'=>Jc::CENTER,'spaceBefore'=>80,'spaceAfter'=>40]);
    $section->addText($periodDisplay,['size'=>11,'italic'=>true],['alignment'=>Jc::CENTER,'spaceAfter'=>120]);

    // Meta table
    $mt = $section->addTable(['unit'=>TblWidth::TWIP,'width'=>$CW,'borderSize'=>6,'borderColor'=>'000000']);
    $mt->addRow();
    foreach(['PERIOD','GENERATED','PREPARED BY','DOCUMENT ID'] as $lbl) {
        $mt->addCell(2500, docxHdrCell($DARK))->addText($lbl,$hF,$ctr);
    }
    $mt->addRow();
    foreach([$periodDisplay, date('F d, Y g:i A'), strip_tags($preparedBy), $docId] as $v) {
        $mt->addCell(2500, docxBorderAll())->addText($v,['bold'=>true,'size'=>9],$ctr);
    }
    $section->addTextBreak(1);

    if ($reportType === 'unpaid') {
        // Stats
        $st = $section->addTable(['unit'=>TblWidth::TWIP,'width'=>$CW,'borderSize'=>8,'borderColor'=>$DARK]);
        $st->addRow();
        foreach([
            [strval($summary['total_unpaid_count']),'UNPAID RECORDS'],
            [peso($summary['total_unpaid']),'TOTAL OUTSTANDING'],
            [peso($summary['total_unpaid_count']>0?$summary['total_unpaid']/$summary['total_unpaid_count']:0),'AVERAGE BALANCE'],
            [strval(count($unpaidDeductions)),'TOTAL DEDUCTIONS'],
        ] as [$v,$l]) {
            $c=$st->addCell(2500, docxBorderAll());
            $c->addText($v,['bold'=>true,'size'=>14],$ctr);
            $c->addText($l,['size'=>8,'color'=>'666666'],$ctr);
        }
        $section->addTextBreak(1);

        // Table
        $uC = [500, 2500, 1500, 1000, 1200, 1200, 1400, 900];
        $ut = $section->addTable(['unit'=>TblWidth::TWIP,'width'=>$CW,'borderSize'=>6,'borderColor'=>'000000']);
        $ut->addRow();
        foreach(['#','Employee','Department','Order #','Total','Paid','Balance','Status'] as $i=>$h)
            $ut->addCell($uC[$i], docxHdrCell($DARK))->addText($h,$hF,$ctr);

        $n=1; $gT=$gP=$gB=0;
        foreach($unpaidDeductions as $d) {
            $gT+=floatval($d['total_amount']); $gP+=floatval($d['total_paid']??0); $gB+=floatval($d['remaining_balance']);
            $bg = ($n%2) ? 'FFFFFF' : $ALT;
            $rc = docxBorderAll();
            $rc['bgColor'] = $bg;
            $ut->addRow();
            $ut->addCell($uC[0],$rc)->addText(strval($n++),$dF,$ctr);
            $ec=$ut->addCell($uC[1],$rc);
            $ec->addText($d['customer_name']??'',['bold'=>true,'size'=>9]);
            $ec->addText($d['employee_id']??'',['size'=>8,'color'=>'666666']);
            $ut->addCell($uC[2],$rc)->addText($d['department']??'N/A',$dF,$ctr);
            $ut->addCell($uC[3],$rc)->addText(strval($d['order_id']),$dF,$ctr);
            $ut->addCell($uC[4],$rc)->addText(peso($d['total_amount']),$dF,$end);
            $ut->addCell($uC[5],$rc)->addText(peso($d['total_paid']??0),$dF,$end);
            $ut->addCell($uC[6],$rc)->addText(peso($d['remaining_balance']),['bold'=>true,'size'=>9],$end);
            $ut->addCell($uC[7],$rc)->addText(ucfirst($d['deduction_status']??''),$dF,$ctr);
        }
        $ut->addRow();
        $ut->addCell(array_sum(array_slice($uC,0,4)), array_merge(docxBorderAll($DARK), ['gridSpan'=>4, 'bgColor'=>$DARK]))->addText('TOTAL',$tF,$end);
        $ut->addCell($uC[4], array_merge(docxBorderAll($DARK), ['bgColor'=>$DARK]))->addText(peso($gT),$tF,$end);
        $ut->addCell($uC[5], array_merge(docxBorderAll($DARK), ['bgColor'=>$DARK]))->addText(peso($gP),$tF,$end);
        $ut->addCell($uC[6], array_merge(docxBorderAll($DARK), ['bgColor'=>$DARK]))->addText(peso($gB),$tF,$end);
        $ut->addCell($uC[7], array_merge(docxBorderAll($DARK), ['bgColor'=>$DARK]))->addText('',$tF);

    } else {
        // Sales report
        $section->addText('Sales Transaction Details',['bold'=>true,'size'=>13,'color'=>$DARK],['spaceBefore'=>80,'spaceAfter'=>60]);

        foreach($salesLedger as $pn=>$ledger) {
            $tbl=$section->addTable(['unit'=>TblWidth::TWIP,'width'=>$CW,'borderSize'=>6,'borderColor'=>'000000']);
            $tbl->addRow();
            $headerCell = docxHdrCell($MED);
            $headerCell['gridSpan'] = 8;
            $tbl->addCell($CW, $headerCell)->addText('PRODUCT: '.strtoupper($pn),['bold'=>true,'color'=>'FFFFFF','size'=>9]);
            $tbl->addRow();
            $sC = [1000, 2500, 1200, 1000, 1000, 1300, 1200, 1000];
            foreach(['Date','Buyer / Email','Dept','Qty (kg)','Price/kg','Total','Payment','Source'] as $i=>$h)
                $tbl->addCell($sC[$i], docxHdrCell($DARK))->addText($h,$hF,$ctr);

            $pQ=$pR=0; $n=0;
            foreach($ledger['transactions'] as $t) {
                $qty=floatval($t['quantity']??0); $sub=floatval($t['subtotal']??0);
                $pQ+=$qty; $pR+=$sub; $n++;
                $bg=($n%2)?'FFFFFF':$ALT;
                $rc = docxBorderAll();
                $rc['bgColor'] = $bg;
                $tbl->addRow();
                $tbl->addCell($sC[0],$rc)->addText(date('M d, Y',strtotime($t['order_date']??'')),$dF,$ctr);
                $bc=$tbl->addCell($sC[1],$rc);
                $bc->addText($t['customer_name']??'',['bold'=>true,'size'=>9]);
                $bc->addText($t['email']??'',['size'=>8,'color'=>'666666']);
                $tbl->addCell($sC[2],$rc)->addText($t['department']??'N/A',$dF,$ctr);
                $tbl->addCell($sC[3],$rc)->addText(kg($qty),$dF,$end);
                $tbl->addCell($sC[4],$rc)->addText(peso($t['price_per_kg']??0),$dF,$end);
                $tbl->addCell($sC[5],$rc)->addText(peso($sub),['bold'=>true,'size'=>9],$end);
                $tbl->addCell($sC[6],$rc)->addText(pmLabel($t['payment_method']??''),$dF,$ctr);
                $tbl->addCell($sC[7],$rc)->addText(($t['source']??'')==='salary_deduction'?'Salary':'Cash',$dF,$ctr);
            }
            $tbl->addRow();
            $subRow = docxBorderAll();
            $subRow['bgColor'] = $LGRAY;
            $tbl->addCell(array_sum(array_slice($sC,0,3)), array_merge($subRow, ['gridSpan'=>3]))->addText('Subtotal:',$sF,$end);
            $tbl->addCell($sC[3],$subRow)->addText(kg($pQ),$sF,$end);
            $tbl->addCell($sC[4],$subRow)->addText('',$sF);
            $tbl->addCell($sC[5],$subRow)->addText(peso($pR),$sF,$end);
            $tbl->addCell(array_sum(array_slice($sC,6)), array_merge($subRow, ['gridSpan'=>2]))->addText('');
            $section->addTextBreak(1);
        }
    }

    // Certification
    $section->addTextBreak(1);
    $section->addText('CERTIFICATION',['bold'=>true,'size'=>12],['spaceBefore'=>100,'spaceAfter'=>60]);
    $section->addText(
        $reportType==='unpaid'
            ? 'I hereby certify that this report is a true and correct record of all unpaid salary deductions, as shown in the Aquaculture Management System database. All entries have been verified.'
            : 'I hereby certify that this report is a true and correct record of all sales transactions for the period covered, as shown in the Aquaculture Management System database. All entries have been verified.',
        ['size'=>9],['spaceAfter'=>40]
    );
    $section->addText('This is a system-generated document.',['size'=>9,'italic'=>true],['spaceAfter'=>120]);

    // Signatures
    $sg=$section->addTable(['unit'=>TblWidth::TWIP,'width'=>$CW]);
    $bBot=['borderTopSize'=>0,'borderTopColor'=>'FFFFFF','borderLeftSize'=>0,'borderLeftColor'=>'FFFFFF',
           'borderRightSize'=>0,'borderRightColor'=>'FFFFFF','borderBottomSize'=>8,'borderBottomColor'=>'000000'];
    $sg->addRow(600);
    $sg->addCell(intval($CW/2),$bBot)->addText('');
    $sg->addCell(intval($CW/2),$bBot)->addText('');
    $nb=docxBorderNone();
    $sg->addRow();
    $sg->addCell(intval($CW/2),$nb)->addText(strip_tags($preparedBy),['bold'=>true,'size'=>10],$ctr);
    $sg->addCell(intval($CW/2),$nb)->addText('_________________________',['size'=>10],$ctr);
    $sg->addRow();
    $sg->addCell(intval($CW/2),$nb)->addText('Prepared by',['size'=>9,'italic'=>true],$ctr);
    $sg->addCell(intval($CW/2),$nb)->addText('Manager / Authorized Signatory',['size'=>9,'italic'=>true],$ctr);
    $sg->addRow();
    $sg->addCell(intval($CW/2),$nb)->addText('Date: '.$preparedDate,['size'=>9],$ctr);
    $sg->addCell(intval($CW/2),$nb)->addText('Date: _______________',['size'=>9],$ctr);

    $section->addTextBreak(1);
    $section->addText('BISU IGE Aquaculture Management System  —  System Generated Document',['size'=>8,'color'=>'888888'],['alignment'=>Jc::CENTER,'spaceBefore'=>80]);
    $section->addText($docId,['size'=>8,'color'=>'AAAAAA'],['alignment'=>Jc::CENTER]);

    // Stream download
    $filename = strtolower(str_replace(' ','_',$reportTitle)).'_'.date('Y-m-d_His').'.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: max-age=0');
    $objWriter = IOFactory::createWriter($phpWord,'Word2007');
    $objWriter->save('php://output');
    exit;
}

/* ══════════════════════════════════════════════════════════════════════════════
   BUILD DOWNLOAD URL (same page + &download=1)
══════════════════════════════════════════════════════════════════════════════ */
$downloadUrl = '?' . http_build_query([
    'type'              => $reportType,
    'date'              => $reportDate,
    'date_from'         => $dateFrom,
    'date_to'           => $dateTo,
    'unpaid_employee'   => $unpaidFilterEmployee,
    'unpaid_department' => $unpaidFilterDepartment,
    'unpaid_status'     => $unpaidFilterStatus,
    'download'          => '1',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $reportTitle; ?> — Preview</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Arial,sans-serif;background:#d1d5db;min-height:100vh;padding:0 0 80px;}
.toolbar{position:sticky;top:0;z-index:100;background:#0f2b5c;display:flex;align-items:center;justify-content:space-between;padding:0 24px;height:56px;box-shadow:0 2px 12px rgba(0,0,0,.35);}
.toolbar-left{display:flex;align-items:center;gap:14px;}
.toolbar-logo{width:34px;height:34px;background:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:13px;color:#0f2b5c;}
.toolbar-title{color:#fff;font-size:14px;font-weight:600;line-height:1.2;}
.toolbar-title small{display:block;font-size:11px;font-weight:400;opacity:.7;}
.toolbar-actions{display:flex;align-items:center;gap:10px;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:opacity .15s;}
.btn:hover{opacity:.88;}
.btn-word{background:#2563eb;color:#fff;}
.btn-print{background:#fff;color:#0f2b5c;}
.btn-back{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);}
.paper-wrap{padding:28px 20px;display:flex;flex-direction:column;align-items:center;}
.paper{background:#fff;width:21.59cm;min-height:27.94cm;padding:1.4cm;box-shadow:0 4px 32px rgba(0,0,0,.18);border-radius:2px;font-family:Arial,sans-serif;font-size:9pt;line-height:1.35;}
.doc-header{text-align:center;border-bottom:2.5px solid #0f2b5c;padding-bottom:8px;margin-bottom:12px;}
.doc-header img{max-width:100%;max-height:80px;}
.doc-header-text h2{font-size:15pt;color:#0f2b5c;font-weight:900;}
.doc-header-text p{font-size:8pt;color:#444;}
.doc-title{text-align:center;margin-bottom:12px;}
.doc-title h1{font-size:18pt;color:#0f2b5c;font-weight:900;margin-bottom:4px;}
.doc-title .period{font-size:10pt;color:#555;font-style:italic;}
.meta-table{width:100%;border-collapse:collapse;margin-bottom:14px;}
.meta-table th{background:#0f2b5c;color:#fff;font-size:7.5pt;padding:5px 8px;border:1px solid #000;text-align:center;}
.meta-table td{border:1px solid #000;padding:5px 8px;font-size:8.5pt;font-weight:700;text-align:center;}
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px;}
.stat-card{border:2px solid #0f2b5c;border-radius:4px;padding:10px 8px;text-align:center;}
.stat-card .val{font-size:13pt;font-weight:900;color:#0f2b5c;}
.stat-card .lbl{font-size:7pt;color:#666;text-transform:uppercase;margin-top:3px;}
.sec-header{font-size:11pt;font-weight:900;color:#0f2b5c;border-bottom:2px solid #1e3a8a;padding-bottom:4px;margin:14px 0 8px;}
.data-table{width:100%;border-collapse:collapse;font-size:8pt;margin-bottom:10px;}
.data-table th{background:#0f2b5c;color:#fff;padding:4px 5px;border:1px solid #000;text-align:center;}
.data-table td{border:1px solid #000;padding:3px 5px;vertical-align:middle;}
.data-table tbody tr:nth-child(even) td{background:#f8fafc;}
.prod-header td{background:#1e3a8a!important;color:#fff!important;font-weight:700;padding:4px 6px;}
.subtotal-row td{background:#e5e7eb!important;font-weight:700;}
.total-row td{background:#0f2b5c!important;color:#fff!important;font-weight:700;}
.text-right{text-align:right;}
.text-center{text-align:center;}
.buyer-name{font-weight:700;display:block;}
.buyer-email{font-size:7pt;color:#555;}
.status-badge{display:inline-block;padding:1px 6px;border-radius:3px;font-size:7pt;font-weight:700;border:1px solid;}
.status-pending{background:#fef3c7;border-color:#f59e0b;color:#92400e;}
.status-partial{background:#ffedd5;border-color:#f97316;color:#7c2d12;}
.status-active{background:#fee2e2;border-color:#ef4444;color:#7f1d1d;}
.status-completed{background:#d1fae5;border-color:#10b981;color:#064e3b;}
.breakdown-table{width:100%;border-collapse:collapse;font-size:8.5pt;margin-bottom:10px;}
.breakdown-table th{background:#0f2b5c;color:#fff;padding:5px 8px;border:1px solid #000;text-align:center;}
.breakdown-table td{border:1px solid #000;padding:4px 8px;}
.breakdown-table tfoot td{background:#0f2b5c;color:#fff;font-weight:700;}
.certification{border:1px solid #000;border-radius:3px;padding:10px 12px;margin-top:14px;}
.certification h3{font-size:9pt;margin-bottom:6px;}
.certification p{font-size:8pt;margin-bottom:4px;}
.sig-grid{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:20px;}
.sig-box{text-align:center;}
.sig-line{border-bottom:1.5px solid #000;height:28px;margin-bottom:5px;}
.sig-name{font-weight:700;font-size:8.5pt;}
.sig-title{font-size:7.5pt;color:#444;}
.sig-date{font-size:7.5pt;color:#444;margin-top:2px;}
.doc-footer{margin-top:18px;padding-top:6px;border-top:1px solid #000;text-align:center;font-size:7pt;color:#555;}
@media print{
    body{background:#fff;padding:0;}
    .toolbar{display:none!important;}
    .paper-wrap{padding:0;}
    .paper{box-shadow:none;width:100%;padding:1cm;}
    @page{size:Legal;margin:1.5cm;}
    thead{display:table-header-group;}
    tr{page-break-inside:avoid;}
}
@media screen and (max-width:900px){
    .paper{width:100%;}
    .stat-row{grid-template-columns:repeat(2,1fr);}
}
</style>
</head>
<body>

<div class="toolbar">
    <div class="toolbar-left">
        <div class="toolbar-logo">B</div>
        <div class="toolbar-title">
            <?php echo $reportTitle; ?>
            <small><?php echo $periodDisplay; ?></small>
        </div>
    </div>
    <div class="toolbar-actions">
        <a href="reports.php" class="btn btn-back">← Back</a>
        <button onclick="window.print()" class="btn btn-print">🖨 Print / PDF</button>
        <a href="<?php echo htmlspecialchars($downloadUrl); ?>" class="btn btn-word">📄 Download Word (.docx)</a>
    </div>
</div>

<div class="paper-wrap">
<div class="paper">

    <div class="doc-header">
        <?php if (file_exists('../assets/header.jpg')): ?>
            <img src="../assets/header.jpg" alt="BISU Header">
        <?php else: ?>
            <div class="doc-header-text">
                <h2>BOHOL ISLAND STATE UNIVERSITY</h2>
                <p>Candijay Campus | Balance · Integrity · Stewardship · Uprightness</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="doc-title">
        <h1><?php echo $reportTitle; ?></h1>
        <div class="period"><?php echo $periodDisplay; ?></div>
    </div>

    <table class="meta-table">
        <tr><th>PERIOD</th><th>GENERATED</th><th>PREPARED BY</th><th>DOCUMENT ID</th></tr>
        <tr>
            <td><?php echo $periodDisplay; ?></td>
            <td><?php echo date('F d, Y g:i A'); ?></td>
            <td><?php echo $preparedBy; ?></td>
            <td><?php echo $docId; ?></td>
        </tr>
    </table>

    <?php if ($reportType === 'unpaid'): ?>
    <div class="stat-row">
        <div class="stat-card"><div class="val"><?php echo $summary['total_unpaid_count']; ?></div><div class="lbl">Unpaid Records</div></div>
        <div class="stat-card"><div class="val"><?php echo peso($summary['total_unpaid']); ?></div><div class="lbl">Total Outstanding</div></div>
        <div class="stat-card"><div class="val"><?php echo peso($summary['total_unpaid_count'] > 0 ? $summary['total_unpaid'] / $summary['total_unpaid_count'] : 0); ?></div><div class="lbl">Average Balance</div></div>
        <div class="stat-card"><div class="val"><?php echo count($unpaidDeductions); ?></div><div class="lbl">Total Deductions</div></div>
    </div>

    <div class="sec-header">Outstanding Employee Balances</div>
    <table class="data-table">
        <colgroup><col style="width:5%"><col style="width:25%"><col style="width:15%"><col style="width:10%"><col style="width:12%"><col style="width:12%"><col style="width:12%"><col style="width:9%"></colgroup>
        <thead><tr><th>#</th><th>Employee</th><th>Department</th><th>Order #</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead>
        <tbody>
            <?php $c=1; $gT=$gP=$gB=0; foreach($unpaidDeductions as $d): $gT+=$d['total_amount']; $gP+=$d['total_paid']??0; $gB+=$d['remaining_balance']; ?>
            <tr><td class="text-center"><?php echo $c++; ?></td>
            <td><span class="buyer-name"><?php echo htmlspecialchars($d['customer_name']); ?></span><span class="buyer-email"><?php echo htmlspecialchars($d['employee_id']??''); ?></span></td>
            <td class="text-center"><?php echo htmlspecialchars($d['department']??'N/A'); ?></td>
            <td class="text-center"><?php echo $d['order_id']; ?></td>
            <td class="text-right"><?php echo peso($d['total_amount']); ?></td>
            <td class="text-right"><?php echo peso($d['total_paid']??0); ?></td>
            <td class="text-right"><strong><?php echo peso($d['remaining_balance']); ?></strong></td>
            <td class="text-center"><span class="status-badge status-<?php echo $d['deduction_status']; ?>"><?php echo ucfirst($d['deduction_status']); ?></span></td></tr>
            <?php endforeach; ?>
            <?php if(empty($unpaidDeductions)): ?><tr><td colspan="8" class="text-center">No unpaid deductions found.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr class="total-row"><td colspan="4" class="text-right">TOTAL</td>
        <td class="text-right"><?php echo peso($gT); ?></td>
        <td class="text-right"><?php echo peso($gP); ?></td>
        <td class="text-right"><?php echo peso($gB); ?></td><td></td></tr></tfoot>
    </table>

    <?php elseif (!empty($salesLedger)): ?>
    <div class="stat-row">
        <div class="stat-card"><div class="val"><?php echo peso($summary['total_sales']); ?></div><div class="lbl">Total Sales</div></div>
        <div class="stat-card"><div class="val"><?php echo peso($summary['cash_payments']); ?></div><div class="lbl">Cash</div></div>
        <div class="stat-card"><div class="val"><?php echo peso($summary['salary_payments']); ?></div><div class="lbl">Salary Deduct</div></div>
        <div class="stat-card"><div class="val"><?php echo $summary['total_transactions']; ?></div><div class="lbl">Transactions</div></div>
    </div>

    <div class="sec-header">Sales Transaction Details</div>
    <?php foreach($salesLedger as $pn=>$ledger): $pQ=0; $pR=0; ?>
    <table class="data-table">
        <colgroup><col style="width:11%"><col style="width:25%"><col style="width:12%"><col style="width:10%"><col style="width:10%"><col style="width:13%"><col style="width:11%"><col style="width:8%"></colgroup>
        <thead><tr class="prod-header"><td colspan="8">PRODUCT: <?php echo strtoupper(htmlspecialchars($pn)); ?></td></tr>
        <tr><th>Date</th><th>Buyer / Email</th><th>Dept</th><th>Qty (kg)</th><th>Price/kg</th><th>Total</th><th>Payment</th><th>Source</th></tr></thead>
        <tbody>
            <?php foreach($ledger['transactions'] as $t): $qty=floatval($t['quantity']??0); $sub=floatval($t['subtotal']??0); $pQ+=$qty; $pR+=$sub; ?>
            <tr><td class="text-center"><?php echo date('M d, Y',strtotime($t['order_date']??'')); ?></td>
            <td><span class="buyer-name"><?php echo htmlspecialchars($t['customer_name']); ?></span><span class="buyer-email"><?php echo htmlspecialchars($t['email']??''); ?></span></td>
            <td class="text-center"><?php echo htmlspecialchars($t['department']??'N/A'); ?></td>
            <td class="text-right"><?php echo kg($qty); ?></td>
            <td class="text-right"><?php echo peso($t['price_per_kg']??0); ?></td>
            <td class="text-right"><strong><?php echo peso($sub); ?></strong></td>
            <td class="text-center"><?php echo pmLabel($t['payment_method']??''); ?></td>
            <td class="text-center"><?php echo ($t['source']??'')==='salary_deduction'?'Salary':'Cash'; ?></td></tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="subtotal-row"><td colspan="3" class="text-right">Subtotal:</td>
        <td class="text-right"><?php echo kg($pQ); ?></td><td></td>
        <td class="text-right"><?php echo peso($pR); ?></td><td colspan="2"></td></tr></tfoot>
    </table>
    <?php endforeach; ?>

    <div class="sec-header">Payment Method Breakdown</div>
    <table class="breakdown-table">
        <thead><tr><th>Payment Method</th><th>Amount</th><th>% of Total</th></tr></thead>
        <tbody>
            <?php $pmData=[['Cash',$summary['cash_payments']],['GCash',$summary['gcash_payments']],['Bank Transfer',$summary['bank_payments']],['Card',$summary['card_payments']],['Pay Later',$summary['paylater_payments']],['Salary Deduction',$summary['salary_payments']]]; ?>
            <?php foreach($pmData as $pmItem): ?>
            <tr><td><?php echo $pmItem[0]; ?></td><td class="text-right"><?php echo peso($pmItem[1]); ?></td><td class="text-center"><?php echo $summary['total_sales']>0?number_format($pmItem[1]/$summary['total_sales']*100,1).'%':'0.0%'; ?></td></tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td><strong>TOTAL</strong></td><td class="text-right"><?php echo peso($summary['total_sales']); ?></td><td class="text-center">100.0%</td></tr></tfoot>
    </table>
    <p style="text-align:center;font-weight:700;margin-top:6px;">Transactions: <?php echo $summary['total_transactions']; ?> | Quantity: <?php echo kg($summary['total_quantity']); ?> | Average: <?php echo peso($summary['average_transaction']); ?></p>

    <?php else: ?>
    <div style="text-align:center;padding:40px;border:1px solid #ccc;"><p>No records found.</p></div>
    <?php endif; ?>

    <div class="certification">
        <h3>CERTIFICATION</h3>
        <p>I hereby certify that this report is a true and correct record of all <?php echo $reportType==='unpaid'?'unpaid salary deductions':'sales transactions'; ?> for the period covered, as shown in the Aquaculture Management System database. All entries have been verified.</p>
        <p style="font-style:italic;">This is a system-generated document.</p>
    </div>

    <div class="sig-grid">
        <div class="sig-box"><div class="sig-line"></div><div class="sig-name"><?php echo $preparedBy; ?></div><div class="sig-title">Prepared by</div><div class="sig-date">Date: <?php echo $preparedDate; ?></div></div>
        <div class="sig-box"><div class="sig-line"></div><div class="sig-name">_________________________</div><div class="sig-title">Manager / Authorized Signatory</div><div class="sig-date">Date: _______________</div></div>
    </div>

    <div class="doc-footer">
        <strong>BISU IGE Aquaculture Management System</strong> — System Generated Document<br>
        <?php echo $docId; ?>
    </div>

</div>
</div>

</body>
</html>