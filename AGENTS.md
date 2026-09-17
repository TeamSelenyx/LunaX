# LunaX Minecraft protocol updates

When adding support for a new Minecraft: Bedrock Edition version, use the PocketMine-MP protocol update guide as the primary workflow reference:

- https://doc.pmmp.io/en/rtfd/developers/internals-docs/updating-minecraft-protocol.html

Follow the guide in order: obtain and verify protocol/supporting data, update BedrockProtocol and PocketMine-MP integration, regenerate BedrockData-derived code, update every applicable constant in `src/data/bedrock/WorldDataVersions.php`, then run PHPStan and PHPUnit. Compare packet structures against Mojang's official Bedrock protocol documentation and validate uncertain structures with vanilla packet traces when available.

Use update branch names in the form `bedrock-<minecraft-version>` (for example, `bedrock-1.26.50`). Keep protocol-version claims traceable to an official schema, BDS dump, or captured vanilla traffic. Do not mark an update complete until login, world creation/loading, inventory, crafting, and representative gameplay packets have been playtested with the target client.
