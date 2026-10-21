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

use pocketmine\event\block\GeyserEruptionPulseEvent;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\player\Player;
use pocketmine\world\sound\Sound;
use function mt_rand;

abstract class EruptivePotentSulfur extends WetPotentSulfur{

	private const ERUPTION_SOUND_INTERVAL_HEARTBEATS = 4; //2 seconds

	/** Initial upward motion limit in blocks per tick. */
	private const BASE_LAUNCH_SPEED = 0.3;
	/** Vertical acceleration added to entity motion per pulse. */
	private const LAUNCH_FORCE = 0.2;

	private const PLUME_BLOCKS_PER_DEPTH = 6;

	abstract public function isErupting() : bool;
	abstract public function getEruptionStartSound() : Sound;
	abstract public function getEruptionBurstSound() : Sound;

	protected function onGeyserHeartbeat(?Block $outlet) : void{
		parent::onGeyserHeartbeat($outlet);

		if($outlet !== null && $this->isErupting()){
			$cancelled = false;
			if(GeyserEruptionPulseEvent::hasHandlers()){
				$ev = new GeyserEruptionPulseEvent($this, $outlet);
				$ev->call();
				$cancelled = $ev->isCancelled();
			}

			if(!$cancelled){
				$this->pulseEruption($outlet);
			}
		}
	}

	/**
	 * Returns how many blocks upward the plume can extend before hitting an obstacle.
	 */
	private function getPlumeHeight(int $columnDepth) : int{
		$maxHeight = self::PLUME_BLOCKS_PER_DEPTH * $columnDepth;

		for($i = 0; $i < $maxHeight; $i++){
			if(!$this->isGeyserPassable($this->getSide(Facing::UP, $i + 1))){
				return $i;
			}
		}

		return $maxHeight;
	}

	/**
	 * Triggers the eruption push effect and periodically plays the pulse sound.
	 */
	private function pulseEruption(Block $outlet) : void{
		$this->pulsePush($outlet);

		if(mt_rand(1, self::ERUPTION_SOUND_INTERVAL_HEARTBEATS) === 1){
			$this->position->getWorld()->addSound($outlet->position->add(0.5, 0.5, 0.5), $this->getEruptionBurstSound());
		}
	}

	/**
	 * Pushes entities upward within the active plume area.
	 */
	private function pulsePush(Block $outlet) : void{
		$columnLength = $this->getWaterColumnLength($outlet);
		$plumeHeight = $this->getPlumeHeight($columnLength);
		if($plumeHeight <= 0){
			return;
		}

		$bb = AxisAlignedBB::one()
			->offset($this->position->x, $this->position->y + 1, $this->position->z)
			->extend(Facing::UP, $plumeHeight - 1);
		$maxSpeed = self::BASE_LAUNCH_SPEED + $columnLength * 0.1;

		foreach($this->position->getWorld()->getCollidingEntities($bb) as $entity){
			if($entity instanceof Player && $entity->isFlying()){
				continue;
			}

			$entity->resetFallDistance();
			if($entity->getMotion()->y < $maxSpeed){
				$entity->addMotion(0, self::LAUNCH_FORCE, 0);
			}
		}
	}
}
