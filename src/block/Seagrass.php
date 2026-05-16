<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\SeagrassType;
use pocketmine\data\runtime\RuntimeDataDescriber;

class Seagrass extends Flowable{

	private SeagrassType $seagrassType = SeagrassType::DEFAULT;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->enum($this->seagrassType);
	}

	public function getSeagrassType() : SeagrassType{
		return $this->seagrassType;
	}

	public function setSeagrassType(SeagrassType $seagrassType) : self{
		$this->seagrassType = $seagrassType;
		return $this;
	}
}