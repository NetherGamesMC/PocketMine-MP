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

use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\HorizontalFacingTrait;
use pocketmine\block\utils\PoweredByRedstone;
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;

class RedstoneRepeater extends Flowable implements PoweredByRedstone, HorizontalFacing{
	use HorizontalFacingTrait;
	use PoweredByRedstoneTrait;
	use StaticSupportTrait;

	public const MIN_DELAY = 1;
	public const MAX_DELAY = 4;

	protected int $delay = self::MIN_DELAY;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->boundedIntAuto(self::MIN_DELAY, self::MAX_DELAY, $this->delay);
		$w->bool($this->powered);
	}

	public function getDelay() : int{ return $this->delay; }

	/** @return $this */
	public function setDelay(int $delay) : self{
		if($delay < self::MIN_DELAY || $delay > self::MAX_DELAY){
			throw new \InvalidArgumentException("Delay must be in range " . self::MIN_DELAY . " ... " . self::MAX_DELAY);
		}
		$this->delay = $delay;
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
		if(++$this->delay > self::MAX_DELAY){
			$this->delay = self::MIN_DELAY;
		}
		$this->position->getWorld()->setBlock($this->position, $this);
		return true;
	}

	private function canBeSupportedAt(Block $block) : bool{
		return $block->getAdjacentSupportType(Facing::DOWN) !== SupportType::NONE;
	}

	public function onNearbyBlockChange() : void{
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, $this->delay);
	}

	public function onScheduledUpdate() : void{
		$shouldPower = $this->getInputSignal() > 0;
		if($shouldPower !== $this->powered){
			$this->powered = $shouldPower;
			$world = $this->position->getWorld();
			$world->setBlock($this->position, $this);
			foreach(Facing::ALL as $face){
				$world->getBlock($this->position->getSide($face))->onNearbyBlockChange();
			}
		}
	}

	private function getInputSignal() : int{
		$inputFace = $this->facing;
		$back = $this->getSide($inputFace);
		return $this->readSignalFromBlock($back, $inputFace);
	}

	private function readSignalFromBlock(Block $block, int $faceFromSelf) : int{
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
		if($block instanceof \pocketmine\block\utils\AnalogRedstoneSignalEmitter){
			return $block->getOutputSignalStrength();
		}
		if($block instanceof PoweredByRedstone && $block->isPowered()){
			return 15;
		}
		return 0;
	}
}
