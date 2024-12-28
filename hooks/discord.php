<?php
// includes/hooks/discord.php

use WHMCS\Database\Capsule;

// Global configuration
global $discord_config;
$discord_config = require '/config.php';
$discord_config['bot_token'] = getenv('DISCORD_BOT_TOKEN') ?: $discord_config['bot_token'];

add_hook('DailyCronJob', 1, function() {
    global $discord_config;
    
    try {
        // Get all clients with Discord IDs from custom fields
        $clients = Capsule::table('tblcustomfields')
            ->where('fieldname', 'LIKE', '%discord%')
            ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->join('tblclients', 'tblcustomfieldsvalues.relid', '=', 'tblclients.id')
            ->select('tblclients.id', 'tblclients.status', 'tblcustomfieldsvalues.value as discord_id')
            ->get();

        foreach ($clients as $client) {
            if (empty($client->discord_id)) continue;

            $activeProducts = Capsule::table('tblhosting')
                ->where('userid', $client->id)
                ->where('domainstatus', 'Active')
                ->count();

            if ($client->status == 'Inactive') {
                // Remove both roles if client is inactive
                removeRole($client->discord_id, $discord_config['guild_id'], $discord_config['active_role_id'], $discord_config['bot_token']);
                removeRole($client->discord_id, $discord_config['guild_id'], $discord_config['default_role_id'], $discord_config['bot_token']);
                logActivity("Removed Discord roles for inactive client ID: " . $client->id);
            } else {
                // Assign appropriate role based on product status
                try {
                    assignRoleToUser($client->discord_id, $client->id);
                    logActivity("Updated Discord role for client ID: " . $client->id);
                } catch (Exception $e) {
                    logActivity("Failed to update Discord role for client ID: " . $client->id . " - " . $e->getMessage());
                }
            }
        }
    } catch (Exception $e) {
        logActivity("Discord role sync failed: " . $e->getMessage());
    }
});

add_hook('ServiceStatusChange', 1, function($vars) {
    global $discord_config;
    
    // Only proceed if the service status is changing to or from 'Active'
    if ($vars['oldstatus'] !== 'Active' && $vars['status'] !== 'Active') {
        return;
    }

    try {
        // Get client's Discord ID
        $discordId = Capsule::table('tblcustomfields')
            ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfields.fieldname', 'LIKE', '%discord%')
            ->where('tblcustomfieldsvalues.relid', $vars['userid'])
            ->value('tblcustomfieldsvalues.value');

        if ($discordId) {
            assignRoleToUser($discordId, $vars['userid']);
        }
    } catch (Exception $e) {
        logActivity("Failed to update Discord role on service status change - User ID: {$vars['userid']} - " . $e->getMessage());
    }
});

add_hook('ClientStatusChange', 1, function($vars) {
    global $discord_config;
    
    try {
        // Get client's Discord ID
        $discordId = Capsule::table('tblcustomfields')
            ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfields.fieldname', 'LIKE', '%discord%')
            ->where('tblcustomfieldsvalues.relid', $vars['userid'])
            ->value('tblcustomfieldsvalues.value');

        if ($discordId) {
            if ($vars['status'] == 'Inactive') {
                // Remove roles for inactive clients
                removeRole($discordId, $discord_config['guild_id'], $discord_config['active_role_id'], $discord_config['bot_token']);
                removeRole($discordId, $discord_config['guild_id'], $discord_config['default_role_id'], $discord_config['bot_token']);
            } else {
                assignRoleToUser($discordId, $vars['userid']);
            }
        }
    } catch (Exception $e) {
        logActivity("Failed to update Discord role on client status change - User ID: {$vars['userid']} - " . $e->getMessage());
    }
});

function removeRole($userId, $guildId, $roleId, $botToken) {
    $url = "https://discord.com/api/guilds/$guildId/members/$userId/roles/$roleId";
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => "DELETE",
        CURLOPT_HTTPHEADER => [
            'Authorization: Bot ' . $botToken,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    curl_exec($ch);
    curl_close($ch);
}

function assignRoleToUser($userId, $clientId) {
    global $discord_config;
    
    // Check if client has active products
    $activeProducts = Capsule::table('tblhosting')
        ->where('userid', $clientId)
        ->where('domainstatus', 'Active')
        ->count();

    // Determine which role to assign
    $roleId = $activeProducts > 0 ? $discord_config['active_role_id'] : $discord_config['default_role_id'];
    
    $url = "https://discord.com/api/guilds/{$discord_config['guild_id']}/members/$userId/roles/$roleId";
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_HTTPHEADER => [
            'Authorization: Bot ' . $discord_config['bot_token'],
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new Exception('Failed to assign role: ' . curl_error($ch));
    }

    // Remove the other role if it exists
    $roleToRemove = $roleId === $discord_config['active_role_id'] ? $discord_config['default_role_id'] : $discord_config['active_role_id'];
    removeRole($userId, $discord_config['guild_id'], $roleToRemove, $discord_config['bot_token']);

    curl_close($ch);
}