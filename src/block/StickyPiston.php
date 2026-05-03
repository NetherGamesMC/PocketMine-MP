<?php

declare(strict_types=1);

namespace pocketmine\block;

class StickyPiston extends Piston{
	protected function isSticky() : bool{
		return true;
	}

	protected function getMaxPushBlocks() : int{
		return 9;
	}
}
