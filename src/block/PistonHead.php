<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\AnyFacing;
use pocketmine\block\utils\AnyFacingTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\player\Player;

class PistonHead extends Transparent implements AnyFacing{
	use AnyFacingTrait;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facing($this->facing);
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$world = $this->position->getWorld();
		$basePos = $this->position->getSide(\pocketmine\math\Facing::opposite($this->facing));
		$base = $world->getBlock($basePos);
		if($base instanceof Piston){
			$base->onBreak($item, $player, $returnedItems);
		}
		return parent::onBreak($item, $player, $returnedItems);
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [VanillaBlocks::PISTON()->asItem()];
	}
}
