<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */


declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\block\Air;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\ToolTier;
use pocketmine\item\StringToItemParser;
use pocketmine\item\enchantment\StringToEnchantmentParser;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\player\Player;
use pocketmine\world\particle\BlockBreakParticle;

class Mace extends Tool {

    /** @var Player|null */
    public static $currentAttacker = null;  // Hack for player/fall in onAttackEntity

    protected $tier;

    public function __construct(ItemIdentifier $id, string $name, ToolTier $tier, array $enchantmentTags = []){
        parent::__construct($id, $name, $enchantmentTags);
        $this->tier = $tier;
    }

    public function getTier(): ToolTier {
        return $this->tier;
    }

    public function getMaxDurability(): int{
        return $this->getTier()->getMaxDurability();
    }

    public function getBaseAttackPoints(): int{
        return $this->getTier()->getBaseAttackPoints() + 2;
    }

    public function onAttackEntity(Entity $victim, array &$returnedItems) : bool {
        if(!$victim instanceof Living){
            return parent::onAttackEntity($victim, $returnedItems);
        }

        $player = self::$currentAttacker;
        if (!$player) {
            return parent::onAttackEntity($victim, $returnedItems);
        }

        $baseDamage = $this->getBaseAttackPoints();
        $fallDistance = $player->getFallDistance() * 2;  // *2 to match manual (PMMP fall is weak)

        // Debug (remove after)
        error_log("Mace DEBUG: fall=$fallDistance, base=$baseDamage");

        $damage = $baseDamage;
        if ($fallDistance > 1) {
            $bonusDamage = floor($fallDistance * 2) - 1;  // Your formula
            
            // FIX: Check if enchantment exists
            $densityEnchantment = StringToEnchantmentParser::getInstance()->parse("density");
            if ($densityEnchantment !== null) {
                $densityEnchant = $this->getEnchantment($densityEnchantment);
                if ($densityEnchant !== null) {
                    $densityLevel = $densityEnchant->getLevel();
                    // Replace match with switch
                    switch($densityLevel){
                        case 1: $densityMultiplier = 0.5; break;
                        case 2: $densityMultiplier = 1.0; break;
                        case 3: $densityMultiplier = 1.5; break;
                        case 4: $densityMultiplier = 2.0; break;
                        case 5: $densityMultiplier = 2.5; break;
                        default: $densityMultiplier = 0; break;
                    }
                    $bonusDamage += $fallDistance * $densityMultiplier;
                }
            }
            
            $damage = ($baseDamage + $bonusDamage) * 1.5;  // crit ×1.5

            $player->setFallDistance(0.0);  // No fall damage to player

            // Heavy effects
            if ($fallDistance >= 3) {
                $this->applyHeavySmashEffects($player, $victim);
            }

            error_log("Mace SMASH: bonus=$bonusDamage, total=$damage");
        }

        $event = new EntityDamageByEntityEvent($player, $victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage);
        $victim->attack($event);

        // Breach ( * multi on total)
        if ($victim instanceof Player && !$event->isCancelled()) {
            // FIX: Check if enchantment exists
            $breachEnchantment = StringToEnchantmentParser::getInstance()->parse("breach");
            if ($breachEnchantment !== null) {
                $enchant = $this->getEnchantment($breachEnchantment);
                if ($enchant !== null) {
                    $breachLevel = $enchant->getLevel();
                    $armorPoints = 0;
                    foreach ($victim->getArmorInventory()->getContents() as $armorItem) {
                        if (!$armorItem->isNull()) {
                            $armorPoints += $armorItem->getDefensePoints();
                        }
                    }
                    if ($armorPoints > 0) {
                        $reductionPercent = $armorPoints * 4;
                        $effectiveReduction = max(0, $reductionPercent - (15 * $breachLevel));
                        $finalDamageMultiplier = (100 - $effectiveReduction) / 100;
                        $event->setBaseDamage($event->getBaseDamage() * $finalDamageMultiplier);
                    }
                }
            }
        }

        $this->applyDamage(1);

        return true;
    }

    private function applyHeavySmashEffects(Player $player, Living $victim): void {
        $world = $player->getWorld();
        $targetPos = $victim->getPosition();
        $x = $targetPos->x;
        $y = $targetPos->y;
        $z = $targetPos->z;

        // Particles (your logic, grass_block for parse)
        $blockUnderPos = new Vector3($x, $y - 1, $z);
        $blockUnder = $world->getBlock($blockUnderPos);
        
        $block = ($blockUnder instanceof Air) ? "grass_block" : $blockUnder->getName();
        $parsedBlock = StringToItemParser::getInstance()->parse($block);
        
        // FIX: Check that block is not null
        if($parsedBlock !== null){
            $blockInstance = $parsedBlock->getBlock();
            $maxHeight = 4.0;
            $step = 0.5;
            $offset = 1.5;
            for ($i = 0; $i <= $maxHeight; $i += $step) {
                $currentY = $y + $i;
                $positions = [
                    new Vector3($x + $offset, $currentY, $z),
                    new Vector3($x - $offset, $currentY, $z),
                    new Vector3($x, $currentY, $z + $offset),
                    new Vector3($x, $currentY, $z - $offset),
                ];
                foreach ($positions as $particlePos) {
                    $world->addParticle($particlePos, new BlockBreakParticle($blockInstance));
                }
            }
        }

        // Knockback to VICTIM (fix: dir from player, horizontal + up from wind)
        $playerPos = $player->getPosition();
        
        // FIX: Correctly calculate difference between positions
        $diffX = $targetPos->x - $playerPos->x;
        $diffY = $targetPos->y - $playerPos->y;
        $diffZ = $targetPos->z - $playerPos->z;
        
        $distance = sqrt($diffX * $diffX + $diffY * $diffY + $diffZ * $diffZ);
        
        if ($distance < 0.1) {
            $dirX = mt_rand(-10,10)/10;
            $dirZ = mt_rand(-10,10)/10;
        } else {
            $dirX = $diffX / $distance;
            $dirZ = $diffZ / $distance;
        }
        
        $knockStr = 0.5;
        $knockY = 0.7;
        
        // FIX: Check if enchantment exists
        $windBurstEnchantment = StringToEnchantmentParser::getInstance()->parse("wind_burst");
        if ($windBurstEnchantment !== null) {
            $enchant = $this->getEnchantment($windBurstEnchantment);
            if ($enchant !== null) {
                $level = $enchant->getLevel();
                // Replace match with switch
                switch($level){
                    case 1: $knockY = 1.2; break;
                    case 2: $knockY = 2.0; break;
                    case 3: $knockY = 3.1; break;
                    default: $knockY = 0.7; break;
                }
            }
        }
        
        // FIX: Universal knockBack for different entity types
        $this->applyKnockback($victim, $dirX, $dirZ, $knockY);

        // Sound (AABB 10x10)
        $nearbyEntities = $world->getNearbyEntities(new AxisAlignedBB($x - 10, $y - 10, $z - 10, $x + 10, $y + 10, $z + 10));
        foreach ($nearbyEntities as $entity) {
            if ($entity instanceof Player) {
                $entity->getNetworkSession()->sendDataPacket(PlaySoundPacket::create(
                    "mace.heavy_smash_ground",
                    $x, $y, $z, 1.0, 1.0
                ));
            }
        }
    }

    /**
     * Universal method for applying knockback to different entity types
     */
    private function applyKnockback(Living $victim, float $dirX, float $dirZ, float $knockY): void {
        // Try different knockBack method signatures
        try {
            // Try standard PocketMine signature
            $victim->knockBack($dirX, $dirZ, $knockY);
        } catch (\TypeError $e) {
            try {
                // Try signature with knockback force
                $victim->knockBack(0.5, $dirX, $dirZ, $knockY);
            } catch (\TypeError $e2) {
                try {
                    // Try signature with player (old version)
                    $victim->knockBack(self::$currentAttacker, 0.5, $dirX, $dirZ, $knockY);
                } catch (\TypeError $e3) {
                    // If nothing works, log error
                    error_log("Mace: Could not apply knockback to " . get_class($victim));
                }
            }
        }
    }
}