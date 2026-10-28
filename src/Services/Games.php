<?php

declare(strict_types=1);

namespace Simplify\Subdomains\Services;

/** Which servers get an SRV record. */
final class Games
{
    /**
     * Minecraft Java servers, from the egg's name and features. Bedrock has no SRV lookup, so it is handled like
     * any other game: players add the port.
     *
     * @param  list<string>  $features
     */
    public static function minecraftJava(string $eggName, array $features = []): bool
    {
        if (preg_match('/bedrock|pocketmine|nukkit/i', $eggName) === 1) {
            return false;
        }

        return in_array('eula', $features, true)
            || preg_match('/minecraft|vanilla|paper|purpur|spigot|bukkit|folia|velocity|bungee|waterfall|modrinth|curseforge|ftb|forge|fabric|quilt|technic/i', $eggName) === 1;
    }
}
