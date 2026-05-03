<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\item\Item;

class StickyPistonHead extends PistonHead{
	public function getDropsForCompatibleTool(Item $item) : array{
		return [VanillaBlocks::STICKY_PISTON()->asItem()];
	}
}
