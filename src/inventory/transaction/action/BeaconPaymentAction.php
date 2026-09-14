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

namespace pocketmine\inventory\transaction\action;

use pocketmine\block\Beacon;
use pocketmine\block\inventory\BeaconInventory;
use pocketmine\entity\effect\Effect;
use pocketmine\inventory\transaction\TransactionValidationException;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;

/**
 * This action is generated when a player confirms an effect selection in a beacon's UI
 * (BeaconPaymentStackRequestAction on the wire). The output is the payment item, destroyed.
 */
class BeaconPaymentAction extends InventoryAction{

	public function __construct(
		protected BeaconInventory $inventory,
		protected Effect $primaryEffect,
		protected ?Effect $secondaryEffect
	){
		parent::__construct(VanillaItems::AIR(), VanillaItems::AIR());
	}

	public function getInventory() : BeaconInventory{
		return $this->inventory;
	}

	public function validate(Player $source) : void{
		$input = $this->inventory->getInput();
		if(!isset(Beacon::ALLOWED_ITEM_IDS[$input->getTypeId()]) || $input->getCount() < 1){
			throw new TransactionValidationException("Invalid input item");
		}

		$position = $this->inventory->getHolder();
		$block = $position->getWorld()->getBlock($position);
		if(!$block instanceof Beacon){
			throw new TransactionValidationException("Target block is not a beacon");
		}
	}

	public function execute(Player $source) : void{
		$position = $this->inventory->getHolder();
		$world = $position->getWorld();
		$block = $world->getBlock($position);

		if(!$block instanceof Beacon){
			return;
		}

		$block->setPrimaryEffect($this->primaryEffect);
		$block->setSecondaryEffect($this->secondaryEffect);
	}
}