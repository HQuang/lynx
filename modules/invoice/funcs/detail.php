<?php

/**
 * @Project NUKEVIET 4.x
 * @Author TDFOSS.,LTD (contact@tdfoss.vn)
 * @Copyright (C) 2018 TDFOSS.,LTD. All rights reserved
 * @Createdate Mon, 26 Feb 2018 03:48:37 GMT
 */
if (!defined('NV_IS_MOD_INVOICE')) die('Stop!!!');

if ($nv_Request->isset_request('get_score_info', 'post')) {
    $amount = $nv_Request->get_title('amount', 'post', 0);
    $score = nv_invoice_get_amount($amount);
    $customerid = $nv_Request->get_int('customerid', 'post', 0);
    $customer_score = nv_invoice_get_customer_score($customerid);
    if ($customer_score['score'] < $score) {
        nv_jsonOutput(array(
            'error' => 1,
            'msg' => $lang_module['score_error'],
            'input' => 'amount'
        ));
    }
    nv_jsonOutput(array(
        'error' => 0,
        'msg' => sprintf($lang_module['score_note'], $score)
    ));
}

if ($nv_Request->isset_request('transaction_list', 'post')) {
    $invoiceid = $nv_Request->get_int('invoiceid', 'post', 0);
    $contents = nv_transaction_list($invoiceid);
    nv_htmlOutput($contents);
}

if ($nv_Request->isset_request('transaction_update', 'post')) {
    $row = array();
    $row['transaction_type'] = $nv_Request->get_int('transaction_type', 'post', 1);
    $row['invoiceid'] = $nv_Request->get_int('invoiceid', 'post', 0);
    $row['transaction_status'] = 4;
    $row['customerid'] = $nv_Request->get_int('customerid', 'post', 0);
    $row['payment'] = '';
    $row['payment_amount'] = $nv_Request->get_title('amount', 'post', '');
    $row['payment_data'] = '';
    $row['note'] = $nv_Request->get_textarea('note', '', NV_ALLOWED_HTML_TAGS);

    if (preg_match('/^([0-9]{1,2})\/([0-9]{1,2})\/([0-9]{4})$/', $nv_Request->get_string('transaction_time', 'post'), $m)) {
        $row['transaction_time'] = mktime(23, 59, 59, $m[2], $m[1], $m[3]);
    } else {
        $row['transaction_time'] = 0;
    }

    if (empty($row['invoiceid'])) {
        nv_jsonOutput(array(
            'error' => 1,
            'msg' => $lang_module['error_required_invoiceid']
        ));
    }

    if (empty($row['payment_amount'])) {
        nv_jsonOutput(array(
            'error' => 1,
            'msg' => $lang_module['error_required_amount'],
            'input' => 'amount'
        ));
    }

    $rows = $db->query('SELECT title, code FROM ' . NV_PREFIXLANG . '_' . $module_data . ' WHERE id=' . $row['invoiceid'])->fetch();
    if (!$rows) {
        nv_jsonOutput(array(
            'error' => 1,
            'msg' => $lang_module['error_required_invoiceid']
        ));
    }

    if (defined('NV_INVOICE_SCORE') && $row['transaction_type'] == 2) {
        // đổi số tiền cần thanh toán thành điểm
        $score = nv_invoice_get_amount($row['payment_amount']);

        // điểm hiện có của khách hàng
        $customer_score = nv_invoice_get_customer_score($row['customerid']);

        // so sánh xem khách hàng có đủ điểm để thanh toán hay không
        if ($customer_score['score'] < $score) {
            nv_jsonOutput(array(
                'error' => 1,
                'msg' => $lang_module['score_error'],
                'input' => 'amount'
            ));
        }
        $row['payment'] = 'score';
        $row['payment_data'] = array(
            'score' => $score
        );
        $row['payment_data'] = serialize($row['payment_data']);
    }

    $stmt = $db->prepare('INSERT INTO ' . NV_PREFIXLANG . '_' . $module_data . '_transaction(invoiceid, transaction_time, transaction_status, payment, payment_amount, payment_data, note) VALUES(:invoiceid, ' . $row['transaction_time'] . ', :transaction_status, :payment, :payment_amount, :payment_data, :note)');
    $stmt->bindParam(':invoiceid', $row['invoiceid'], PDO::PARAM_INT);
    $stmt->bindParam(':transaction_status', $row['transaction_status'], PDO::PARAM_INT);
    $stmt->bindParam(':payment', $row['payment'], PDO::PARAM_STR);
    $stmt->bindParam(':payment_amount', $row['payment_amount'], PDO::PARAM_STR);
    $stmt->bindParam(':payment_data', $row['payment_data'], PDO::PARAM_STR);
    $stmt->bindParam(':note', $row['note'], PDO::PARAM_STR, strlen($row['note']));
    if ($stmt->execute()) {

        // tính toán, cập nhật lại trạng thái hóa đơn
        nv_transaction_update($row['invoiceid']);

        // cập nhật điểm tích lũy
        if (defined('NV_INVOICE_SCORE')) {
            // quy tiền thành điểm
            $score = nv_invoice_get_score($row['payment_amount']);

            // cập nhật điểm tổng cho khách hàng khi mua hàng
            try {
                $db->query('INSERT INTO ' . NV_PREFIXLANG . '_' . $module_data . '_score (customerid, score) VALUES(' . $row['customerid'] . ', ' . $score . ')');
            } catch (Exception $e) {
                $db->query('UPDATE ' . NV_PREFIXLANG . '_' . $module_data . '_score SET score=score+' . ($score) . ' WHERE customerid=' . $row['customerid']);
            }

            // cập nhật điểm tổng cho mỗi đơn hàng
            $db->query('UPDATE ' . NV_PREFIXLANG . '_' . $module_data . ' SET score=score+' . ($score) . ' WHERE customerid=' . $row['customerid'] . ' AND id=' . $row['invoiceid']);

            // Nếu lấy điểm thanh toán thì trừ điểm
            if ($row['transaction_type'] == 2) {
                $score = nv_invoice_get_amount($row['payment_amount']);
                $db->query('UPDATE ' . NV_PREFIXLANG . '_' . $module_data . '_score SET score=score-' . $score . ' WHERE customerid=' . $row['customerid']);
            }
        }

        $content = sprintf($lang_module['logs_transaction_add'], $workforce_list[$user_info['userid']]['fullname'], $rows['code'], $rows['title'], nv_number_format($row['payment_amount']));
        nv_insert_logs(NV_LANG_DATA, $module_name, $lang_module['transaction_add'], $content, $user_info['userid']);

        nv_jsonOutput(array(
            'error' => 0,
            'invoiceid' => $row['invoiceid']
        ));
    }
    nv_jsonOutput(array(
        'error' => 1,
        'msg' => $lang_module['error_unknow']
    ));
}

if ($nv_Request->isset_request('transaction_content', 'post')) {
    $id = $nv_Request->get_int('id', 'post', 0);

    if ($id > 0) {
        $rows = $db->query('SELECT * FROM ' . NV_PREFIXLANG . '_' . $module_data . '_transaction WHERE id=' . $id)->fetch();
    } else {
        $rows = array();
        $rows['id'] = 0;
        $rows['invoiceid'] = $nv_Request->get_int('invoiceid', 'post', 0);
        $rows['transaction_time'] = NV_CURRENTTIME;
        $rows['transaction_status'] = 0;
        $rows['payment'] = '';
        $rows['payment_amount'] = 0;
        $rows['payment_data'] = '';
    }

    $invoice = array();
    if ($rows['invoiceid']) {
        $invoice = $db->query('SELECT * FROM ' . NV_PREFIXLANG . '_' . $module_data . ' WHERE id=' . $rows['invoiceid'])->fetch();
        $invoice['customer'] = nv_crm_customer_info($invoice['customerid']);
    }

    if (defined('NV_INVOICE_SCORE')) {
        $invoice['customer'] += nv_invoice_get_customer_score($invoice['customerid']);
    }

    $contents = nv_theme_invoice_transaction($invoice, $rows);
    nv_htmlOutput($contents);
}


if ($nv_Request->isset_request('downpdf', 'get')) {
    $id = $nv_Request->get_int('id', 'get', 0);
    $code = $db->query('SELECT code FROM ' . NV_PREFIXLANG . '_' . $module_data . ' WHERE id=' . $id)->fetchColumn();
    $filename = '#' . $code . '.pdf';
    $contents = nv_invoice_template($id);

    if (class_exists('Mpdf\Mpdf')) {
        $mpdf = new \Mpdf\Mpdf();
        $mpdf->WriteHTML($contents);
        $mpdf->Output($filename, 'D');
    }
}

if ($nv_Request->isset_request('sendmail', 'post')) {

    $id = $nv_Request->get_int('id', 'post', 0);
    $code = $db->query('SELECT code FROM ' . NV_PREFIXLANG . '_' . $module_data . ' WHERE id=' . $id)->fetchColumn();

    $location_file = array();
    if (class_exists('Mpdf\Mpdf')) {
        $contents = nv_invoice_template($id);
        $location = NV_ROOTDIR . '/' . NV_TEMP_DIR . '/#' . $code . '.pdf';
        $mpdf = new \Mpdf\Mpdf();
        $mpdf->WriteHTML($contents);
        $mpdf->Output($location, \Mpdf\Output\Destination::FILE);
        $location_file[] = str_replace(NV_ROOTDIR . '/', '', $location);
    }

    $result = nv_sendmail_econtent($id, $user_info['userid'], $location_file);
    if ($result['status']) {
        if (file_exists($location)) {
            unlink($location);
        }
        die('OK_' . $lang_module['invoice_sendmail_success']);
    }
    die('NO_' . $lang_module['invoice_sendmail_error']);
}

if ($nv_Request->isset_request('confirm_payment_id', 'get')) {
    $id = $nv_Request->get_int('id', 'get');
    if ($id > 0) {
        nv_invoice_confirm_payment($id);
        die('OK');
    }
    die('NO_' . $lang_module['error_unknow']);
}

if ($nv_Request->isset_request('copy_id', 'get')) {
    $id = $nv_Request->get_int('id', 'get');
    $new_id = nv_copy_invoice($id, 0, $user_info['userid']);
    if (!empty($new_id)) {
        die('OK_' . $new_id);
    }
    die('NO_' . $lang_module['error_invoice_copy']);
}

$id = $nv_Request->get_int('id', 'id', 0);
$downpdf = $nv_Request->get_int('downpdf', 'downpdf', 0);
$sendmail = $nv_Request->get_int('sendmail', 'sendmail', 0);

if ($id > 0) {
    $row = $db->query('SELECT * FROM ' . NV_PREFIXLANG . '_' . $module_data . ' WHERE id=' . $id . nv_invoice_premission($module_name))->fetch();
    if ($row == false) {
        Header('Location: ' . NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&' . NV_NAME_VARIABLE . '=' . $module_name);
        die();
    }

    $row['createtime'] = date('d/m/Y', $row['createtime']);
    $row['duetime'] = (empty($row['duetime'])) ? ($lang_module['non_identify']) : nv_date('d/m/Y', $row['duetime']);
    $row['customer'] = nv_crm_customer_info($row['customerid']);
    $row['workforceid'] = !empty($row['workforceid']) ? $workforce_list[$row['workforceid']]['fullname'] : $lang_module['workforceid_empty'];
    $row['status_str'] = $array_invoice_status[$row['status']];
    $row['grand_total_string'] = nv_convert_number_to_words($row['grand_total']);
    $row['grand_total'] = number_format($row['grand_total']);
    $row['discount_value'] = number_format($row['discount_value']);
} else {
    Header('Location: ' . NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&' . NV_NAME_VARIABLE . '=' . $module_name);
    die();
}

$row['vat_price'] = $row['item_total'] = $row['vat_total'] = 0;
$array_invoice_products = array();

$order_id = $db->query('SELECT * FROM ' . NV_PREFIXLANG . '_' . $module_data . '_detail WHERE idinvoice=' . $id);

while ($order = $order_id->fetch()) {
    $row['vat_price'] = ($order['price'] * $order['vat']) / 100;
    $row['item_total'] += $order['price'];
    $row['vat_total'] += $row['vat_price'];
    $array_invoice_products[] = $order;
}

$row['item_total'] = number_format($row['item_total']);
$row['vat_total'] = number_format($row['vat_total']);

$row['terms'] = nv_nl2br($row['terms']);
$row['description'] = nv_nl2br($row['description']);

$array_control = array(
    'url_confirm_payment' => NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&amp;' . NV_NAME_VARIABLE . '=' . $module_name . '&' . NV_OP_VARIABLE . '=' . $op . '&amp;confirm_payment_id=' . $id,
    'url_sendmail' => NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&amp;' . NV_NAME_VARIABLE . '=' . $module_name . '&' . NV_OP_VARIABLE . '=' . $op . '&amp;sendmail_id=' . $id,
    'url_add' => NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&amp;' . NV_NAME_VARIABLE . '=' . $module_name . '&amp;' . NV_OP_VARIABLE . '=content',
    'url_edit' => NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&amp;' . NV_NAME_VARIABLE . '=' . $module_name . '&amp;' . NV_OP_VARIABLE . '=content&amp;id=' . $id . '&amp;redirect=' . nv_redirect_encrypt($client_info['selfurl']),
    'url_delete' => NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&amp;' . NV_NAME_VARIABLE . '=' . $module_name . '&amp;delete_id=' . $id . '&amp;delete_checkss=' . md5($id . NV_CACHE_PREFIX . $client_info['session_id']),
    'url_export_pdf' => NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&' . NV_NAME_VARIABLE . '=' . $module_name . '&' . NV_OP_VARIABLE . '=' . $op . '&id=' . $row['id'] . '&downpdf=1',
    'url_invoice' => NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&' . NV_NAME_VARIABLE . '=' . $module_name . '&' . NV_OP_VARIABLE . '=invoice&id=' . $row['id'] . '&checksum=' . md5($id . $global_config['sitekey'] . $client_info['session_id'])
);

if (isset($site_mods['comment']) and isset($array_config['activecomm'])) {
    define('NV_COMM_ID', $id);
    define('NV_COMM_AREA', $module_info['funcs'][$op]['func_id']);
    $allowed = $array_config['allowed_comm'];
    if ($allowed == '-1') {
        $allowed = $rows['allowed_comm'];
    }

    define('NV_PER_PAGE_COMMENT', 5);

    require_once NV_ROOTDIR . '/modules/comment/comment.php';
    $area = (defined('NV_COMM_AREA')) ? NV_COMM_AREA : 0;
    $checkss = md5($module_name . '-' . $area . '-' . NV_COMM_ID . '-' . $allowed . '-' . NV_CACHE_PREFIX);

    $url_info = parse_url($client_info['selfurl']);
    $content_comment = nv_comment_module($module_name, $checkss, $area, NV_COMM_ID, $allowed, 1);
} else {
    $content_comment = '';
}

if (isset($site_mods['support'])) {
    $array_control['url_support'] = NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&amp;' . NV_NAME_VARIABLE . '=support&amp;' . NV_OP_VARIABLE . '=content&amp;customerid=' . $row['customerid'] . '&amp;title=' . $row['title'];
}

$contents = nv_theme_invoice_detail($row, $array_invoice_products, $array_control, $downpdf, $sendmail, $content_comment);

$page_title = '#' . $row['code'] . ' - ' . $row['title'];
$array_mod_title[] = array(
    'title' => $page_title,
    'link' => NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&amp;' . NV_NAME_VARIABLE . '=' . $module_name . '&amp;' . NV_OP_VARIABLE . '=' . $op . '&id=' . $row['id']
);

include NV_ROOTDIR . '/includes/header.php';
echo nv_site_theme($contents);
include NV_ROOTDIR . '/includes/footer.php';
