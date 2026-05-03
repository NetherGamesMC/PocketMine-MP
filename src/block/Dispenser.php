<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\tile\Dispenser as TileDispenser;
use pocketmine\block\tile\Dropper as TileDropper;
use pocketmine\block\tile\Container as TileContainer;
use pocketmine\block\utils\AnyFacing;
use pocketmine\block\utils\AnyFacingTrait;
use pocketmine\block\utils\PoweredByRedstone;
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Arrow as ArrowEntity;
use pocketmine\entity\projectile\Egg as EggEntity;
use pocketmine\entity\projectile\Snowball as SnowballEntity;
use pocketmine\item\Arrow;
use pocketmine\item\Egg;
use pocketmine\item\Item;
use pocketmine\item\Snowball;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\World;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\LaunchSound;
use function abs;

class Dispenser extends Opaque implements AnyFacing, PoweredByRedstone{
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
					$this->facing = Facing::opposite($player->getHorizontalFacing());
				}
			}else{
				$this->facing = Facing::opposite($player->getHorizontalFacing());
			}
		}

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player !== null){
			$world = $this->position->getWorld();
			$tile = $this->getOrCreateExpectedTile($world);
			if($tile instanceof TileContainer){
				$player->setCurrentWindow($tile->getInventory());
			}
		}
		return true;
	}

	public function onPostPlace() : void{
		$this->getOrCreateExpectedTile($this->position->getWorld());
	}

	public function onNearbyBlockChange() : void{
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 1);
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$powered = $this->isPoweredByNeighbors();
		$wasPowered = $this->powered;
		if($wasPowered !== $powered){
			$this->powered = $powered;
			$world->setBlock($this->position, $this);
		}

		// Fire once on rising edge (button press / redstone power on)
		if(!$wasPowered && $powered){
			$this->dispenseOneItem($world);
		}
	}

	private function getOrCreateExpectedTile(World $world) : ?TileContainer{
		$tile = $world->getTile($this->position);
		if($this instanceof Dropper){
			if($tile instanceof TileDropper){
				return $tile;
			}
		}elseif($tile instanceof TileDispenser){
			return $tile;
		}

		$tile = $this instanceof Dropper ?
			new TileDropper($world, $this->position->asVector3()) :
			new TileDispenser($world, $this->position->asVector3());
		$world->addTile($tile);
		return $tile;
	}

	private function isPoweredByNeighbors() : bool{
		foreach(Facing::ALL as $face){
			$side = $this->getSide($face);
			if($this->readPowerFromNeighbor($side, $face) > 0){
				return true;
			}
		}
		return false;
	}

	private function readPowerFromNeighbor(Block $block, int $faceFromSelf) : int{
		if($block instanceof Redstone){
			return 15;
		}
		if($block instanceof Lever && $block->isActivated()){
			return 15;
		}
		if($block instanceof Button && $block->isPressed()){
			return 15;
		}
		if($block instanceof SimplePressurePlate && $block->isPressed()){
			return 15;
		}
		if($block instanceof RedstoneWire){
			return $block->getOutputSignalStrength();
		}
		if($block instanceof RedstoneRepeater){
			$outputFace = Facing::opposite($block->getFacing());
			return $block->isPowered() && $outputFace === Facing::opposite($faceFromSelf) ? 15 : 0;
		}
		if($block instanceof RedstoneComparator){
			$outputFace = Facing::opposite($block->getFacing());
			return $outputFace === Facing::opposite($faceFromSelf) ? $block->getOutputSignalStrength() : 0;
		}
		if($block instanceof \pocketmine\block\utils\AnalogRedstoneSignalEmitter){
			return $block->getOutputSignalStrength();
		}
		if($block instanceof PoweredByRedstone && $block->isPowered()){
			return 15;
		}
		return 0;
	}

	protected function dispenseOneItem(World $world) : void{
		$tile = $this->getOrCreateExpectedTile($world);
		if($tile === null){
			return;
		}

		$inventory = $tile->getInventory();
		for($slot = 0, $size = $inventory->getSize(); $slot < $size; ++$slot){
			$item = $inventory->getItem($slot);
			if($item->isNull()){
				continue;
			}

			$one = clone $item;
			$one->setCount(1);
			$item->setCount($item->getCount() - 1);
			$inventory->setItem($slot, $item->getCount() > 0 ? $item : $item->setCount(0));

			[$dx, $dy, $dz] = Facing::OFFSET[$this->facing];
			$spawnPos = $this->position->add(0.5 + $dx * 0.7, 0.5 + $dy * 0.7, 0.5 + $dz * 0.7); // from dispenser mouth
			$direction = new Vector3((float) $dx, (float) $dy, (float) $dz);

			if($this->shouldLaunchProjectile($one) && ($one instanceof Arrow || $one instanceof Egg || $one instanceof Snowball)){
				$loc = Location::fromObject($spawnPos, $world);
				$projectile = match(true){
					$one instanceof Arrow => new ArrowEntity($loc, null, false),
					$one instanceof Egg => new EggEntity($loc, null),
					default => new SnowballEntity($loc, null)
				};
				$projectile->setMotion($direction->multiply(1.2));
				$projectile->spawnToAll();
				$world->addSound($spawnPos, new LaunchSound());
			}else{
				$motion = new Vector3($dx * 0.3, 0.2 + ($dy * 0.1), $dz * 0.3);
				$world->dropItem($spawnPos, $one, $motion);
			}
			return;
		}
	}

	protected function shouldLaunchProjectile(Item $item) : bool{
		return true;
	}
}
