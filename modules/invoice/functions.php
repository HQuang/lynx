<?php

/**
 * @Project NUKEVIET 4.x
 * @Author TDFOSS.,LTD (contact@tdfoss.vn)
 * @Copyright (C) 2018 TDFOSS.,LTD. All rights reserved
 * @Createdate Mon, 26 Feb 2018 03:48:37 GMT
 */
if (!defined('NV_SYSTEM')) die('Stop!!!');

define('NV_IS_MOD_INVOICE', true);

require_once NV_ROOTDIR . '/modules/invoice/global.functions.php';
require_once NV_ROOTDIR . '/modules/customer/site.functions.php';
require_once NV_ROOTDIR . '/modules/invoice/site.functions.php';

function nv_number_format($number)
{
    if (!empty($number) && is_numeric($number)) $number = number_format($number);
    return $number;
}

function nv_delete_invoice($id)
{
    global $db, $module_name, $module_data, $user_info, $lang_module, $workforce_list;

    $rows = $db->query('SELECT code, title FROM ' . NV_PREFIXLANG . '_' . $module_data . ' WHERE id=' . $id)->fetch();
    if ($rows) {
        $count = $db->exec('DELETE FROM ' . NV_PREFIXLANG . '_' . $module_data . ' WHERE id = ' . $id);
        if ($count) {
            $db->query('DELETE FROM ' . NV_PREFIXLANG . '_' . $module_data . '_detail WHERE idinvoice = ' . $id);
            $db->query('DELETE FROM ' . NV_PREFIXLANG . '_' . $module_data . '_transaction WHERE invoiceid = ' . $id);

            $content = sprintf($lang_module['logs_invoice_delete_note'], $workforce_list[$user_info['userid']]['fullname'], '[#' . $rows['code'] . '] ' . $rows['title']);
            nv_insert_logs(NV_LANG_DATA, $module_name, $lang_module['logs_invoice_delete'], $content, $user_info['userid']);
        }
    }
}

function nv_caculate_total($price, $quantity, $vat = 0)
{
    $total = $price * $quantity;
    $total = $total + (($total * $vat) / 100);
    return $total;
}

function nv_sendmail_confirm($id)
{
    global $db, $module_name, $module_data, $row, $lang_module, $array_invoice_status, $user_info, $workforce_list;

    $row = $db->query('SELECT * FROM ' . NV_PREFIXLANG . '_' . $module_data . ' WHERE id=' . $id)->fetch();
    if ($row) {
        $customer_info = nv_crm_customer_info($row['customerid']);
        if ($customer_info) {
            require_once NV_ROOTDIR . '/modules/email/site.functions.php';
            $sendto_id = array(
                $row['customerid']
            );

            $subject = 'Re: ' . sprintf($lang_module['sendmail_title'], $row['code'], $row['title']);
            $message = $db->query('SELECT econtent FROM ' . NV_PREFIXLANG . '_' . $module_data . '_econtent WHERE action="newconfirm"')->fetchColumn();
            $row['status'] = $array_invoice_status[$row['status']];
            $array_replace = array(
                'FULLNAME' => $customer_info['fullname'],
                'TITLE' => $row['title'],
                'STATUS' => $row['status'],
                'URL' => NV_MY_DOMAIN . NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&amp;' . NV_NAME_VARIABLE . '=' . $module_name . '&amp;' . NV_OP_VARIABLE . '=detail&amp;id=' . $id,
                'CODE' => $row['code'],
                'WORKFORCE' => $workforce_list[$row['workforceid']]['fullname'],
                'CREATETIME' => date('d/m/Y', $row['createtime']),
                'DUETIME' => (empty($row['duetime'])) ? ($lang_module['non_identify']) : nv_date('d/m/Y', $row['duetime']),
                'TERMS' => $row['terms'],
                'DESCRIPTION' => $row['description'],
                'TABLE' => nv_invoice_table($id)
            );

            $message = nv_unhtmlspecialchars($message);
            foreach ($array_replace as $index => $value) {
                $message = str_replace('[' . $index . ']', $value, $message);
            }

            $result = nv_email_send($subject, $message, $user_info['userid'], $sendto_id);
            if ($result['status']) {
                if (empty($row['sended'])) {
                    $db->query('UPDATE ' . NV_PREFIXLANG . '_' . $module_data . ' SET sended=1 WHERE id=' . $id);
                }
            }
        }
    }
}

function nv_invoice_new_notification($id, $title, $workforceid)
{
    global $db, $user_info, $lang_module, $module_name, $module_config, $array_config;

    // thông báo người phụ trách
    if ($workforceid != $user_info['userid']) {
        require_once NV_ROOTDIR . '/modules/notification/site.functions.php';
        $array_userid = array(
            $workforceid
        );
        $content = sprintf($lang_module['new_invoice'], $title);
        $url = NV_MY_DOMAIN . NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&' . NV_NAME_VARIABLE . '=' . $module_name . '&' . NV_OP_VARIABLE . '=detail&id=' . $id;
        nv_send_notification($array_userid, $content, 'new_invoice', $module_name, $url);
    }
}

function nv_invoice_premission($module, $type = 'where')
{
    global $db, $array_config, $user_info;

    $array_userid = array(); // mảng chứa userid mà người này được quản lý
    $groups_admin = explode(',', $array_config['groups_admin']);

    if (!empty(array_intersect($groups_admin, $user_info['in_groups']))) {
        return '';
    }

    // nhóm quản lý thấy tất cả
    $group_manage = !empty($array_config['groups_manage']) ? explode(',', $array_config['groups_manage']) : array();
    $group_manage = array_map('intval', $group_manage);

    if (!empty(array_intersect($group_manage, $user_info['in_groups']))) {
        // kiểm tra tư cách trong nhóm (trưởng nhóm / thành viên nhóm)
        $result = $db->query('SELECT * FROM ' . NV_USERS_GLOBALTABLE . '_groups_users WHERE is_leader=1 AND approved=1 AND userid=' . $user_info['userid']);
        while ($row = $result->fetch()) {
            // lấy danh sách userid thuộc nhóm do người này quản lý
            $_result = $db->query('SELECT userid FROM ' . NV_USERS_GLOBALTABLE . '_groups_users WHERE approved=1 AND group_id=' . $row['group_id']);
            while (list ($userid) = $_result->fetch(3)) {
                $array_userid[] = $userid;
            }
        }
        $array_userid = array_unique($array_userid);

        if ($type == 'where') {
            if (!empty($array_userid)) {
                // nếu là trưởng nhóm, thấy nhân viên do mình quản lý
                $array_userid = implode(',', $array_userid);
                return ' AND (workforceid IN (' . $array_userid . ') OR useradd IN (' . $array_userid . '))';
            } else {
                // thành viên nhóm nhìn thấy ticket cho mình thực hiện, do mình tạo ra
                return ' AND (workforceid=' . $user_info['userid'] . ' OR useradd=' . $user_info['userid'] . ')';
            }
        } elseif ($type == 'array_userid') {
            return $array_userid;
        }
    } else {
        return '';
    }
}

function nv_transaction_list($invoiceid)
{
    global $db, $module_info, $module_data, $module_file, $lang_module, $array_transaction_status;

    $invoice_info = $db->query('SELECT * FROM ' . NV_PREFIXLANG . '_' . $module_data . ' WHERE id=' . $invoiceid)->fetch();
    if ($invoice_info) {
        $total = 0;
        $array_data = array();
        $result = $db->query('SELECT * FROM ' . NV_PREFIXLANG . '_' . $module_data . '_transaction WHERE invoiceid=' . $invoiceid . ' ORDER BY id DESC');
        while ($_row = $result->fetch()) {
            $total += $_row['payment_amount'];
            $_row['payment_amount'] = nv_number_format($_row['payment_amount']);
            $_row['transaction_time'] = nv_date('H:i d/m/Y', $_row['transaction_time']);
            $_row['transaction_status'] = $array_transaction_status[$_row['transaction_status']];
            $array_data[$_row['id']] = $_row;
        }

        $xtpl = new XTemplate('transaction.tpl', NV_ROOTDIR . '/themes/' . $module_info['template'] . '/modules/' . $module_file);
        $xtpl->assign('LANG', $lang_module);
        $xtpl->assign('TOTAL', nv_number_format($total));
        $rest = $total >= $invoice_info['grand_total'] ? 0 : $invoice_info['grand_total'] - $total;
        $xtpl->assign('REST', nv_number_format($rest));

        if (!empty($array_data)) {
            foreach ($array_data as $data) {
                $xtpl->assign('DATA', $data);
                $xtpl->parse('transaction_list.data.loop');
            }
            $xtpl->parse('transaction_list.data');
        } else {
            $xtpl->parse('transaction_list.empty');
        }
    }

    $xtpl->parse('transaction_list');
    return $xtpl->text('transaction_list');
}

function nv_transaction_update($invoiceid)
{
    global $db, $module_data;

    $invoice_info = $db->query('SELECT * FROM ' . NV_PREFIXLANG . '_' . $module_data . ' WHERE id=' . $invoiceid)->fetch();
    if ($invoice_info) {
        $total = 0;
        $result = $db->query('SELECT payment_amount FROM ' . NV_PREFIXLANG . '_' . $module_data . '_transaction WHERE invoiceid=' . $invoiceid);
        while (list ($amount) = $result->fetch(3)) {
            $total += $amount;
        }

        $status = empty($total) ? 0 : ($total >= $invoice_info['grand_total'] ? 1 : 3);

        if ($status != $invoice_info['status']) {
            $db->query('UPDATE ' . NV_PREFIXLANG . '_' . $module_data . ' SET status=' . $status . ' WHERE id=' . $invoiceid);
        }
    }
}

function nv_support_confirm_payment($id)
{
    global $db, $module_name, $module_data, $lang_module, $workforce_list, $user_info;

    $rows = $db->query('SELECT code, title FROM ' . NV_PREFIXLANG . '_' . $module_data . ' WHERE id=' . $id)->fetch();
    if ($rows) {
        $count = $db->exec('UPDATE ' . NV_PREFIXLANG . '_' . $module_data . ' SET status=1 WHERE id=' . $id);
        if ($count) {
            // cập nhật lịch sử giao dịch
            $grand_total = $db->query('SELECT grand_total FROM ' . NV_PREFIXLANG . '_' . $module_data . ' WHERE id=' . $id)->fetchColumn();
            $transaction_total = $db->query('SELECT SUM(payment_amount) FROM ' . NV_PREFIXLANG . '_' . $module_data . '_transaction WHERE invoiceid=' . $id)->fetchColumn();
            $payment_amount = $grand_total - $transaction_total;
            $transaction_status = 4;
            $payment = '';

            $stmt = $db->prepare('INSERT INTO ' . NV_PREFIXLANG . '_' . $module_data . '_transaction(invoiceid, transaction_time, transaction_status, payment, payment_amount) VALUES(:invoiceid, ' . NV_CURRENTTIME . ', :transaction_status, :payment, :payment_amount)');
            $stmt->bindParam(':invoiceid', $id, PDO::PARAM_INT);
            $stmt->bindParam(':transaction_status', $transaction_status, PDO::PARAM_INT);
            $stmt->bindParam(':payment', $payment, PDO::PARAM_STR);
            $stmt->bindParam(':payment_amount', $payment_amount, PDO::PARAM_STR);
            if ($stmt->execute()) {
                nv_sendmail_confirm($id);
                $content = sprintf($lang_module['logs_invoice_confirm_note'], '[#' . $rows['code'] . '] ' . $rows['title']);
                nv_insert_logs(NV_LANG_DATA, $module_name, $lang_module['logs_invoice_confirm'], $content, $user_info['userid']);
            }
        }
    }
}