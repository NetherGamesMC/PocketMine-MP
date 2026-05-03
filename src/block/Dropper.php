<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\item\Item;

class Dropper extends Dispenser{
	protected function shouldLaunchProjectile(Item $item) : bool{
		return false;
	}
}
