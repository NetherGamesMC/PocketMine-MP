<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\block\inventory\DropperInventory;
use pocketmine\math\Vector3;
use pocketmine\world\World;

class Dropper extends Dispenser{
	public function __construct(World $world, Vector3 $pos){
		parent::__construct($world, $pos);
		$this->inventory = new DropperInventory($this->position);
	}

	public function getDefaultName() : string{
		return "Dropper";
	}
}
