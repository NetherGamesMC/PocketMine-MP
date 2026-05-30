<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\tile\PistonArm as TilePistonArm;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\utils\AnyFacing;
use pocketmine\block\utils\AnyFacingTrait;
use pocketmine\block\utils\PoweredByRedstone;
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use function abs;

class Piston extends Opaque implements AnyFacing, PoweredByRedstone{
	use AnyFacingTrait;
	use PoweredByRedstoneTrait;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facing($this->facing);
		$w->bool($this->powered);
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			if(abs($player->getPosition()->x - $this->position->x) < 2 && abs($player->getPosition()->z - $this->position->z) < 2){
				$y = $player->getEyePos()->y;

				if($y - $this->position->y > 2){
					$this->facing = Facing::UP;
				}elseif($this->position->y - $y > 0){
					$this->facing = Facing::DOWN;
				}else{
					$this->facing = $player->getHorizontalFacing();
				}
			}else{
				$this->facing = $player->getHorizontalFacing();
			}
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 1);
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$this->retractHead();
		return parent::onBreak($item, $player, $returnedItems);
	}

	public function onPostPlace() : void{
		$world = $this->position->getWorld();
		$this->getOrCreatePistonArmTile($world)?->setSticky($this->isSticky());
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$powered = $this->isPoweredByNeighbors();

		if($powered === $this->powered){
			return;
		}

		$wasPowered = $this->powered;
		$this->powered = $powered;
		$world->setBlock($this->position, $this);
		$tile = $this->getOrCreatePistonArmTile($world);
		$tile?->setSticky($this->isSticky());

		if($powered){
			$extended = $this->tryExtend();
			$tile?->setExtended($extended);
		}elseif($wasPowered && $this->isSticky()){
			$tile?->setExtended(false);
			$this->retractHead();
			$this->tryPullOneBlockBack();
		}else{
			$tile?->setExtended(false);
			$this->retractHead();
		}
	}

	private function tryExtend() : bool{
		if(!$this->tryPushLine()){
			return false;
		}
		$this->placeHead();
		return true;
	}

	private function tryPushLine() : bool{
		$world = $this->position->getWorld();
		$pushFacing = $this->getPushFacing();
		$maxPush = $this->getMaxPushBlocks();

		$line = [];
		for($i = 1; $i <= $maxPush + 1; ++$i){
			$b = $this->getSide($pushFacing, $i);
			if($b->getTypeId() === BlockTypeIds::AIR){
				break;
			}
			if($i === $maxPush + 1){
				return false;
			}
			if(!$this->canMoveBlock($b)){
				return false;
			}
			$line[] = $b;
		}

		if($line === []){
			return true;
		}

		$after = $this->getSide($pushFacing, count($line) + 1);
		if(!$after->canBeReplaced()){
			return false;
		}

		for($i = count($line) - 1; $i >= 0; --$i){
			$from = $line[$i];
			$to = $from->getSide($pushFacing);
			if($this->shouldBreakOnPush($from)){
				$world->useBreakOn($from->getPosition());
				continue;
			}
			$this->moveBlockWithTile($from, $to);
		}
		$world->setBlock($line[0]->getPosition(), VanillaBlocks::AIR());
		return true;
	}

	private function placeHead() : void{
		$front = $this->getSide($this->getPushFacing());
		if(!$front->canBeReplaced()){
			return;
		}

		$head = ($this->isSticky() ? VanillaBlocks::STICKY_PISTON_HEAD() : VanillaBlocks::PISTON_HEAD())
			->setFacing($this->getPushFacing());
		$this->position->getWorld()->setBlock($front->getPosition(), $head);
	}

	private function retractHead() : void{
		$front = $this->getSide($this->getPushFacing());
		if(
			$front->getTypeId() === BlockTypeIds::PISTON_HEAD ||
			$front->getTypeId() === BlockTypeIds::STICKY_PISTON_HEAD ||
			$front->getTypeId() === BlockTypeIds::MOVING_PISTON
		){
			$this->position->getWorld()->setBlock($front->getPosition(), VanillaBlocks::AIR());
		}
	}

	private function tryPullOneBlockBack() : void{
		$pushFacing = $this->getPushFacing();
		$front = $this->getSide($pushFacing);
		$second = $front->getSide($pushFacing);

		if($front->getTypeId() !== BlockTypeIds::AIR){
			return;
		}
		if($second->getTypeId() === BlockTypeIds::AIR){
			return;
		}
		if(!$this->canMoveBlock($second)){
			return;
		}
		if(!$front->canBeReplaced()){
			return;
		}

		$world = $this->position->getWorld();
		if($this->shouldBreakOnPush($second)){
			$world->useBreakOn($second->getPosition());
			return;
		}
		$this->moveBlockWithTile($second, $front);
		$world->setBlock($second->getPosition(), VanillaBlocks::AIR());
	}

	protected function isSticky() : bool{
		return false;
	}

	protected function getMaxPushBlocks() : int{
		return 12;
	}

	private function getPushFacing() : int{
		return $this->facing === Facing::UP || $this->facing === Facing::DOWN
			? $this->facing
			: Facing::opposite($this->facing);
	}

	private function isPoweredByNeighbors() : bool{
		$pushFacing = $this->getPushFacing();
		foreach($this->getAllSides() as $side){
			if($side instanceof Redstone){
				if($side->getPosition()->equals($this->getSide($pushFacing)->getPosition())){
					continue;
				}
				return true;
			}
			if($side instanceof RedstoneWire && $side->getOutputSignalStrength() > 0){
				return true;
			}
			if($side instanceof Lever && $side->isActivated()){
				return true;
			}
			if($side instanceof Button && $side->isPressed()){
				return true;
			}
			if($side instanceof SimplePressurePlate && $side->isPressed()){
				return true;
			}
		}
		return false;
	}

	private function getOrCreatePistonArmTile(\pocketmine\world\World $world) : ?TilePistonArm{
		$tile = $world->getTile($this->position);
		if($tile instanceof TilePistonArm){
			return $tile;
		}
		$tile = new TilePistonArm($world, $this->position->asVector3());
		$world->addTile($tile);
		return $tile;
	}

	private function moveBlockWithTile(Block $from, Block $to) : void{
		$world = $this->position->getWorld();
		$fromPos = $from->getPosition();
		$toPos = $to->getPosition();
		$tile = $world->getTile($fromPos);
		$tileNbt = null;

		if($tile !== null){
			$tileNbt = $tile->saveNBT();
			$tileNbt->setInt("x", $toPos->getFloorX());
			$tileNbt->setInt("y", $toPos->getFloorY());
			$tileNbt->setInt("z", $toPos->getFloorZ());
		}

		$world->setBlock($toPos, $from);
		if($tileNbt !== null){
			if(($existing = $world->getTile($toPos)) !== null){
				$existing->close();
			}
			$tile->close();
			$newTile = TileFactory::getInstance()->createFromData($world, $tileNbt);
			if($newTile !== null){
				$world->addTile($newTile);
			}
		}
	}

	private function shouldBreakOnPush(Block $block) : bool{
		$typeId = $block->getTypeId();
		return $typeId === BlockTypeIds::SHULKER_BOX || $typeId === BlockTypeIds::DYED_SHULKER_BOX;
	}

	private function canMoveBlock(Block $block) : bool{
		return match($block->getTypeId()){
			BlockTypeIds::OBSIDIAN,
			BlockTypeIds::CRYING_OBSIDIAN,
			BlockTypeIds::GLOWING_OBSIDIAN => false,
			default => true,
		};
	}
}
