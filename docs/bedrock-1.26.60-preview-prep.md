# Bedrock 1.26.60 preview update preparation

Checked on 2026-09-21 against Mojang's `v1.26.60-preview.25` release (game build `1.26.60.25`, protocol `2211`). This is an analysis checkpoint, not a server compatibility claim.

## Downloaded inputs

- Windows preview BDS: `F:\minecraft\local\dev\deps-1.26.60\bedrock-server-1.26.60.25.zip`, extracted to `F:\minecraft\local\dev\BDS-1.26.60-preview.25`. SHA-256: `4C2C13B3D4EB9BCF204CDF3B306E98697A2D915BC0E0BE76B81E4ECC7661063B`.
- Mojang protocol metadata: `F:\minecraft\local\dev\deps-1.26.60\metadata-1.26.60-preview.25.zip`, extracted beside it (997 JSON schemas).
- Mojang developer notes: `F:\minecraft\local\dev\deps-1.26.60\developer_notes-1.26.60-preview.25.zip`, extracted beside it (212 files).

Sources: [official BDS preview ZIP](https://www.minecraft.net/bedrockdedicatedserver/bin-win-preview/bedrock-server-1.26.60.25.zip), [Mojang protocol metadata release](https://github.com/Mojang/bedrock-protocol-docs/releases/tag/v1.26.60-preview.25), [official preview changelog](https://feedback.minecraft.net/hc/en-us/articles/48940953884173-Minecraft-Beta-Preview-26-60-24).

## Current baseline and protocol changes

At the initial analysis checkpoint, `stable` targeted BedrockData and BedrockProtocol for 1.26.30 (protocol `1001`). The working branch now includes the existing 1.26.50 update (protocol `2193`). The downloaded preview declares protocol `2211`; the 1.26.50 final metadata used for comparison declares `2193`.

Compared with `F:\minecraft\local\dev\deps-1.26.50\metadata-1.26.50-final`, the preview metadata has 15 added schema files and no removed schema files. After ignoring version labels, descriptions, enum binary value annotations, and defaults, 52 existing schemas have structural changes.

Compared with the extracted 1.26.51 BDS, `definitions/` has the same 340 file paths. `behavior_packs/` grows from 2,482 to 2,821 files, with 340 new paths, mainly the new `vanilla_1.26.60` pack. `resource_packs/` keeps 196 files and replaces its versioned manifest path. These are file inventory differences; contents still need a data-level comparison.

Priority changes for the protocol fork:

1. Add packet IDs 353 `ClientboundMatchmakingState`, 354 `ServerboundStonecutterSetRecipe`, 355 `ClientboundStonecutterSetRecipe`, and 356 `ServerboundMatchmakingCancel`. The Stonecutter schemas appear in metadata even though `MinecraftPacketIds.json` only adds the matchmaking names; verify packet registration against the BDS binary or a live trace before implementation.
2. `LevelChunkPacketPayload` adds the boolean `Is Client Biome Update`. Check serialization order and chunk sender behavior.
3. `AnimatePacketPayload` adds a `Hand` field. Check encode and decode of hand animations.
4. `ClientboundUpdateSoundDataPacketPayload` has extensive structural changes. Inspect its action variants and related `Pause`, `Resume`, `SeekTo`, `SetPitch`, `SetVolume`, `Stop`, and `Fade` schemas together.
5. Inspect `ChangeDimension`, `ModalFormResponse`, `GraphicsParameterOverride`, `ServerboundLoadingScreen`, `UpdateClientOptions`, and the other changed packet payload schemas before updating the protocol library.
6. Review the added attribute and transition schemas (`ConstantAttributeData`, `NoiseTransitionAttributeData`, `NoiseTransitionSettingsData`, `TransitionAttributeData`, `TransitionSettingsData`) for world and biome data support.

## Suggested update sequence

1. Update the local BedrockProtocol fork against the downloaded metadata. Keep the preview version and protocol number explicit. Run its codec tests and capture a preview client login trace.
2. Compare the preview BDS definitions, behavior packs, and resource packs with the current BedrockData fork. Regenerate the required palettes, IDs, recipes, and mappings using the existing data generation tools.
3. Update `composer.json` and lock files to the tested local protocol and data revisions; regenerate `generated/` files with the repository's Composer scripts.
4. Run unit tests and a real preview client join/transfer test, including chunk display, inventory, forms, and dimension changes. Only then advertise 1.26.60 support.

Preview schemas can change before the final release. Preserve this input set so the final release can be diffed against it.

## Work in progress on 2026-09-21

The `bedrock-1.26.60` LunaX branch was created from `stable` and merged the existing `bedrock-1.26.50` branch as its starting point. Separate `bedrock-1.26.60` branches were created in the local TeamSelenyx BedrockProtocol and BedrockData repositories.

The protocol branch now contains the four new packet codecs, the protocol ID update, and the identified changes to chunk, animation, sound, inventory transaction, player list, skin, and dimension serialization. BedrockData's `protocol_info.json` now records preview build 1.26.60.25 and protocol 2211. These changes remain local and are not yet a supported release.

Validation so far: BedrockProtocol PHPStan passes; its PHPUnit suite passes (477 tests). LunaX PHPUnit passes (190 tests) when the in-progress protocol source is copied into the ignored local `vendor` directory. LunaX PHPStan reports four unrelated existing errors in `src/Server.php` and `src/resourcepacks/ResourcePackCdnServer.php`. A source bootstrap `--version` command reports `v26.60.25 beta` with that local protocol source. An isolated server smoke test using the local protocol source started successfully, generated a fresh `preview-smoke` world, and shut down cleanly; its data is under `F:\minecraft\local\dev\deps-1.26.60\lunax-smoke`.

Remaining work: implement the changed environment attribute payload and verify uncertain wire details with a vanilla trace; extract and validate the preview block palette, item/recipe data, and upgrade schemas; update the LunaX dependency manifest and lock file to published test revisions; regenerate BedrockData-derived code; update every applicable `WorldDataVersions` constant; and playtest the target client. The public BDS ZIP does not provide the debug symbols required for the guide's data extraction workflow. No preview client is available to this agent for login and gameplay validation.
