<?php

/*
 *
 * ____            _        _   __  __ _                  __  __ ____
 * | _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * | __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|  \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 */

declare(strict_types=1);

namespace pocketmine\event\entity;

use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\player\Player;

/**
 * Called when an entity takes damage from another entity.
 */
class EntityDamageByEntityEvent extends EntityDamageEvent{

	private int $damagerEntityId;

	/**
 * Whether this attack originally matched the vanilla critical-hit condition.
 * This is independent from MODIFIER_CRITICAL damage.
 */
private bool $criticalHit = false;

/**
 * Whether this attack originally had positive melee enchantment damage.
 * This is independent from MODIFIER_WEAPON_ENCHANTMENTS after plugins modify it.
 */
private bool $magicHit = false;

/**
 * If false, critical-hit particles won't be shown even if this hit was originally critical.
 */
private bool $criticalHitAnimationEnabled = true;

/**
 * If false, magic critical-hit particles won't be shown even if this hit originally had enchantment damage.
 */
private bool $magicHitAnimationEnabled = true;

	private static bool $defaultKnockBackDisplacementEnabled = false;

    private bool $knockBackDisplacementEnabled;

	/**
	 * 击退附魔每级额外增加的横向 KB。
	 *
	 * 0.5 = 击退 I 横向 +0.5，击退 II 横向 +1.0
	 */
	private const KNOCKBACK_ENCHANTMENT_EXTRA_HORIZONTAL_PER_LEVEL = 0.5;

	/**
	 * true = 击退附魔只增加横向 KB，不增加 Y KB。
	 * false = 击退附魔同时增加横向和纵向。
	 */
	private const KNOCKBACK_ENCHANTMENT_ONLY_HORIZONTAL = true;

	/**
	 * @param float[] $modifiers
	 */
	public function __construct(
	Entity $damager,
	Entity $entity,
	int $cause,
	float $damage,
	array $modifiers = [],
	private float $knockBack = Living::DEFAULT_KNOCKBACK_FORCE,
	private float $verticalKnockBackLimit = Living::DEFAULT_KNOCKBACK_VERTICAL_LIMIT,
	private ?float $verticalKnockBack = null,
	?bool $knockBackDisplacementEnabled = null
    ){
	$this->damagerEntityId = $damager->getId();
	$this->knockBackDisplacementEnabled = $knockBackDisplacementEnabled ?? self::$defaultKnockBackDisplacementEnabled;

	parent::__construct($entity, $cause, $damage, $modifiers);

	$this->addAttackerModifiers($damager);
    }

	protected function addAttackerModifiers(Entity $damager) : void{
		if($damager instanceof Living){ //TODO: move this to entity classes
			$effects = $damager->getEffects();

			if(($strength = $effects->get(VanillaEffects::STRENGTH())) !== null){
				$this->setModifier($this->getBaseDamage() * 0.3 * $strength->getEffectLevel(), self::MODIFIER_STRENGTH);
			}

			if(($weakness = $effects->get(VanillaEffects::WEAKNESS())) !== null && $this->getCause() === EntityDamageEvent::CAUSE_ENTITY_ATTACK){
				$this->setModifier(-($this->getBaseDamage() * 0.2 * $weakness->getEffectLevel()), self::MODIFIER_WEAKNESS);
			}
		}

		$this->addKnockBackEnchantmentModifier($damager);
	}

	private function addKnockBackEnchantmentModifier(Entity $damager) : void{
		if($this->getCause() !== EntityDamageEvent::CAUSE_ENTITY_ATTACK){
			return;
		}

		if(!$damager instanceof Player){
			return;
		}

		$level = $damager->getInventory()->getItemInHand()->getEnchantmentLevel(VanillaEnchantments::KNOCKBACK());
		if($level <= 0){
			return;
		}

		$extraHorizontal = $level * self::KNOCKBACK_ENCHANTMENT_EXTRA_HORIZONTAL_PER_LEVEL;

		// 先锁住原始 Y KB。
		// 因为 getVerticalKnockBack() 默认会 fallback 到 knockBack。
		// 如果先加 knockBack，再取 vertical，就会导致 Y 也被附魔放大。
		$baseVertical = $this->getVerticalKnockBack();

		// 击退附魔只加横向。
		$this->knockBack += $extraHorizontal;

		if(self::KNOCKBACK_ENCHANTMENT_ONLY_HORIZONTAL){
			$this->verticalKnockBack = $baseVertical;
		}else{
			$this->verticalKnockBack = $baseVertical + $extraHorizontal;

			if($this->verticalKnockBackLimit < $this->verticalKnockBack){
				$this->verticalKnockBackLimit = $this->verticalKnockBack;
			}
		}
	}

	/**
	 * Returns the attacking entity, or null if the attacker has been killed or closed.
	 */
	public function getDamager() : ?Entity{
		return $this->getEntity()->getWorld()->getServer()->getWorldManager()->findEntity($this->damagerEntityId);
	}

	/**
 * Returns whether this attack originally matched the vanilla critical-hit condition.
 *
 * This is separated from MODIFIER_CRITICAL, so plugins may remove critical damage
 * without removing critical particles.
 */
public function isCriticalHit() : bool{
	return $this->criticalHit;
}

/**
 * Sets whether this attack originally matched the vanilla critical-hit condition.
 *
 * Normally this is set by Player::attackEntity().
 */
public function setCriticalHit(bool $criticalHit) : void{
	$this->criticalHit = $criticalHit;
}

/**
 * Returns whether this attack originally had positive melee enchantment damage.
 *
 * This is separated from MODIFIER_WEAPON_ENCHANTMENTS, so plugins may remove magic damage
 * without removing magic particles.
 */
public function isMagicHit() : bool{
	return $this->magicHit;
}

/**
 * Sets whether this attack originally had positive melee enchantment damage.
 *
 * Normally this is set by Player::attackEntity().
 */
public function setMagicHit(bool $magicHit) : void{
	$this->magicHit = $magicHit;
}

/**
 * Returns whether critical-hit particles are enabled for this event.
 */
public function isCriticalHitAnimationEnabled() : bool{
	return $this->criticalHitAnimationEnabled;
}

/**
 * Enables or disables critical-hit particles for this event.
 */
public function setCriticalHitAnimationEnabled(bool $enabled) : void{
	$this->criticalHitAnimationEnabled = $enabled;
}

/**
 * Returns whether magic critical-hit particles are enabled for this event.
 */
public function isMagicHitAnimationEnabled() : bool{
	return $this->magicHitAnimationEnabled;
}

/**
 * Enables or disables magic critical-hit particles for this event.
 */
public function setMagicHitAnimationEnabled(bool $enabled) : void{
	$this->magicHitAnimationEnabled = $enabled;
}

/**
 * Returns whether Player::attackEntity() should show critical-hit particles.
 *
 * This intentionally does not depend on MODIFIER_CRITICAL.
 * If plugins clear critical damage, particles can still play.
 */
public function shouldPlayCriticalHitAnimation() : bool{
	return $this->criticalHitAnimationEnabled && $this->criticalHit;
}

/**
 * Returns whether Player::attackEntity() should show magic critical-hit particles.
 *
 * This intentionally does not depend on MODIFIER_WEAPON_ENCHANTMENTS.
 * If plugins clear enchantment damage, particles can still play.
 */
public function shouldPlayMagicHitAnimation() : bool{
	return $this->magicHitAnimationEnabled && $this->magicHit;
}

	public static function isDefaultKnockBackDisplacementEnabled() : bool{
	return self::$defaultKnockBackDisplacementEnabled;
    }

    public static function setDefaultKnockBackDisplacementEnabled(bool $enabled) : void{
	    self::$defaultKnockBackDisplacementEnabled = $enabled;
    }

    public function isKnockBackDisplacementEnabled() : bool{
	    return $this->knockBackDisplacementEnabled;
    }

    public function setKnockBackDisplacementEnabled(bool $enabled) : void{
	    $this->knockBackDisplacementEnabled = $enabled;
    }

	/**
	 * Returns the horizontal force with which the victim will be knocked back from the attacking entity.
	 *
	 * @see Living::DEFAULT_KNOCKBACK_FORCE
	 */
	public function getKnockBack() : float{
		return $this->knockBack;
	}

	/**
	 * Sets the horizontal force with which the victim will be knocked back from the attacking entity.
	 * Larger values will knock the victim back further.
	 * Negative values will pull the victim towards the attacker.
	 */
	public function setKnockBack(float $knockBack) : void{
		$this->knockBack = $knockBack;
	}

	/**
	 * Returns the independent vertical knockback force.
	 *
	 * If not explicitly set, it falls back to horizontal knockback for old API compatibility.
	 */
	public function getVerticalKnockBack() : float{
		return $this->verticalKnockBack ?? $this->knockBack;
	}

	/**
	 * Sets the independent vertical knockback force.
	 */
	public function setVerticalKnockBack(float $verticalKnockBack) : void{
		$this->verticalKnockBack = $verticalKnockBack;
	}

	/**
	 * Returns the maximum upwards velocity the victim may have after being knocked back.
	 * This ensures that the victim doesn't fly up into the sky when high levels of knockback are applied.
	 *
	 * @see Living::DEFAULT_KNOCKBACK_VERTICAL_LIMIT
	 */
	public function getVerticalKnockBackLimit() : float{
		return $this->verticalKnockBackLimit;
	}

	/**
	 * Sets the maximum upwards velocity the victim may have after being knocked back.
	 * Larger values will allow the victim to fly higher if the vertical knockback force is also large.
	 */
	public function setVerticalKnockBackLimit(float $verticalKnockBackLimit) : void{
		$this->verticalKnockBackLimit = $verticalKnockBackLimit;
	}
}
