<?php

declare(strict_types=1);

namespace pocketmine\event\block;

use pocketmine\block\Block;
use pocketmine\entity\Entity;
use pocketmine\event\Event;
use pocketmine\item\Item;
use pocketmine\player\Player;

final class BlockCanBuildEvent extends Event{

	public const REASON_ENTITY_COLLISION = "entity_collision";

	/**
	 * @param Entity[] $collidingEntities
	 */
	public function __construct(
		private ?Player $player,
		private Block $blockPlace,
		private Block $blockReplace,
		private Block $blockAgainst,
		private Item $item,
		private bool $buildable,
		private string $reason,
		private array $collidingEntities = []
	){}

	public function getPlayer() : ?Player{
		return $this->player;
	}

	public function getBlockPlace() : Block{
		return $this->blockPlace;
	}

	public function getBlockReplace() : Block{
		return $this->blockReplace;
	}

	public function getBlockAgainst() : Block{
		return $this->blockAgainst;
	}

	public function getItem() : Item{
		return clone $this->item;
	}

	public function isBuildable() : bool{
		return $this->buildable;
	}

	public function setBuildable(bool $buildable) : void{
		$this->buildable = $buildable;
	}

	public function getReason() : string{
		return $this->reason;
	}

	/**
	 * @return Entity[]
	 */
	public function getCollidingEntities() : array{
		return $this->collidingEntities;
	}
}
