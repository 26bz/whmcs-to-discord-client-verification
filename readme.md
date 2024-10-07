# WHMCS Discord Client Verification

This integration allows paying customers to obtain a Discord role by visiting `/discord.php` in your WHMCS installation.

## Installation

1. **Place Files**:
   - Add `discord.php` to the root directory of your WHMCS installation.
   - Place `config.php` outside the web root or use environment variables to store sensitive credentials securely. Sample `config.php` content:
     ```php
     <?php
     
     return [
         'client_id' => 'YOUR_DISCORD_CLIENT_ID',
         'secret_id' => 'YOUR_DISCORD_SECRET_ID',
         'scopes' => 'identify email', // Adjust scopes as needed
         'domainurl' => 'https://billing.yourdomain.com/', // Update with your WHMCS domain URL
         'redirect_uri' => 'https://billing.yourdomain.com/discord.php',
         'guild_id' => 'YOUR_DISCORD_GUILD_ID',
         'role_id' => 'YOUR_DISCORD_ROLE_ID',
         'bot_token' => 'YOUR_DISCORD_BOT_TOKEN'
     ];
     ```
     Store `config.php` securely outside the web root or use environment variables to prevent unauthorized access.

2. **Update Discord.php**:
   - In `discord.php`, update the line to load sensitive information from `config.php`:
     ```php
     // Load sensitive information from config file
     $config = require __DIR__ . '/config.php';
     ```

3. **Create Custom Field**:
   - [Create a custom client field named `discord` in WHMCS](https://docs.whmcs.com/Custom_Client_Fields) to store the customer's Discord ID.

4. **Usage**:
   - Paying customers can visit `/discord.php` to link their Discord account.
   - Upon verification, they will receive a Discord role based on their active product status in WHMCS.

## Disclaimer

This project does not include ongoing support. Use at your own discretion.

If you find this project helpful, consider giving it a star! 🌟
