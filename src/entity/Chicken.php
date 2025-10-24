<?php

/*
 *  ____            _        _   __  __ _                  __  __ ____  
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \ 
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/ 
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_| 
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 */

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\world\particle\HeartParticle;

class Chicken extends Living{

	public static function getNetworkTypeId() : string{ return EntityIds::CHICKEN; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.7, 0.4);
	}

	public function getName() : string{
		return "Chicken";
	}

	private int $inLove = 0;
	private int $breedingCooldown = 0;
	private int $timeUntilNextEgg = 0;
	private bool $isChickenJockey = false;

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->setMaxHealth(4);
		$this->setHealth(4);
		
		$this->isChickenJockey = $nbt->getByte("IsChickenJockey", 0) === 1;
		$this->timeUntilNextEgg = mt_rand(6000, 12000);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		
		if(!$this->isAlive()){
			return $hasUpdate;
		}

		// Slow fall like chickens
		if(!$this->isOnGround() && $this->motion->y < 0){
			$this->motion->y *= 0.6;
		}

		// Lay eggs (only adult chickens that are not jockeys)
		if($this->getScale() === 1.0 && !$this->isChickenJockey && $this->timeUntilNextEgg-- <= 0){
			$this->getWorld()->dropItem($this->getPosition(), VanillaItems::EGG());
			$this->timeUntilNextEgg = mt_rand(6000, 12000);
		}

		// Breeding cooldown
		if($this->breedingCooldown > 0){
			$this->breedingCooldown -= $tickDiff;
		}

		// Love mode
		if($this->inLove > 0){
			$this->inLove -= $tickDiff;
			
			// Love particles
			if(mt_rand(1, 5) === 1){
				$this->getWorld()->addParticle($this->getPosition()->add(0, 0.8, 0), new HeartParticle());
			}

			// Try to find partner
			if($this->inLove > 0){
				$this->findPartner();
			}else{
				$this->inLove = 0;
			}
		}

		// Follow players with seeds
		$this->followPlayersWithSeeds();

		// Random movement
		if($this->isOnGround() && !$this->isUnderwater() && $this->inLove <= 0){
			if(mt_rand(1, 100) === 1){
				$this->setRotation(mt_rand(0, 359), 0);
			}
			
			if(mt_rand(1, 50) === 1){
				$this->moveForward();
			}
			
			if(mt_rand(1, 200) === 1){
				$this->doChickenJump();
			}
		}

		// Chicken sounds
		if(mt_rand(1, 600) === 1){
			$this->getWorld()->addParticle($this->getPosition(), new HeartParticle());
		}
		
		return $hasUpdate;
	}

	private function followPlayersWithSeeds() : void{
		$players = $this->getWorld()->getPlayers();
		foreach($players as $player){
			if($player->getPosition()->distance($this->getPosition()) < 8){
				$item = $player->getInventory()->getItemInHand();
				if($item->getTypeId() === VanillaItems::WHEAT_SEEDS()->getTypeId()){
					// Move towards player with seeds
					$direction = $player->getPosition()->subtractVector($this->getPosition())->normalize();
					$direction->y = 0;
					$this->setMotion($direction->multiply(0.25));
					
					// Look at player
					$dx = $player->getPosition()->x - $this->getPosition()->x;
					$dz = $player->getPosition()->z - $this->getPosition()->z;
					$yaw = atan2($dz, $dx) * 180 / M_PI - 90;
					$this->setRotation($yaw, 0);
					break;
				}
			}
		}
	}

	private function moveForward() : void{
		$yaw = $this->getLocation()->yaw * M_PI / 180;
		$speed = 0.1;
		
		$motionX = -sin($yaw) * $speed;
		$motionZ = cos($yaw) * $speed;
		
		$this->setMotion(new Vector3($motionX, $this->motion->y, $motionZ));
	}

	private function doChickenJump() : void{
		if($this->isOnGround()){
			$this->setMotion($this->getMotion()->add(0, 0.42, 0));
		}
	}

	private function findPartner() : void{
		if($this->breedingCooldown > 0) return;

		$nearbyEntities = $this->getWorld()->getNearbyEntities($this->getBoundingBox()->expandedCopy(6, 3, 6));
		
		foreach($nearbyEntities as $entity){
			if($entity instanceof Chicken && $entity !== $this && $entity->inLove > 0 && $entity->breedingCooldown <= 0){
				// Move towards partner
				$direction = $entity->getPosition()->subtractVector($this->getPosition())->normalize();
				$this->setMotion($direction->multiply(0.2));
				
				// Breed if close enough
				if($this->getPosition()->distance($entity->getPosition()) < 1.5){
					$this->breedWith($entity);
					break;
				}
			}
		}
	}

	private function breedWith(Chicken $partner) : void{
		// Set breeding cooldown (5 minutes)
		$this->breedingCooldown = 6000;
		$partner->breedingCooldown = 6000;
		
		// Reset love mode
		$this->inLove = 0;
		$partner->inLove = 0;
		
		// Remove one chicken after breeding
		if(mt_rand(1, 2) === 1){
			$this->flagForDespawn();
		}else{
			$partner->flagForDespawn();
		}
		
		// Breeding particles
		for($i = 0; $i < 15; $i++){
			$this->getWorld()->addParticle($this->getPosition()->add(0, 1, 0), new HeartParticle());
			if($partner->isAlive()){
				$partner->getWorld()->addParticle($partner->getPosition()->add(0, 1, 0), new HeartParticle());
			}
		}
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		$item = $player->getInventory()->getItemInHand();
		
		if($item->getTypeId() === VanillaItems::WHEAT_SEEDS()->getTypeId()){
			
			if($this->breedingCooldown <= 0){
				// Start love mode (30 seconds)
				$this->inLove = 600;
				
				// Love particles
				for($i = 0; $i < 8; $i++){
					$this->getWorld()->addParticle($this->getPosition()->add(0, 0.8, 0), new HeartParticle());
				}
				
				// Consume seeds
				if(!$player->isCreative()){
					$item->pop();
					$player->getInventory()->setItemInHand($item);
				}
				
				return true;
			}
		}
		
		return parent::onInteract($player, $clickPos);
	}

	public function getDrops() : array{
		$drops = [];
		
		$featherCount = mt_rand(0, 2);
		if($featherCount > 0){
			$drops[] = VanillaItems::FEATHER()->setCount($featherCount);
		}
		
		if(mt_rand(1, 3) === 1){
			$drops[] = VanillaItems::RAW_CHICKEN()->setCount(1);
		}
		
		$cause = $this->getLastDamageCause();
		if($cause !== null && ($cause->getCause() === EntityDamageEvent::CAUSE_FIRE || $cause->getCause() === EntityDamageEvent::CAUSE_FIRE_TICK)){
			$drops[] = VanillaItems::COOKED_CHICKEN()->setCount(1);
		}
		
		return $drops;
	}

	public function getXpDropAmount() : int{
		return mt_rand(1, 3);
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::CHICKEN_SPAWN_EGG();
	}

	public function knockBack(float $x, float $z, float $force = 0.4, ?float $verticalLimit = 0.4) : void{
		parent::knockBack($x, $z, $force * 1.3, $verticalLimit);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte("IsChickenJockey", $this->isChickenJockey ? 1 : 0);
		return $nbt;
	}

	public function fall(float $fallDistance) : void{
		// Chickens don't take fall damage
	}
}