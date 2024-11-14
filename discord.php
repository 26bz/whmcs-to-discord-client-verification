<?php

use WHMCS\Authentication\CurrentUser;
use WHMCS\ClientArea;

define('CLIENTAREA', true);

require __DIR__ . '/init.php';

// Load sensitive information from config file
$config = require '/config.php';
// Use environment variables for sensitive data
$client_id = getenv('DISCORD_CLIENT_ID') ?: $config['client_id'];
$secret_id = getenv('DISCORD_SECRET_ID') ?: $config['secret_id'];
$scopes = $config['scopes'];
$redirect_uri = $config['redirect_uri'];
$guild_id = $config['guild_id'];
$role_id = $config['role_id'];
$bot_token = getenv('DISCORD_BOT_TOKEN') ?: $config['bot_token'];

// Start session
session_start();

$ca = new ClientArea();
$ca->setPageTitle('Discord Connection');
$ca->initPage();

$currentUser = new CurrentUser();
$client = $currentUser->client();

if ($client) {
    if (isset($_GET['code'])) {
        // Check CSRF token matches
        if (!isset($_GET['state']) || $_GET['state'] !== $_SESSION['csrf_token']) {
            $ca->assign('message', "Invalid CSRF token. Please try again.");
        } else {
            try {
                // Exchange authorization code for access token
                $tokenData = exchangeAuthorizationCodeForAccessToken($_GET['code'], $client_id, $secret_id, $redirect_uri);
                $userInfo = getUserInfo($tokenData->access_token);

                if (isset($userInfo->id)) {
                    updateClientDiscordId($userInfo->id, $client->id);
                    // Attempt to assign the Discord role
                    try {
                        assignRoleToUser($userInfo->id, $guild_id, $role_id, $bot_token);
                        $ca->assign('message', "Discord Linked and Role Assigned Successfully");
                    } catch (Exception $e) {
                        $ca->assign('message', "Discord Linked Successfully, but failed to assign role: " . htmlspecialchars($e->getMessage()));
                    }
                } else {
                    $ca->assign('message', "Failed to retrieve user information from Discord.");
                }
            } catch (Exception $e) {
                $ca->assign('message', "An error occurred: " . htmlspecialchars($e->getMessage()));
            }
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
        CURLOPT_SSL_VERIFYPEER => true // Verify SSL certificates
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
        CURLOPT_SSL_VERIFYPEER => true // Verify SSL certificates
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
    // Generate CSRF token
    $csrfToken = bin2hex(random_bytes(32)); // 32 bytes gives you a 64 character token
    $_SESSION['csrf_token'] = $csrfToken;

    // Append the CSRF token to the state parameter
    $authorizationUrl = 'https://discord.com/oauth2/authorize?response_type=code&client_id=' . $clientId . '&redirect_uri=' . urlencode($redirectUri) . '&scope=' . urlencode($scopes) . '&state=' . $csrfToken;

    header('Location: ' . $authorizationUrl);
    exit();
}

function assignRoleToUser($userId, $guildId, $roleId, $botToken)
{
    $url = "https://discord.com/api/guilds/$guildId/members/$userId/roles/$roleId";
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => "PUT", 
        CURLOPT_HTTPHEADER    => [
            'Authorization: Bot ' . $botToken,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true // Verify SSL certificates
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new Exception('Failed to assign role: ' . curl_error($ch));
    }

    $result = json_decode($response);

    if (isset($result->code) && $result->code !== 0) {
        throw new Exception('Failed to assign role: ' . json_encode($result));
    }

    curl_close($ch);
}
