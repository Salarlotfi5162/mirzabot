<?php
require_once 'config.php';
require_once 'x-ui_single.php';
require_once 'request.php';
ini_set('error_log', 'error_log');

function get_clinetsalireza($username,$namepanel){
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel,"select");
    login($marzban_list_get['code_panel']);
    $curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => $marzban_list_get['url_panel'].'/xui/API/inbounds',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_SSL_VERIFYHOST =>  false,
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_TIMEOUT_MS => 4000,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'GET',
  CURLOPT_HTTPHEADER => array(
    'Accept: application/json'
  ),
  CURLOPT_COOKIEFILE => 'cookie.txt',
));
$response = json_decode(curl_exec($curl),true)['obj'];
curl_close($curl);
unlink('cookie.txt');
if(!isset($response)) return [];

$aggSettings = null;
$aggStats = null;

foreach ($response as $client){
    $clientdata= json_decode($client['settings'],true)['clients'];
    foreach($clientdata as $clinets){
        if($clinets['email'] == $username || preg_match("/^" . preg_quote($username, '/') . "_inb\d+$/", $clinets['email'])){
            if ($aggSettings === null) {
                $aggSettings = $clinets;
                $aggSettings['email'] = $username;
                $aggSettings['ids'] = [$clinets['id']];
                $aggSettings['inbound_ids'] = [$client['id']];
            } else {
                $aggSettings['ids'][] = $clinets['id'];
                $aggSettings['inbound_ids'][] = $client['id'];
            }
        }
    }
    $clientStats= $client['clientStats'];
    foreach($clientStats as $clinetsup){
        if($clinetsup['email'] == $username || preg_match("/^" . preg_quote($username, '/') . "_inb\d+$/", $clinetsup['email'])){
            if ($aggStats === null) {
                $aggStats = $clinetsup;
                $aggStats['email'] = $username;
            } else {
                $aggStats['up'] += $clinetsup['up'];
                $aggStats['down'] += $clinetsup['down'];
                $aggStats['total'] = $clinetsup['total'];
                $aggStats['enable'] = $aggStats['enable'] || $clinetsup['enable'];
            }
        }
    }
}

if ($aggSettings !== null && $aggStats !== null) {
    return [$aggSettings, $aggStats];
} elseif ($aggSettings !== null) {
    return [$aggSettings, $aggSettings]; // Fallback
}
return [];
}

function addClientalireza_singel($namepanel, $usernameac, $Expire,$Total, $Uuid,$Flow,$subid,$inboundid){
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel,"select");
    login($marzban_list_get['code_panel']);
    
    $inbounds_arr = [];
    $decoded = json_decode($inboundid, true);
    if(is_array($decoded)) {
        $inbounds_arr = $decoded;
    } elseif(strpos($inboundid, ',') !== false) {
        $inbounds_arr = explode(',', $inboundid);
    } else {
        $inbounds_arr = [$inboundid];
    }
    
    $last_response = null;
    
    foreach($inbounds_arr as $inb) {
        $inb = trim($inb);
        if(empty($inb)) continue;
        
        $current_email = $usernameac . "_inb" . $inb;
        $current_uuid = generateUUID();
        
        $config = array(
            "id" => intval($inb),
            'settings' => json_encode(array(
                'clients' => array(
                    array(
                    "id" => $current_uuid,
                    "flow" => $Flow,
                    "email" => $current_email,
                    "totalGB" => $Total,
                    "expiryTime" => $Expire,
                    "enable" => true,
                    "tgId" => "",
                    "subId" => $subid,
                    "reset" => 0
                )),
                 'decryption' => 'none',
                'fallbacks' => array(),
            ))
        );

        $configpanel = json_encode($config,true);
        $url = $marzban_list_get['url_panel'].'/xui/API/inbounds/addClient';
        $headers = array(
                'Accept: application/json',
                'Content-Type: application/json',
        );
        $req = new CurlRequest($url);
        $req->setHeaders($headers);
        $req->setCookie('cookie.txt');
        $last_response = $req->post($configpanel);
    }
    
    unlink('cookie.txt');
    return $last_response;
}

function updateClientalireza($namepanel, $username,array $config){
    $UsernameData = get_clinetsalireza($username,$namepanel)[0];
    if (empty($UsernameData)) return;
    
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel,"select");
    login($marzban_list_get['code_panel']);
    
    $last_response = null;
    $ids = isset($UsernameData['ids']) ? $UsernameData['ids'] : [$UsernameData['id']];
    $inbound_ids = isset($UsernameData['inbound_ids']) ? $UsernameData['inbound_ids'] : [$marzban_list_get['inboundid']];
    
    foreach ($ids as $index => $uuid) {
        $inbId = isset($inbound_ids[$index]) ? $inbound_ids[$index] : $inbound_ids[0];
        
        $currentConfig = $config;
        $decodedConfig = json_decode($currentConfig['settings'], true);
        if (isset($decodedConfig['clients'][0])) {
            $decodedConfig['clients'][0]['id'] = $uuid;
            $decodedConfig['clients'][0]['email'] = $username . "_inb" . $inbId;
        }
        $currentConfig['id'] = $inbId;
        $currentConfig['settings'] = json_encode($decodedConfig);
        
        $configpanel = json_encode($currentConfig,true);
        $url = $marzban_list_get['url_panel'].'/xui/API/inbounds/updateClient/'.$uuid;
        $headers = array(
                'Accept: application/json',
                'Content-Type: application/json',
        );
        $req = new CurlRequest($url);
        $req->setHeaders($headers);
        $req->setCookie('cookie.txt');
        $last_response = $req->post($configpanel);
    }
    
    unlink('cookie.txt');
    return $last_response;
}

function ResetUserDataUsagealirezasin($usernamepanel, $namepanel){
    $data_user = get_clinetsalireza($usernamepanel,$namepanel)[0];
    if (empty($data_user)) return;
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel,"select");
    login($marzban_list_get['code_panel']);
    
    $last_response = null;
    $inbound_ids = isset($data_user['inbound_ids']) ? $data_user['inbound_ids'] : [$marzban_list_get['inboundid']];
    
    foreach ($inbound_ids as $inbId) {
        $suffixed_email = $usernamepanel . "_inb" . $inbId;
        $url = $marzban_list_get['url_panel']."/xui/API/inbounds/{$inbId}/resetClientTraffic/".$suffixed_email;
        $headers = array(
                'Accept: application/json',
                'Content-Type: application/json',
        );
        $req = new CurlRequest($url);
        $req->setHeaders($headers);
        $req->setCookie('cookie.txt');
        $last_response = $req->post(array());
    }
    
    unlink('cookie.txt');
    return $last_response;
}

function removeClientalireza_single($location,$username){
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $location,"select");
    $data_user = get_clinetsalireza($username,$location)[0];
    if (empty($data_user)) return;
    login($marzban_list_get['code_panel']);
    
    $last_response = null;
    $inbound_ids = isset($data_user['inbound_ids']) ? $data_user['inbound_ids'] : [$marzban_list_get['inboundid']];
    $ids = isset($data_user['ids']) ? $data_user['ids'] : [$data_user['id']];
    
    foreach ($ids as $index => $uuid) {
        $inbId = isset($inbound_ids[$index]) ? $inbound_ids[$index] : $inbound_ids[0];
        $url = $marzban_list_get['url_panel']."/xui/API/inbounds/{$inbId}/delClient/".$uuid;
        $headers = array(
                'Accept: application/json',
                'Content-Type: application/json',
        );
        $req = new CurlRequest($url);
        $req->setHeaders($headers);
        $req->setCookie('cookie.txt');
        $last_response = $req->post(array());
    }
    
    unlink('cookie.txt');
    return $last_response;
}

function get_onlineclialireza($name_panel,$username){
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $name_panel,"select");
    login($marzban_list_get['code_panel']);
    $curl = curl_init();
curl_setopt_array($curl, array(
  CURLOPT_URL => $marzban_list_get['url_panel'].'/xui/API/inbounds/onlines',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_SSL_VERIFYHOST =>  false,
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'POST',
  CURLOPT_HTTPHEADER => array(
    'Accept: application/json'
  ),
  CURLOPT_COOKIEFILE => 'cookie.txt',
));
$response = json_decode(curl_exec($curl),true)['obj'];
curl_close($curl);
unlink('cookie.txt');

if($response == null) return "offline";

$found = false;
foreach ($response as $online_email) {
    if ($online_email == $username || preg_match("/^" . preg_quote($username, '/') . "_inb\d+$/", $online_email)) {
        $found = true;
        break;
    }
}
return $found ? "online" : "offline";
}