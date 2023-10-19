<?php
/*
 - Author : Hamidreza shooshtari 
 - Module Designed For The : jibimo.com
 - Mail : Hamidrezashoshtari84@gamil.com
*/
use WHMCS\Database\Capsule;
if(isset($_REQUEST['invoiceId']) && is_numeric($_REQUEST['invoiceId'])){
    require_once __DIR__ . '/../../init.php';
    require_once __DIR__ . '/../../includes/gatewayfunctions.php';
    require_once __DIR__ . '/../../includes/invoicefunctions.php';
    $gatewayParams = getGatewayVariables('jibimo');
    if(isset($_REQUEST['trx'], $_REQUEST['hash'], $_REQUEST['callback'])){
        $invoice = Capsule::table('tblinvoices')->where('id', $_REQUEST['invoiceId'])->where('status', 'Unpaid')->first();
        if(!$invoice){
            die("Invoice not found");
        }
           $client = Capsule::table('tblclients')->where('id', $_SESSION['uid'])->first();
         $client_number =   "+98".str_replace(array('-', '(', ')', '.', ',', '+', ' '),null,substr($client->phonenumber,3,15));
         $response = jibimo_request('https://api.jibimo.com/v2/ipg/verify', [
            'trx' => $_GET['trx']
        ]);
                if(isset($response['status'])){
                    if($response['status'] == 1){
                        if ($response['mobile_number'] == $client_number) {
                            $amount = $response['amount'] / ($gatewayParams['currencyType'] == 'IRT' ? 10 : 1);
                            $hash = sha1($invoice->id . $response['amount'] . ($gatewayParams['testMode'] == 'on' ? 'test' : $gatewayParams['apiToken']));
                            if ($_REQUEST['hash'] == $hash) {
                                logTransaction($gatewayParams['name'], $_REQUEST, 'Success');
                                addInvoicePayment(
                                $invoice->id,
                                $response['tracking_id'],
                                $amount,
                                $response['date'],
                                'jibimo'
                            );
                            } else {
                                logTransaction($gatewayParams['name'], array(
                                'Code'        => 'Invalid Amount',
                                'Message'     => 'رقم تراكنش با رقم پرداخت شده مطابقت ندارد',
                                'Transaction' => $response['tracking_id'],
                                'Invoice'     => $invoice->id,
                                'Amount'      => $amount,
                            ), 'Failure');
                            }
                        }
                    } else {
                        logTransaction($gatewayParams['name'], array(
                            'Code'        => isset($response['state_code']) ? $response['state_code'] : 'Verify',
                            'Message'     => isset($response['errorMessage']) ? $response['errorMessage'] : 'در ارتباط با وب سرویس api.jibimo.com خطایی رخ داده است',
                            'Transaction' => $response['transId'],
                            'Invoice'     => $invoice->id,
                        ), 'Failure');
                    }
                    header('Location: ' . $gatewayParams['systemurl'] . '/viewinvoice.php?id=' . $invoice->id);
                }

    } else if(isset($_SESSION['uid'])){
        $invoice = Capsule::table('tblinvoices')->where('id', $_REQUEST['invoiceId'])->where('status', 'Unpaid')->where('userid', $_SESSION['uid'])->first();
        if(!$invoice){
            die("Invoice not found");
        }
        $client = Capsule::table('tblclients')->where('id', $_SESSION['uid'])->first();
        $client_number =   "+98".str_replace(array('-', '(', ')', '.', ',', '+', ' '),null,substr($client->phonenumber,3,15));
        $hash = sha1($invoice->id . ($invoice->total * ($gatewayParams['currencyType'] == 'IRT' ? 10 : 1)) . ($gatewayParams['testMode'] == 'on' ? 'test' : $gatewayParams['apiToken']));
        if($gatewayParams['testMode'] == 'on'){ $mobile_number = true; }else{ $mobile_number = false; }
        $response = jibimo_request('https://api.jibimo.com/v2/ipg/request', [
            'amount'       => intval($invoice->total * ($gatewayParams['currencyType'] == 'IRT' ? 10 : 1)),
            'return_url'     => $gatewayParams['systemurl'] . '/modules/gateways/jibimo.php?invoiceId=' . $invoice->id . '&callback=1&hash=' . $hash,
            'mobile_number'       => $client_number,
            'check_national_code' => $mobile_number,
        ]);
        print_r($response);
       echo "<br>".$client_number."<br>";
        if($response !== false){
            if(isset($response['trx'])){
                header("Location: {$response['link']}");
            } else {
                $text = 'اتصال به درگاه پرداخت ناموفق بود.';
                $text .= '<br />';
                $text .= 'متن خطا: %s';
                echo sprintf($text, $response['errors']['token']);
            }
        } else {
            echo 'اتصال به درگاه امکان پذیر نیست.';
        }
    }
    return;
}

if (!defined('WHMCS')) {
	die('This file cannot be accessed directly');
}
function jibimo_request($url, $params) 
{ 
 global $gatewayParams;
   $header = [
                'Content-Type: application/json',
                'Accept: application/json',
                'API-KEY: ' . $gatewayParams['apiToken'],
            ];


        $handler = curl_init();
        curl_setopt($handler, CURLOPT_HTTPHEADER, $header);
        curl_setopt($handler, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($handler, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($handler, CURLOPT_URL, $url);
        curl_setopt($handler, CURLOPT_RETURNTRANSFER, True);
        curl_setopt($handler, CURLOPT_POST, True);
        curl_setopt($handler, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($handler, CURLOPT_VERBOSE, true);

       $result = curl_exec($handler);

        $info = curl_getinfo($handler);

        if (!$result) {
            return false;
        }

    return json_decode($result, true);
}

function jibimo_MetaData()
{
    return array(
        'DisplayName' => 'ماژول پرداخت آنلاین api.jibimo.com برای WHMCS',
        'APIVersion' => '1.0',
    );
}

function jibimo_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'جیبیمو',
        ),
        'currencyType' => array(
            'FriendlyName' => 'نوع ارز',
            'Type' => 'dropdown',
            'Options' => array(
                'IRR' => 'ریال',
                'IRT' => 'تومان',
            ),
        ),
        'apiToken' => array(
            'FriendlyName' => 'کد API',
            'Type' => 'text',
            'Size' => '255',
            'Default' => '',
            'Description' => 'کد api دریافتی از سایت api.jibimo.com',
        ),
        'testMode' => array(
            'FriendlyName' => 'برسی شماره موبایل',
            'Type' => 'yesno',
            'Description' => 'برای فعال کردن همخوانی شماره موبایل و حساب تیک بزنید',
        ),
    );
}

function jibimo_link($params)
{
    $htmlOutput = '<form method="GET" action="modules/gateways/jibimo.php">';
    $htmlOutput .= '<input type="hidden" name="invoiceId" value="' . $params['invoiceid'] .'">';
    $htmlOutput .= '<input type="submit" value="' . $params['langpaynow'] . '" />';
    $htmlOutput .= '</form>';
    return $htmlOutput;
}
