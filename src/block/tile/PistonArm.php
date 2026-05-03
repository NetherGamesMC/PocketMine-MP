<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\TypeConverter;

class PistonArm extends Spawnable{
	private const TAG_STATE = "State"; //TAG_Byte
	private const TAG_NEW_STATE = "NewState"; //TAG_Byte
	private const TAG_PROGRESS = "Progress"; //TAG_Float
	private const TAG_LAST_PROGRESS = "LastProgress"; //TAG_Float
	private const TAG_STICKY = "Sticky"; //TAG_Byte

	private int $state = 0;
	private int $newState = 0;
	private float $progress = 0.0;
	private float $lastProgress = 0.0;
	private bool $sticky = false;

	public function readSaveData(CompoundTag $nbt) : void{
		$this->state = $nbt->getByte(self::TAG_STATE, 0);
		$this->newState = $nbt->getByte(self::TAG_NEW_STATE, $this->state);
		$this->progress = $nbt->getFloat(self::TAG_PROGRESS, 0.0);
		$this->lastProgress = $nbt->getFloat(self::TAG_LAST_PROGRESS, $this->progress);
		$this->sticky = $nbt->getByte(self::TAG_STICKY, 0) !== 0;
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setByte(self::TAG_STATE, $this->state);
		$nbt->setByte(self::TAG_NEW_STATE, $this->newState);
		$nbt->setFloat(self::TAG_PROGRESS, $this->progress);
		$nbt->setFloat(self::TAG_LAST_PROGRESS, $this->lastProgress);
		$nbt->setByte(self::TAG_STICKY, $this->sticky ? 1 : 0);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt, TypeConverter $typeConverter) : void{
		$nbt->setByte(self::TAG_STATE, $this->state);
		$nbt->setByte(self::TAG_NEW_STATE, $this->newState);
		$nbt->setFloat(self::TAG_PROGRESS, $this->progress);
		$nbt->setFloat(self::TAG_LAST_PROGRESS, $this->lastProgress);
		$nbt->setByte(self::TAG_STICKY, $this->sticky ? 1 : 0);
	}

	public function setSticky(bool $sticky) : void{
		$this->sticky = $sticky;
		$this->clearSpawnCompoundCache();
	}

	public function setExtended(bool $extended) : void{
		$this->lastProgress = $this->progress;
		$this->progress = $extended ? 1.0 : 0.0;
		$this->state = $extended ? 2 : 0;
		$this->newState = $this->state;
		$this->clearSpawnCompoundCache();
	}
}
