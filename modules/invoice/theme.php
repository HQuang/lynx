<?php

/**
 * @Project NUKEVIET 4.x
 * @Author TDFOSS.,LTD (contact@tdfoss.vn)
 * @Copyright (C) 2018 TDFOSS.,LTD. All rights reserved
 * @Createdate Mon, 26 Feb 2018 03:48:37 GMT
 */
if (!defined('NV_IS_MOD_INVOICE')) die('Stop!!!');

/**
 * nv_theme_invoice_main()
 *
 * @param mixed $array_data
 * @return
 */
function nv_theme_invoice_main($array_data)
{
    global $global_config, $module_name, $module_file, $lang_module, $module_config, $module_info, $op;

    $xtpl = new XTemplate($op . '.tpl', NV_ROOTDIR . '/themes/' . $module_info['template'] . '/modules/' . $module_file);
    $xtpl->assign('LANG', $lang_module);

    $xtpl->parse('main');
    return $xtpl->text('main');
}

/**
 * nv_theme_invoice_detail()
 *
 * @param mixed $array_data
 * @return
 */
function nv_theme_invoice_detail($row, $array_invoice_products, $array_control, $downpdf, $sendmail)
{
    global $global_config, $module_name, $module_file, $lang_module, $module_config, $module_info, $op, $array_services, $array_products, $array_projects, $site_mods, $db;

    $lang_module['send_mail'] = $row['sended'] > 0 ? (sprintf($lang_module['resend_mail'], $row['sended'] + 1)) : $lang_module['send_mail'];
    $templateCSS = file_exists(NV_ROOTDIR . '/themes/' . $global_config['module_theme'] . '/css/pdf.css') ? $global_config['module_theme'] : 'default';

    $xtpl = new XTemplate($op . '.tpl', NV_ROOTDIR . '/themes/' . $module_info['template'] . '/modules/' . $module_file);
    $xtpl->assign('LANG', $lang_module);
    $xtpl->assign('ROW', $row);
    $xtpl->assign('CONTROL', $array_control);
    $xtpl->assign('TEMPLATE_CSS', $templateCSS);
    $xtpl->assign('TRANSACTION', nv_transaction_list($row['id']));

    if (!empty($array_invoice_products)) {
        $i = 1;
        foreach ($array_invoice_products as $orders) {
            $orders['number'] = $i++;
            $orders['vat_price'] = ($orders['price'] * $orders['vat']) / 100;
            $orders['vat_price'] = number_format($orders['vat_price']);
            $orders['price'] = number_format($orders['price']);
            $orders['unit_price'] = number_format($orders['unit_price']);
            $orders['total'] = number_format($orders['total']);

            if ($orders['module'] == 'services') {
                $unit_services = $db->query('SELECT t2.title FROM ' . NV_PREFIXLANG . '_services t1 INNER JOIN  ' . NV_PREFIXLANG . '_services_price_unit t2 ON t1.price_unit = t2.id WHERE t1.id=' . $orders['itemid'])->fetch();

                $orders['money_unit'] = $unit_services['title'];
                $orders['itemid'] = $array_services[$orders['itemid']]['title'];
            } elseif ($orders['module'] == 'products') {
                $unit_products = $db->query('SELECT t2.title FROM ' . NV_PREFIXLANG . '_products t1 INNER JOIN  ' . NV_PREFIXLANG . '_products_price_unit t2 ON t1.price_unit = t2.id WHERE t1.id=' . $orders['itemid'])->fetch();

                $orders['money_unit'] = $unit_products['title'];
                $orders['itemid'] = $array_products[$orders['itemid']]['title'];
            } elseif ($orders['module'] == 'projects') {
                $orders['itemid'] = $array_projects[$orders['itemid']]['title'];
            }

            $xtpl->assign('ORDERS', $orders);

            if ($orders['vat'] > 0) {
                $xtpl->parse('main.invoice_list.loop.vat');
            } else {
                $xtpl->parse('main.invoice_list.loop.vat_empty');
            }

            $xtpl->parse('main.invoice_list.loop');
        }
        $xtpl->parse('main.invoice_list');
    }

    if (!empty($row['terms'])) {
        $xtpl->parse('main.terms');
    }

    if (!empty($row['description'])) {
        $xtpl->parse('main.description');
    }

    if (!empty($row['discount_percent']) && !empty($row['discount_value'])) {
        $xtpl->parse('main.discount');
    }

    if (defined('NV_INVOICE_ADMIN')) {
        if ($row['status'] == 0 || $row['status'] == 3 || $row['status'] == 4) {
            $xtpl->parse('main.admin.button_funs.invoice_payment_confirm');
        }

        if (isset($site_mods['support'])) {
            $xtpl->parse('main.admin.button_funs.support');
        }

        if (isset($site_mods['support'])) {
            $xtpl->parse('main.admin.button_funs.support');
        }

        if (empty($downpdf) && empty($sendmail)) {
            if (class_exists('Mpdf')) {
                $xtpl->parse('main.admin.button_funs.export_pdf');
            }
            $xtpl->parse('main.admin.button_funs');
            $xtpl->parse('main.admin.non_title_pdf');
        } else {
            $xtpl->parse('main.admin.dompdf_link');
        }
        $xtpl->parse('main.admin');
        $xtpl->parse('main.transaction_add');
    }

    $xtpl->parse('main');
    return $xtpl->text('main');
}

function nv_theme_invoice_transaction($array_data)
{
    global $module_info, $module_file, $lang_module, $array_transaction_status;

    $array_data['transaction_time'] = nv_date('d/m/Y', $array_data['transaction_time']);

    $xtpl = new XTemplate('transaction.tpl', NV_ROOTDIR . '/themes/' . $module_info['template'] . '/modules/' . $module_file);
    $xtpl->assign('LANG', $lang_module);
    $xtpl->assign('DATA', $array_data);

    $xtpl->parse('main');
    return $xtpl->text('main');
}
