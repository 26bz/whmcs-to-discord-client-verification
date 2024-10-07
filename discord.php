<?php

use WHMCS\Authentication\CurrentUser;
use WHMCS\ClientArea;

define('CLIENTAREA', true);

require __DIR__ . '/init.php';

// Load sensitive information from config file
$config = require __DIR__ . '/config.php';

$client_id = $config['client_id'];
$secret_id = $config['secret_id'];
$scopes = $config['scopes'];
$redirect_uri = $config['redirect_uri'];

$ca = new ClientArea();
$ca->setPageTitle('Discord Connection');
$ca->initPage();

$currentUser = new CurrentUser();
$client = $currentUser->client();

if ($client) {
    if (isset($_GET['code'])) {
        try {
            $tokenData = exchangeAuthorizationCodeForAccessToken($_GET['code'], $client_id, $secret_id, $redirect_uri);
            $userInfo = getUserInfo($tokenData->access_token);

            if (isset($userInfo->id)) {
                updateClientDiscordId($userInfo->id, $client->id);
                // Assign Discord role if needed
                $ca->assign('message', "Discord Linked Successfully");
            } else {
                $ca->assign('message', "Failed to retrieve user information from Discord.");
            }
        } catch (Exception $e) {
            $ca->assign('message', $e->getMessage());
        }
    } else {
        redirectToDiscordForAuthorization($client_id, $redirect_uri, $scopes);
    }
} else {
    $ca->assign('message', "You must be logged in to link your Discord account.");
}

$ca->setTemplate('discord');
$ca->output();

function exchangeAuthorizationCodeForAccessToken($code, $client_id, $secret_id, $redirect_uri)
{
    $ch = curl_init('https://discord.com/api/oauth2/token');
    curl_setopt_array($ch, array(
        CURLOPT_HTTPHEADER     => array('Authorization: Basic ' . base64_encode("$client_id:$secret_id")),
        CURLOPT_POST           => 1,
        CURLOPT_POSTFIELDS     => http_build_query(array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirect_uri
        )),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ));

    $tokenResponse = curl_exec($ch);

    if ($tokenResponse === false) {
        throw new Exception('Failed to retrieve access token: ' . curl_error($ch));
    }

    $tokenData = json_decode($tokenResponse);

    if (!isset($tokenData->access_token)) {
        throw new Exception('Failed to retrieve access token: ' . json_encode($tokenData));
    }

    return $tokenData;
}

function getUserInfo($accessToken)
{
    $ch = curl_init('https://discord.com/api/users/@me');
    curl_setopt_array($ch, array(
        CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $accessToken),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ));

    $userInfoResponse = curl_exec($ch);

    if ($userInfoResponse === false) {
        throw new Exception('Failed to retrieve user information: ' . curl_error($ch));
    }

    $userInfo = json_decode($userInfoResponse);

    if (!isset($userInfo->id)) {
        throw new Exception('Failed to retrieve user information: ' . json_encode($userInfo));
    }

    return $userInfo;
}

function updateClientDiscordId($discordId, $clientId)
{
    $command = 'UpdateClient';
    $customFields = array('discord' => $discordId);
    $postData = array(
        'clientid'      => $clientId,
        'customfields'  => base64_encode(serialize($customFields))
    );

    $results = localAPI($command, $postData);

    if ($results['result'] !== 'success') {
        throw new Exception('Failed to update client Discord ID: ' . $results['message']);
    }
}

function redirectToDiscordForAuthorization($clientId, $redirectUri, $scopes)
{
    $authorizationUrl = 'https://discord.com/oauth2/authorize?response_type=code&client_id=' . $clientId . '&redirect_uri=' . urlencode($redirectUri) . '&scope=' . urlencode($scopes);
    header('Location: ' . $authorizationUrl);
    exit();
}
?>
