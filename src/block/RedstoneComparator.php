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

use pocketmine\block\tile\Comparator;
use pocketmine\block\tile\Container as TileContainer;
use pocketmine\block\utils\AnalogRedstoneSignalEmitter;
use pocketmine\block\utils\AnalogRedstoneSignalEmitterTrait;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\HorizontalFacingTrait;
use pocketmine\block\utils\PoweredByRedstone;
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use function assert;
use function floor;
use function max;
use function min;

class RedstoneComparator extends Flowable implements AnalogRedstoneSignalEmitter, PoweredByRedstone, HorizontalFacing{
	use HorizontalFacingTrait;
	use AnalogRedstoneSignalEmitterTrait;
	use PoweredByRedstoneTrait;
	use StaticSupportTrait;

	protected bool $isSubtractMode = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->bool($this->isSubtractMode);
		$w->bool($this->powered);
	}

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof Comparator){
			$this->signalStrength = $tile->getSignalStrength();
		}

		return $this;
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		assert($tile instanceof Comparator);
		$tile->setSignalStrength($this->signalStrength);
	}

	public function isSubtractMode() : bool{
		return $this->isSubtractMode;
	}

	/** @return $this */
	public function setSubtractMode(bool $isSubtractMode) : self{
		$this->isSubtractMode = $isSubtractMode;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim(Facing::UP, 7 / 8)];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			$this->facing = Facing::opposite($player->getHorizontalFacing());
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$this->isSubtractMode = !$this->isSubtractMode;
		$this->position->getWorld()->setBlock($this->position, $this);
		return true;
	}

	private function canBeSupportedAt(Block $block) : bool{
		return $block->getAdjacentSupportType(Facing::DOWN) !== SupportType::NONE;
	}

	public function onNearbyBlockChange() : void{
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 1);
	}

	public function onScheduledUpdate() : void{
		$rear = $this->getRearInputSignal();
		$side = $this->getSideInputSignal();

		$newOutput = $this->isSubtractMode
			? max(0, $rear - $side)
			: ($rear >= $side ? $rear : 0);
		$newPowered = $newOutput > 0;

		if($newOutput !== $this->signalStrength || $newPowered !== $this->powered){
			$this->signalStrength = $newOutput;
			$this->powered = $newPowered;
			$world = $this->position->getWorld();
			$world->setBlock($this->position, $this);
			foreach(Facing::ALL as $face){
				$world->getBlock($this->position->getSide($face))->onNearbyBlockChange();
			}
		}
	}

	private function getRearInputSignal() : int{
		// In this fork's facing conventions, comparator rear input is on the "facing" side.
		$inputFace = $this->facing;
		$back = $this->getSide($inputFace);
		return $this->readSignalFromBlock($back, $inputFace);
	}

	private function getSideInputSignal() : int{
		$leftFace = Facing::rotateY($this->facing, true);
		$rightFace = Facing::rotateY($this->facing, false);
		$left = $this->getSide($leftFace);
		$right = $this->getSide($rightFace);
		return max($this->readSignalFromBlock($left, $leftFace), $this->readSignalFromBlock($right, $rightFace));
	}

	private function readSignalFromBlock(Block $block, int $faceFromSelf) : int{
		if(($containerSignal = $this->readContainerSignal($block)) !== null){
			return $containerSignal;
		}
		if($block instanceof Redstone){
			return 15;
		}
		if($block instanceof Lever && $block->isActivated()){
			return 15;
		}
		if($block instanceof Button && $block->isPressed()){
			return 15;
		}
		if($block instanceof SimplePressurePlate && $block->isPressed()){
			return 15;
		}
		if($block instanceof RedstoneWire){
			return $block->getOutputSignalStrength();
		}
		if($block instanceof RedstoneRepeater){
			$outputFace = Facing::opposite($block->getFacing());
			return $block->isPowered() && $outputFace === Facing::opposite($faceFromSelf) ? 15 : 0;
		}
		if($block instanceof RedstoneComparator){
			$outputFace = Facing::opposite($block->getFacing());
			return $outputFace === Facing::opposite($faceFromSelf) ? $block->getOutputSignalStrength() : 0;
		}
		if($block instanceof AnalogRedstoneSignalEmitter){
			return $block->getOutputSignalStrength();
		}
		if($block instanceof PoweredByRedstone && $block->isPowered()){
			return 15;
		}
		return 0;
	}

	private function readContainerSignal(Block $block) : ?int{
		$tile = $this->position->getWorld()->getTile($block->position);
		if(!$tile instanceof TileContainer){
			return null;
		}

		return $this->calculateComparatorSignalFromInventory($tile->getRealInventory());
	}

	private function calculateComparatorSignalFromInventory(Inventory $inventory) : int{
		$totalSlots = $inventory->getSize();
		if($totalSlots <= 0){
			return 0;
		}

		$fullness = 0.0;
		$nonEmpty = 0;
		for($slot = 0; $slot < $totalSlots; ++$slot){
			$item = $inventory->getItem($slot);
			if($item->isNull()){
				continue;
			}

			$slotMax = min($inventory->getMaxStackSize(), $item->getMaxStackSize());
			if($slotMax <= 0){
				continue;
			}

			$fullness += $item->getCount() / $slotMax;
			++$nonEmpty;
		}

		if($nonEmpty === 0){
			return 0;
		}

		return 1 + (int) floor(($fullness / $totalSlots) * 14);
	}
}
