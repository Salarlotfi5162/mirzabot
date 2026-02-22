<?php
require_once 'config.php';
require_once 'request.php';
ini_set('error_log', 'error_log');
function panel_login_cookie($code_panel)
{
    $panel = select("marzban_panel", "*", "code_panel", $code_panel, "select");
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $panel['url_panel'] . '/login',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT_MS => 4000,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => "username={$panel['username_panel']}&password=" . urlencode($panel['password_panel']),
        CURLOPT_COOKIEJAR => 'cookie.txt',
    ));
    $response = curl_exec($curl);
    if (curl_error($curl)) {
        return json_encode(array(
            'success' => false,
            'msg' => curl_error($curl)
        ));
    }
    curl_close($curl);
    return $response;
}
function login($code_panel, $verify = true)
{
    $panel = select("marzban_panel", "*", "code_panel", $code_panel, "select");
    if ($panel['datelogin'] != null && $verify) {
        $date = json_decode($panel['datelogin'], true);
        if (isset($date['time'])) {
            $timecurrent = time();
            $start_date = time() - strtotime($date['time']);
            if ($start_date <= 3000) {
                file_put_contents('cookie.txt', $date['access_token']);
                return;
            }
        }
    }
    $response = panel_login_cookie($panel['code_panel']);
    $time = date('Y/m/d H:i:s');
    $data = json_encode(array(
        'time' => $time,
        'access_token' => file_get_contents('cookie.txt')
    ));
    update("marzban_panel", "datelogin", $data, 'name_panel', $panel['name_panel']);
    if (!is_string($response))
        return array('success' => false);
    return json_decode($response, true);
}

function get_clinets($username, $namepanel)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    login($marzban_list_get['code_panel']);
    
    $url = $marzban_list_get['url_panel'] . "/panel/api/inbounds/list";
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $req->setCookie('cookie.txt');
    $response = $req->get();
    
    if (isset($response['body'])) {
        $decodedBody = json_decode($response['body'], true);
        if (isset($decodedBody['success']) && $decodedBody['success'] === true && isset($decodedBody['obj'])) {
            $inbounds = $decodedBody['obj'];
            $aggSettings = null;
            $aggStats = null;
            
            foreach ($inbounds as $inbound) {
                $settings = json_decode($inbound['settings'], true);
                $clients = isset($settings['clients']) ? $settings['clients'] : [];
                foreach ($clients as $client) {
                    if ($client['email'] == $username || preg_match("/^" . preg_quote($username, '/') . "_inb\d+$/", $client['email'])) {
                        if ($aggSettings === null) {
                            $aggSettings = $client;
                            $aggSettings['email'] = $username;
                            $aggSettings['ids'] = [$client['id']];
                            $aggSettings['inbound_ids'] = [$inbound['id']];
                        } else {
                            $aggSettings['ids'][] = $client['id'];
                            $aggSettings['inbound_ids'][] = $inbound['id'];
                        }
                    }
                }
                
                $clientStats = isset($inbound['clientStats']) ? $inbound['clientStats'] : [];
                foreach ($clientStats as $cStat) {
                    if ($cStat['email'] == $username || preg_match("/^" . preg_quote($username, '/') . "_inb\d+$/", $cStat['email'])) {
                        if ($aggStats === null) {
                            $aggStats = $cStat;
                            $aggStats['email'] = $username;
                        } else {
                            $aggStats['up'] += $cStat['up'];
                            $aggStats['down'] += $cStat['down'];
                            if (isset($cStat['total'])) $aggStats['total'] = $cStat['total'];
                            $aggStats['enable'] = $aggStats['enable'] || $cStat['enable'];
                        }
                    }
                }
            }
            
            if ($aggSettings !== null) {
                if ($aggStats === null) {
                    $aggStats = [
                        'up' => 0, 'down' => 0, 'total' => 0, 'enable' => $aggSettings['enable']
                    ];
                }
                $finalObj = array_merge($aggSettings, $aggStats);
                $response['body'] = json_encode([
                    'success' => true,
                    'msg' => '',
                    'obj' => $finalObj
                ]);
            } else {
                $response['body'] = json_encode([
                    'success' => false,
                    'msg' => 'Client not found',
                    'obj' => null
                ]);
            }
        }
    }
    
    if (is_file('cookie.txt')) {
        @unlink('cookie.txt');
    }

    return $response;
}
function addClient($namepanel, $usernameac, $Expire, $Total, $Uuid, $Flow, $subid, $inboundid, $name_product, $note = "")
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    login($marzban_list_get['code_panel']);
    if ($name_product == "usertest") {
        if ($marzban_list_get['on_hold_test'] == "1") {
            if ($Expire == 0) {
                $timeservice = 0;
            } else {
                $timelast = $Expire - time();
                $timeservice = -intval(($timelast / 86400) * 86400000);
            }
        } else {
            $timeservice = $Expire * 1000;
        }
    } else {
        if ($marzban_list_get['conecton'] == "onconecton") {
            if ($Expire == 0) {
                $timeservice = 0;
            } else {
                $timelast = $Expire - time();
                $timeservice = -intval(($timelast / 86400) * 86400000);
            }
        } else {
            $timeservice = $Expire * 1000;
        }
    }
    
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
    if (!isset($usernameac))
        return array(
            'status' => 500,
            'msg' => 'username is null'
        );

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
                        "expiryTime" => $timeservice,
                        "enable" => true,
                        "tgId" => "",
                        "subId" => $subid,
                        "reset" => 0,
                        "comment" => $note
                    )
                ),
                'decryption' => 'none',
                'fallbacks' => array(),
            ))
        );

        $configpanel = json_encode($config, true);
        $url = $marzban_list_get['url_panel'] . '/panel/api/inbounds/addClient';
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
function updateClient($namepanel, $uuid, array $config)
{
    $decodedConfig = json_decode($config['settings'], true);
    $username = $decodedConfig['clients'][0]['email'];
    
    $user_data_response = get_clinets($username, $namepanel);
    $user_data = json_decode($user_data_response['body'], true)['obj'];
    if (empty($user_data)) return;

    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    login($marzban_list_get['code_panel']);
    
    $last_response = null;
    $ids = isset($user_data['ids']) ? $user_data['ids'] : [$user_data['id']];
    $inbound_ids = isset($user_data['inbound_ids']) ? $user_data['inbound_ids'] : [$marzban_list_get['inboundid']];
    
    foreach ($ids as $index => $c_uuid) {
        $inbId = isset($inbound_ids[$index]) ? $inbound_ids[$index] : $inbound_ids[0];
        
        $currentConfig = $config;
        $dec = json_decode($currentConfig['settings'], true);
        if (isset($dec['clients'][0])) {
            $dec['clients'][0]['id'] = $c_uuid;
            $dec['clients'][0]['email'] = $username . "_inb" . $inbId;
        }
        $currentConfig['id'] = $inbId;
        $currentConfig['settings'] = json_encode($dec);
        
        $configpanel = json_encode($currentConfig, true);
        $url = $marzban_list_get['url_panel'] . '/panel/api/inbounds/updateClient/' . $c_uuid;
        
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
function ResetUserDataUsagex_uisin($usernamepanel, $namepanel)
{
    $data_user_resp = get_clinets($usernamepanel, $namepanel);
    $data_user = json_decode($data_user_resp['body'], true)['obj'];
    if(empty($data_user)) return;
    
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    login($marzban_list_get['code_panel']);
    
    $last_response = null;
    $inbound_ids = isset($data_user['inbound_ids']) ? $data_user['inbound_ids'] : [$marzban_list_get['inboundid']];
    
    foreach ($inbound_ids as $inbId) {
        $suffixed_email = $usernamepanel . "_inb" . $inbId;
        $url = $marzban_list_get['url_panel'] . "/panel/api/inbounds/{$inbId}/resetClientTraffic/" . $suffixed_email;
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
function removeClient($location, $username)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $location, "select");
    $data_user_resp = get_clinets($username, $location);
    $data_user = json_decode($data_user_resp['body'], true)['obj'];
    if(empty($data_user)) return;
    
    login($marzban_list_get['code_panel']);
    
    $last_response = null;
    $inbound_ids = isset($data_user['inbound_ids']) ? $data_user['inbound_ids'] : [$marzban_list_get['inboundid']];
    
    foreach ($inbound_ids as $inbId) {
        $suffixed_email = $username . "_inb" . $inbId;
        $url = $marzban_list_get['url_panel'] . "/panel/api/inbounds/{$inbId}/delClientByEmail/" . $suffixed_email;
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