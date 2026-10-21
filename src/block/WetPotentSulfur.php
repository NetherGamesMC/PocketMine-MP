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

use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Living;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\math\VoxelRayTrace;
use function count;

class WetPotentSulfur extends PotentSulfur{

	private const HEARTBEAT_TICKS = 10;

	private const MAX_COLUMN_HEIGHT_BLOCKS = 4;

	private const GAS_EFFECT_DURATION_TICKS = 80; //4 seconds
	private const GAS_EFFECT_RANGE_BLOCKS = 3.0;

	public function asItem() : Item{
		return VanillaBlocks::POTENT_SULFUR()->asItem();
	}

	public function onScheduledUpdate() : void{
		$this->onGeyserHeartbeat($this->findGeyserOutlet());
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, self::HEARTBEAT_TICKS);
	}

	protected function onGeyserVariantApplied() : void{
		//A dry block doesn't tick, so its heartbeat has died by the time it becomes a geyser and has to be started again
		//here. Scheduling is idempotent at this delay, so doing it for a variant that is already beating costs nothing.
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, self::HEARTBEAT_TICKS);
	}

	/**
	 * Handles the geyser's periodic heartbeat update.
	 * Called every {@link WetPotentSulfur::HEARTBEAT_TICKS} ticks.
	 */
	protected function onGeyserHeartbeat(?Block $outlet) : void{
		if($outlet !== null){
			$this->pulseGas($outlet);
		}
	}

	/**
	 * Returns whether the given block can be pushed through by the geyser plume.
	 */
	protected function isGeyserPassable(Block $block) : bool{
		return count($block->getCollisionBoxes()) === 0;
	}

	/**
	 * Returns the number of water blocks between this block and the outlet.
	 */
	protected function getWaterColumnLength(Block $outlet) : int{
		return $outlet->position->getFloorY() - $this->position->getFloorY() - 1;
	}

	/**
	 * Returns the first outlet block found by walking up the water column
	 * above this block, or null if not found.
	 */
	protected function findGeyserOutlet() : ?Block{
		$maxY = $this->position->getFloorY() + self::MAX_COLUMN_HEIGHT_BLOCKS + 1;

		$block = $this->getSide(Facing::UP);
		while($block->position->getFloorY() <= $maxY){
			if($block instanceof Water && $block->isSource()){
				$block = $block->getSide(Facing::UP);
				continue;
			}

			return $this->isGeyserPassable($block) ? $block : null;
		}

		return null;
	}

	/**
	 * Applies the nausea gas effect to living entities near the geyser outlet.
	 *
	 * Only affects entities whose eye position is within range of the outlet, exposed
	 * to the gas (no blocking block overhead), and standing in source water.
	 */
	private function pulseGas(Block $outlet) : void{
		$world = $this->position->getWorld();
		$bb = AxisAlignedBB::one()
			->offset($outlet->position->x, $outlet->position->y, $outlet->position->z)
			->expand(2.5, 0.0, 2.5);

		foreach($world->getCollidingEntities($bb) as $entity){
			if(!$entity instanceof Living){
				continue;
			}

			if($this->isExposedToGas($outlet, $entity->getEyePos())){
				$entity->getEffects()->add(new EffectInstance(VanillaEffects::NAUSEA(), self::GAS_EFFECT_DURATION_TICKS, 0, true, true));
			}
		}
	}

	private function isExposedToGas(Block $outlet, Vector3 $eyePos) : bool{
		$world = $this->position->getWorld();
		if(!$this->isGeyserPassable($world->getBlock($eyePos))){
			return false;
		}
		if($eyePos->distanceSquared($outlet->position->add(0.5, 0.5, 0.5)) > self::GAS_EFFECT_RANGE_BLOCKS ** 2){
			return false;
		}

		$feetPos = new Vector3($eyePos->x, $eyePos->y - 1, $eyePos->z);
		$feetBlock = $world->getBlock($feetPos);
		if(!($feetBlock instanceof Water) || !$feetBlock->isSource()){
			return false;
		}

		$center = $outlet->position->add(0.5, -0.5, 0.5);
		foreach(VoxelRayTrace::betweenPoints($center, $feetPos) as $blockPos){
			$block = $world->getBlockAt($blockPos->getFloorX(), $blockPos->getFloorY(), $blockPos->getFloorZ());
			foreach($block->getCollisionBoxes() as $bb){
				if($bb->calculateIntercept($center, $feetPos) !== null){
					return false;
				}
			}
		}

		return true;
	}
}
