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

use pocketmine\block\utils\AnalogRedstoneSignalEmitter;
use pocketmine\block\utils\AnalogRedstoneSignalEmitterTrait;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;

class RedstoneWire extends Flowable implements AnalogRedstoneSignalEmitter{
	use AnalogRedstoneSignalEmitterTrait;
	use StaticSupportTrait;

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		//TODO: check connections to nearby redstone components

		return $this;
	}

	public function onNearbyBlockChange() : void{
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 1);
	}

	public function onScheduledUpdate() : void{
		$newSignal = $this->calculateSignalStrength();
		if($newSignal !== $this->signalStrength){
			$this->signalStrength = $newSignal;
			$world = $this->position->getWorld();
			$world->setBlock($this->position, $this);
			$this->notifyConnectedNeighbors();
		}
	}

	private function canBeSupportedAt(Block $block) : bool{
		return $block->getAdjacentSupportType(Facing::DOWN)->hasCenterSupport();
	}

	private function calculateSignalStrength() : int{
		$max = 0;
		foreach(Facing::ALL as $face){
			$side = $this->getSide($face);
			if($side->getTypeId() === BlockTypeIds::REDSTONE){
				$max = max($max, 15);
				continue;
			}
			if($side instanceof Lever && $side->isActivated()){
				$max = max($max, 15);
				continue;
			}
			if($side instanceof Button && $side->isPressed()){
				$max = max($max, 15);
				continue;
			}
			if($side instanceof SimplePressurePlate && $side->isPressed()){
				$max = max($max, 15);
				continue;
			}
			if($side instanceof RedstoneRepeater){
				$outputFace = Facing::opposite($side->getFacing());
				if($side->isPowered() && $outputFace === Facing::opposite($face)){
					$max = max($max, 15);
				}
				continue;
			}
			if($side instanceof RedstoneWire){
				$max = max($max, $side->getOutputSignalStrength() - 1);
				continue;
			}
			if($side instanceof AnalogRedstoneSignalEmitter){
				$max = max($max, $this->readDirectionalAnalogSignal($side, $face));
				continue;
			}
		}

		// Vertical/climbing connectivity across neighboring blocks (wire up/down slopes).
		foreach([Facing::NORTH, Facing::SOUTH, Facing::WEST, Facing::EAST] as $hFace){
			$adjacent = $this->getSide($hFace);

			// Wire climbing up onto a solid neighboring block.
			if(!$adjacent->isTransparent()){
				$upWire = $adjacent->getSide(Facing::UP);
				if($upWire instanceof RedstoneWire){
					$max = max($max, $upWire->getOutputSignalStrength() - 1);
				}
			}else{
				// Wire running down one block when adjacent space is not solid.
				$downWire = $adjacent->getSide(Facing::DOWN);
				if($downWire instanceof RedstoneWire){
					$max = max($max, $downWire->getOutputSignalStrength() - 1);
				}
			}
		}

		return max(0, min(15, $max));
	}

	private function readDirectionalAnalogSignal(AnalogRedstoneSignalEmitter $source, int $faceFromSelf) : int{
		// This fork uses inverted facing conventions for some directional redstone blocks.
		// Treat output side explicitly so dust doesn't accept power from repeater/comparator rear.
		if($source instanceof RedstoneRepeater){
			$outputFace = Facing::opposite($source->getFacing());
			return $source->isPowered() && $outputFace === Facing::opposite($faceFromSelf) ? 15 : 0;
		}
		if($source instanceof RedstoneComparator){
			$outputFace = Facing::opposite($source->getFacing());
			return $outputFace === Facing::opposite($faceFromSelf) ? $source->getOutputSignalStrength() : 0;
		}
		return $source->getOutputSignalStrength();
	}

	private function notifyConnectedNeighbors() : void{
		$world = $this->position->getWorld();
		foreach(Facing::ALL as $face){
			$neighborPos = $this->position->getSide($face);
			$world->getBlock($neighborPos)->onNearbyBlockChange();
		}
		// notify diagonal climb/drop connections so elevated dust recalculates when source turns off
		foreach([Facing::NORTH, Facing::SOUTH, Facing::WEST, Facing::EAST] as $hFace){
			$adjacentPos = $this->position->getSide($hFace);
			$adjacent = $world->getBlock($adjacentPos);
			if(!$adjacent->isTransparent()){
				$world->getBlock($adjacentPos->getSide(Facing::UP))->onNearbyBlockChange();
			}else{
				$world->getBlock($adjacentPos->getSide(Facing::DOWN))->onNearbyBlockChange();
			}
		}
	}

	public function asItem() : Item{
		return VanillaItems::REDSTONE_DUST();
	}
}
