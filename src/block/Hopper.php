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

namespace pocketmine\block;

use pocketmine\block\tile\Container as TileContainer;
use pocketmine\block\tile\Hopper as TileHopper;
use pocketmine\block\utils\PoweredByRedstone;
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\object\ItemEntity;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\World;

class Hopper extends Transparent implements PoweredByRedstone{
	use PoweredByRedstoneTrait;

	private int $facing = Facing::DOWN;
	private const TRANSFER_DELAY_TICKS = 8;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facingExcept($this->facing, Facing::UP);
		$w->bool($this->powered);
	}

	public function getFacing() : int{ return $this->facing; }

	/** @return $this */
	public function setFacing(int $facing) : self{
		if($facing === Facing::UP){
			throw new \InvalidArgumentException("Hopper may not face upward");
		}
		$this->facing = $facing;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		$result = [
			AxisAlignedBB::one()->trim(Facing::UP, 6 / 16) //the empty area around the bottom is currently considered solid
		];

		foreach(Facing::HORIZONTAL as $f){ //add the frame parts around the bowl
			$result[] = AxisAlignedBB::one()->trim($f, 14 / 16);
		}
		return $result;
	}

	public function getSupportType(int $facing) : SupportType{
		return match($facing){
			Facing::UP => SupportType::FULL,
			Facing::DOWN => $this->facing === Facing::DOWN ? SupportType::CENTER : SupportType::NONE,
			default => SupportType::NONE
		};
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->facing = $face === Facing::DOWN ? Facing::DOWN : Facing::opposite($face);

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player !== null){
			$tile = $this->position->getWorld()->getTile($this->position);
			if($tile instanceof TileHopper){ //TODO: find a way to have inventories open on click without this boilerplate in every block
				$player->setCurrentWindow($tile->getInventory());
			}
			return true;
		}
		return false;
	}

	public function onPostPlace() : void{
		$this->getOrCreateTile($this->position->getWorld());
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 1);
	}

	public function onNearbyBlockChange() : void{
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 1);
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$poweredNow = $this->isPoweredByNeighbors();
		if($poweredNow !== $this->powered){
			$this->powered = $poweredNow;
			$world->setBlock($this->position, $this);
		}

		$didTransfer = false;
		if(!$this->powered){
			$didTransfer = $this->transferItems($world);
		}

		$world->scheduleDelayedBlockUpdate($this->position, $didTransfer ? self::TRANSFER_DELAY_TICKS : 1);
	}

	private function transferItems(World $world) : bool{
		$tile = $this->getOrCreateTile($world);
		if(!$tile instanceof TileHopper){
			return false;
		}

		$hopperInventory = $tile->getInventory();
		// Strict throttle: exactly one transfer action per cycle.
		// Prefer input first to avoid dropped items waiting when there is free space.
		if($this->hasSpace($hopperInventory)){
			if($this->pullFromAboveItems($hopperInventory, $world)){
				return true;
			}
			if($this->pullFromAboveContainer($hopperInventory, $world)){
				return true;
			}
		}

		return $this->pushToOutput($hopperInventory, $world);
	}

	private function pushToOutput(Inventory $hopperInventory, World $world) : bool{
		$targetBlock = $this->getSide($this->facing);
		$targetTile = $world->getTile($targetBlock->position);
		if(!$targetTile instanceof TileContainer){
			return false;
		}

		return $this->moveOneItem($hopperInventory, $targetTile->getRealInventory());
	}

	private function pullFromAboveContainer(Inventory $hopperInventory, World $world) : bool{
		$aboveTile = $world->getTile($this->position->getSide(Facing::UP));
		if(!$aboveTile instanceof TileContainer){
			return false;
		}

		return $this->moveOneItem($aboveTile->getRealInventory(), $hopperInventory);
	}

	private function pullFromAboveItems(Inventory $hopperInventory, World $world) : bool{
		// Include both the hopper top and the space immediately above it for reliable pickup.
		$box = (new AxisAlignedBB(
			$this->position->x,
			$this->position->y,
			$this->position->z,
			$this->position->x + 1,
			$this->position->y + 1.25,
			$this->position->z + 1
		))->expandedCopy(0.1, 0.1, 0.1);

		foreach($world->getNearbyEntities($box) as $entity){
			if(!$entity instanceof ItemEntity || $entity->isClosed() || !$entity->isAlive()){
				continue;
			}
			$item = $entity->getItem();
			if($item->isNull()){
				continue;
			}

			$toMove = clone $item;
			$leftovers = $hopperInventory->addItem($toMove);
			if(count($leftovers) === 0){
				$item->setCount($item->getCount() - $toMove->getCount());
				if($item->getCount() > 0){
					$entity->setStackSize($item->getCount());
				}else{
					$entity->flagForDespawn();
				}
				return true;
			}elseif($this->isCompletelyEmpty($hopperInventory) && count($leftovers) === 1){
				$moved = $toMove->getCount() - $leftovers[0]->getCount();
				if($moved > 0){
					$item->setCount($item->getCount() - $moved);
					if($item->getCount() > 0){
						$entity->setStackSize($item->getCount());
					}else{
						$entity->flagForDespawn();
					}
					return true;
				}
			}
		}

		return false;
	}

	private function moveOneItem(Inventory $from, Inventory $to) : bool{
		return $this->moveItems($from, $to, 1);
	}

	private function moveItems(Inventory $from, Inventory $to, int $countLimit) : bool{
		for($slot = 0, $size = $from->getSize(); $slot < $size; ++$slot){
			$stack = $from->getItem($slot);
			if($stack->isNull()){
				continue;
			}

			$addable = $to->getAddableItemQuantity($stack);
			if($addable <= 0){
				continue;
			}

			$moveCount = min($stack->getCount(), $countLimit, $addable);
			if($moveCount <= 0){
				continue;
			}

			$moving = clone $stack;
			$moving->setCount($moveCount);
			$leftovers = $to->addItem($moving);
			if(count($leftovers) === 0){
				$stack->setCount($stack->getCount() - $moveCount);
				$from->setItem($slot, $stack->getCount() > 0 ? $stack : $stack->setCount(0));
				return true;
			}elseif(count($leftovers) === 1){
				$moved = $moveCount - $leftovers[0]->getCount();
				if($moved > 0){
					$stack->setCount($stack->getCount() - $moved);
					$from->setItem($slot, $stack->getCount() > 0 ? $stack : $stack->setCount(0));
					return true;
				}
			}
		}

		return false;
	}

	private function hasSpace(Inventory $inventory) : bool{
		for($slot = 0, $size = $inventory->getSize(); $slot < $size; ++$slot){
			$item = $inventory->getItem($slot);
			if($item->isNull()){
				return true;
			}
			$maxStack = min($inventory->getMaxStackSize(), $item->getMaxStackSize());
			if($item->getCount() < $maxStack){
				return true;
			}
		}
		return false;
	}

	private function isCompletelyEmpty(Inventory $inventory) : bool{
		for($slot = 0, $size = $inventory->getSize(); $slot < $size; ++$slot){
			if(!$inventory->getItem($slot)->isNull()){
				return false;
			}
		}
		return true;
	}

	private function getOrCreateTile(World $world) : ?TileHopper{
		$tile = $world->getTile($this->position);
		if($tile instanceof TileHopper){
			return $tile;
		}

		$tile = new TileHopper($world, $this->position->asVector3());
		$world->addTile($tile);
		return $tile;
	}

	private function isPoweredByNeighbors() : bool{
		foreach(Facing::ALL as $face){
			$side = $this->getSide($face);
			if($side instanceof Redstone){
				return true;
			}
			if($side instanceof Lever && $side->isActivated()){
				return true;
			}
			if($side instanceof Button && $side->isPressed()){
				return true;
			}
			if($side instanceof SimplePressurePlate && $side->isPressed()){
				return true;
			}
			if($side instanceof RedstoneWire && $side->getOutputSignalStrength() > 0){
				return true;
			}
			if($side instanceof RedstoneRepeater && $side->isPowered()){
				return true;
			}
			if($side instanceof RedstoneComparator && $side->getOutputSignalStrength() > 0){
				return true;
			}
			if($side instanceof \pocketmine\block\utils\PoweredByRedstone && $side->isPowered()){
				return true;
			}
		}
		return false;
	}
}
