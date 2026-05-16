<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\AnyFacing;
use pocketmine\block\utils\AnyFacingTrait;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;

class Observer extends Opaque implements AnyFacing{
	use AnyFacingTrait;

	public function place(
		BlockTransaction $tx,
		Item $item,
		Block $blockReplace,
		Block $blockClicked,
		int $face,
		Vector3 $clickVector,
		?Player $player = null
	) : bool{
		if($player !== null){
			$direction = $player->getDirectionVector();

			$absX = abs($direction->x);
			$absY = abs($direction->y);
			$absZ = abs($direction->z);

			if($absY >= $absX && $absY >= $absZ){
				$this->facing = $direction->y >= 0 ? Facing::UP : Facing::DOWN;
			}elseif($absX >= $absZ){
				$this->facing = $direction->x >= 0 ? Facing::EAST : Facing::WEST;
			}else{
				$this->facing = $direction->z >= 0 ? Facing::SOUTH : Facing::NORTH;
			}
		}else{
			$this->facing = $face;
		}

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}
}