<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\handler;

use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerUIIds;

final class ItemStackContainerIdTranslator{

	/**
	 * Server-side-only bookkeeping key (never sent on the wire - the client only ever sends a ContainerUIIds value
	 * + a slot ID, never this number) for the always-available scratch inventory that RECIPE_FOOD_CONTAINER/
	 * RECIPE_BLOCKS_CONTAINER/RECIPE_FURNACE_ITEMS_CONTAINER resolve to (see translate() below). Deliberately far
	 * outside every range ContainerIds itself defines (0, 1-100, 119-125) so it can never collide with a real
	 * dynamically-assigned window ID.
	 */
	public const RECIPE_PREVIEW_WINDOW_ID = 200;

	private function __construct(){
		//NOOP
	}

	/**
	 * @return int[]
	 * @phpstan-return array{int, int}
	 * @throws ItemStackRequestProcessException
	 */
	public static function translate(int $containerInterfaceId, int $currentWindowId, int $slotId) : array{
		return match($containerInterfaceId){
			ContainerUIIds::ARMOR => [ContainerIds::ARMOR, $slotId],

			ContainerUIIds::HOTBAR,
			ContainerUIIds::INVENTORY,
			ContainerUIIds::COMBINED_HOTBAR_AND_INVENTORY => [ContainerIds::INVENTORY, $slotId],

			//TODO: HACK! The client sends an incorrect slot ID for the offhand as of 1.19.70 (though this doesn't really matter since the offhand has only 1 slot anyway)
			ContainerUIIds::OFFHAND => [ContainerIds::OFFHAND, 0],

			ContainerUIIds::ANVIL_INPUT,
			ContainerUIIds::ANVIL_MATERIAL,
			ContainerUIIds::BEACON_PAYMENT,
			ContainerUIIds::CARTOGRAPHY_ADDITIONAL,
			ContainerUIIds::CARTOGRAPHY_INPUT,
			ContainerUIIds::COMPOUND_CREATOR_INPUT,
			ContainerUIIds::CRAFTING_INPUT,
			ContainerUIIds::CREATED_OUTPUT,
			ContainerUIIds::CURSOR,
			ContainerUIIds::ENCHANTING_INPUT,
			ContainerUIIds::ENCHANTING_MATERIAL,
			ContainerUIIds::GRINDSTONE_ADDITIONAL,
			ContainerUIIds::GRINDSTONE_INPUT,
			ContainerUIIds::LAB_TABLE_INPUT,
			ContainerUIIds::LOOM_DYE,
			ContainerUIIds::LOOM_INPUT,
			ContainerUIIds::LOOM_MATERIAL,
			ContainerUIIds::MATERIAL_REDUCER_INPUT,
			ContainerUIIds::MATERIAL_REDUCER_OUTPUT,
			ContainerUIIds::SMITHING_TABLE_INPUT,
			ContainerUIIds::SMITHING_TABLE_MATERIAL,
			ContainerUIIds::SMITHING_TABLE_TEMPLATE,
			ContainerUIIds::STONECUTTER_INPUT,
			ContainerUIIds::TRADE2_INGREDIENT1,
			ContainerUIIds::TRADE2_INGREDIENT2,
			ContainerUIIds::TRADE_INGREDIENT1,
			ContainerUIIds::TRADE_INGREDIENT2 => [ContainerIds::UI, $slotId],

			ContainerUIIds::BARREL,
			ContainerUIIds::BLAST_FURNACE_INGREDIENT,
			ContainerUIIds::BREWING_STAND_FUEL,
			ContainerUIIds::BREWING_STAND_INPUT,
			ContainerUIIds::BREWING_STAND_RESULT,
			ContainerUIIds::FURNACE_FUEL,
			ContainerUIIds::FURNACE_INGREDIENT,
			ContainerUIIds::FURNACE_RESULT,
			ContainerUIIds::HORSE_EQUIP,
			ContainerUIIds::LEVEL_ENTITY, //chest
			ContainerUIIds::SHULKER_BOX,
			ContainerUIIds::SMOKER_INGREDIENT => [$currentWindowId, $slotId],

			//all preview slots are ignored, since the client shouldn't be modifying those directly

			//Newer clients (recipe book merged into survival inventory/chest screens) send Take/Place actions
			//against these virtual "recipe ingredient preview" containers even for perfectly ordinary item moves
			//that have nothing to do with crafting - we don't know the exact client-side semantics, but routing
			//them at a real (if throwaway) inventory instead of throwing lets whatever Take+Place pair the client
			//sends round-trip through it like the cursor inventory does, instead of failing the action outright.
			//See InventoryManager::RECIPE_PREVIEW_WINDOW_ID for where this is registered.
			ContainerUIIds::RECIPE_FOOD_CONTAINER,
			ContainerUIIds::RECIPE_BLOCKS_CONTAINER,
			ContainerUIIds::RECIPE_FURNACE_ITEMS_CONTAINER => [self::RECIPE_PREVIEW_WINDOW_ID, $slotId],

			//Any other container UI ID this version doesn't recognise yet falls through to here. This used to throw
			//PacketHandlingException, which isn't caught anywhere below ItemStackRequestExecutor and propagated all
			//the way up to NetworkSession as a fatal "bad packet", killing the player's connection over what's
			//really just an unsupported action. ItemStackRequestProcessException is the exception type every caller
			//in this call chain already expects and safely catches (rejects just this one request, re-syncs the
			//player's inventory, keeps them connected).
			default => throw new ItemStackRequestProcessException("Unexpected container UI ID $containerInterfaceId")
		};
	}
}
