<?php

use WHMCS\Authentication\CurrentUser;
use WHMCS\ClientArea;

define('CLIENTAREA', true);

require __DIR__ . '/init.php';

// Load config and hooks
$config = require '/config.php';
require_once __DIR__ . '/includes/hooks/discord.php';

// Use environment variables for sensitive data (loaded from .env or config.php)
$client_id = getenv('DISCORD_CLIENT_ID') ?: $config['client_id'];
$secret_id = getenv('DISCORD_SECRET_ID') ?: $config['secret_id'];
$scopes = $config['scopes'];
$redirect_uri = $config['redirect_uri'];

session_start();
$ca = new ClientArea();
$ca->setPageTitle('Discord Connection');
$ca->initPage();
$ca->assign('verified', false);
$currentUser = new CurrentUser();
if (!$currentUser->isAuthenticatedUser()) {
    $ca->assign('message', "You must be logged in to link your Discord account.");
    $ca->setTemplate('discord');
    $ca->output();
    exit;
}

$client = $currentUser->client();
if (!$client) {
    $ca->assign('message', "Unable to retrieve client information.");
    $ca->setTemplate('discord');
    $ca->output();
    exit;
}

if (isset($_GET['code'])) {
    // Verify CSRF token
    if (!isset($_GET['state']) || $_GET['state'] !== $_SESSION['csrf_token']) {
        $ca->assign('message', "Invalid CSRF token. Please try again.");
    } else {
        try {
            // Exchange authorization code for access token
            $tokenData = exchangeAuthorizationCodeForAccessToken($_GET['code'], $client_id, $secret_id, $redirect_uri);
            $userInfo = getUserInfo($tokenData->access_token);

            if (!isset($userInfo->id)) {
                throw new Exception("Failed to retrieve user information from Discord.");
            }

            // Update Discord ID and assign role
            updateClientDiscordId($userInfo->id, $client->id);

            try {
                assignRoleToUser($userInfo->id, $client->id);
                logActivity("Discord successfully linked for Client ID: " . $client->id);
                $ca->assign('message', "Discord Linked and Role Assigned Successfully");
            } catch (Exception $e) {
                logActivity("Failed to assign Discord role for Client ID: " . $client->id . " - " . $e->getMessage());
                $ca->assign('message', "Discord Linked Successfully, but failed to assign role: " . htmlspecialchars($e->getMessage()));
            }
        } catch (Exception $e) {
            logActivity("Discord linking error for Client ID: " . $client->id . " - " . $e->getMessage());
            $ca->assign('message', "An error occurred: " . htmlspecialchars($e->getMessage()));
        }
    }
} else {
    // Initiate OAuth flow
    redirectToDiscordForAuthorization($client_id, $redirect_uri, $scopes);
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
        CURLOPT_SSL_VERIFYPEER => true
    ));

    $tokenResponse = curl_exec($ch);

    if ($tokenResponse === false) {
        throw new Exception('Failed to retrieve access token: ' . curl_error($ch));
    }

    $tokenData = json_decode($tokenResponse);

    if (!isset($tokenData->access_token)) {
        throw new Exception('Failed to retrieve access token: ' . json_encode($tokenData));
    }

    curl_close($ch);
    return $tokenData;
}

function getUserInfo($accessToken)
{
    $ch = curl_init('https://discord.com/api/users/@me');
    curl_setopt_array($ch, array(
        CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $accessToken),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true
    ));

    $userInfoResponse = curl_exec($ch);

    if ($userInfoResponse === false) {
        throw new Exception('Failed to retrieve user information: ' . curl_error($ch));
    }

    $userInfo = json_decode($userInfoResponse);

    if (!isset($userInfo->id)) {
        throw new Exception('Failed to retrieve user information: ' . json_encode($userInfo));
    }

    curl_close($ch);
    return $userInfo;
}

function redirectToDiscordForAuthorization($clientId, $redirectUri, $scopes)
{
    // Generate CSRF token
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    $authorizationUrl = 'https://discord.com/oauth2/authorize?response_type=code&client_id=' . $clientId .
        '&redirect_uri=' . urlencode($redirectUri) .
        '&scope=' . urlencode($scopes) .
        '&state=' . $csrfToken;

    header('Location: ' . $authorizationUrl);
    exit();
}
