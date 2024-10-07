<div class="w-100 text-center">
    <h1>{$message}</h1>
    {if !$verified}
        <a class="btn btn-primary mt-2" href="{$smarty.const.SITE_URL}/discord.php">
            <i class="fas fa-check"></i> Verify Discord
        </a>
    {/if}
    <a class="btn btn-secondary mt-2" href="/">
        <i class="fas fa-house"></i> Return to home
    </a>
</div>
