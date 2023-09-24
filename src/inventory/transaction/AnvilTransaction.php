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

namespace pocketmine\inventory\transaction;

use pocketmine\block\Anvil;
use pocketmine\block\Block;
use pocketmine\item\Durable;
use pocketmine\item\EnchantedBook;
use pocketmine\item\enchantment\AvailableEnchantmentRegistry;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\EnchantmentTransfer;
use pocketmine\item\enchantment\IncompatibleEnchantMap;
use pocketmine\item\Item;
use pocketmine\item\utils\ItemRepairUtils;
use pocketmine\player\Player;
use pocketmine\utils\Limits;
use pocketmine\world\sound\AnvilUseSound;
use function count;
use function floor;
use function log;
use function max;
use function min;

class AnvilTransaction extends InventoryTransaction{
	private const TAG_REPAIR_COST = "RepairCost";

	private const MAX_COST = 39;
	private const HARD_CAP = Limits::INT32_MAX;

	private Item $result;
	private int $xpCost = 0;
	/** @var Item[] */
	private array $consumed = [];

	private static function repairCostToUses(int $repairCost) : int{
		return (int) (log($repairCost + 1) / log(2));
	}

	private static function usesToRepairCost(int $uses) : int{
		return (2 ** $uses) - 1;
	}

	public function __construct(
		Player $source,
		protected Block $holder,
		protected Item $input,
		protected Item $material,
		protected ?string $rename,
		array $actions = []
	){
		$this->consumed[] = clone $input;
		parent::__construct($source, $actions);
		$this->calculateResult();
	}

	private function calculateResult() : void{
		$currentRepairCost = $this->input->getNamedTag()->getInt(self::TAG_REPAIR_COST, 0);
		$currentRepairCost += $this->material->getNamedTag()->getInt(self::TAG_REPAIR_COST, 0);
		$this->xpCost += $currentRepairCost;

		$addRepairCost = false;

		$this->result = clone $this->input;
		if($this->rename !== null && $this->rename !== $this->input->getCustomName()){
			// rename costs 1 additional XP but doesn't incur cumulative repair costs aside from the base cost
			$this->result->setCustomName($this->rename);
			$this->xpCost += 1;
		}
		if(!$this->material->isNull()){
			$applicableEnchants = self::getApplicableEnchants($this->result, $this->material);
			$sacrificeConsumed = false;

			if($this->result instanceof Durable && $this->result->getDamage() > 0){
				// result is a clone of input,
				// therefore if input is instance of Durable, result must also be Durable
				$damage = $this->result->getDamage();
				if(ItemRepairUtils::isRepairableWith($this->result, $this->material)){
					$currentRepairCost += $this->material->getNamedTag()->getInt(self::TAG_REPAIR_COST, 0);

					$consumedCount = 0;
					for($i = 0; $i < $this->material->getCount() && $damage > 0; $i++){
						$damage -= (int) floor($this->result->getMaxDurability() / 4);
						$consumedCount += 1;
						$this->xpCost += 1;
						$addRepairCost = true;
					}
					$this->consumed[] = $this->material->pop($consumedCount);
					$sacrificeConsumed = true;
				}elseif($this->material instanceof Durable && $this->result->equals($this->material, false, false)){
					// merging the durability values of two items of the same type
					$damage -= $this->material->getMaxDurability() - $this->material->getDamage();
					$damage -= (int) floor($this->material->getMaxDurability() * 0.12) - 1;
					$this->consumed[] = $this->material->pop();
					$sacrificeConsumed = true;
					$this->xpCost += 2;
					$addRepairCost = true;
				}

				$this->result->setDamage(max(0, $damage));
			}

			if(count($applicableEnchants) > 0){
				if(!$sacrificeConsumed){
					$this->consumed[] = $this->material->pop();
				}
				$addRepairCost = true;

				foreach($applicableEnchants as $enchant){
					// we calculate the cost needed to "upgrade" the target item to the new enchant, considering the added level
					$this->xpCost += EnchantmentTransfer::getCost(
						$type = $enchant->getType(),
						max($enchant->getLevel() - $this->result->getEnchantmentLevel($type), 0),
						!$this->material instanceof EnchantedBook
					);

					$this->result->addEnchantment(clone $enchant);
				}
			}
		}

		if($addRepairCost){
			$currentRepairCost = self::usesToRepairCost(self::repairCostToUses($currentRepairCost) + 1);
		}
		$this->result->getNamedTag()->setInt(self::TAG_REPAIR_COST, $currentRepairCost);
	}

	public function getResult() : Item{
		return $this->result;
	}

	public function getXPCost(bool $checkCreative = true) : int{
		if($checkCreative && $this->source->isCreative() && $this->xpCost < self::HARD_CAP){
			return 0;
		}
		return $this->xpCost;
	}

	/**
	 * @return Item[]
	 */
	public function getConsumedItems() : array{
		return $this->consumed;
	}

	public function validate() : void{
		$this->squashDuplicateSlotChanges();
		if(count($this->actions) < 1){
			throw new TransactionValidationException("Transaction must have at least one action to be executable");
		}

		/** @var Item[] $createdItems */
		$createdItems = [];
		/** @var Item[] $deletedItems */
		$deletedItems = [];
		$this->matchItems($createdItems, $deletedItems);

		if(count($createdItems) === 0){
			throw new TransactionValidationException("Transaction attempted to execute but did not result to anything");
		}
		if(count($createdItems) > 1){
			throw new TransactionValidationException("Transaction resulted into more than 1 item stack");
		}
		if(!$this->input->equals($createdItems[0], false, false)){
			throw new TransactionValidationException("Transaction produced a different output item");
		}
		if(count($deletedItems) > count($this->consumed)){
			throw new TransactionValidationException("Transaction consumed more than required items");
		}
		$cost = $this->getXPCost();
		if($cost > self::MAX_COST || $cost > $this->source->getXpManager()->getXpLevel()){
			throw new TransactionValidationException("Too expensive");
		}
	}

	public function execute() : void{
		try{
			parent::execute();
		}catch(TransactionException $e){
			$networkSession = $this->source->getNetworkSession();
			$networkSession->getEntityEventBroadcaster()->syncAttributes([$networkSession], $this->source, $this->source->getAttributeMap()->getAll());
			throw $e;
		}
		$this->source->getXpManager()->subtractXpLevels($this->getXPCost());
		$this->holder->getPosition()->getWorld()->addSound($this->holder->getPosition(), new AnvilUseSound());
		if($this->holder instanceof Anvil && !$this->source->isCreative()){
			$this->holder->attemptDamage();
		}
	}

	/**
	 * @return EnchantmentInstance[]
	 */
	public static function getApplicableEnchants(Item $target, Item $source) : array{
		$applicableEnchants = [];
		$availableEnchantment = AvailableEnchantmentRegistry::getInstance();

		foreach($source->getEnchantments() as $enchantment){
			$enchantmentType = $enchantment->getType();
			if(
				!$availableEnchantment->isAvailableForItem($enchantmentType, $target) &&
				!$target instanceof EnchantedBook // enchanted books let in any compatible
			){
				continue;
			}

			foreach($target->getEnchantments() as $existing){
				if(IncompatibleEnchantMap::isIncompatible($existing->getType(), $enchantmentType)){
					continue 2;
				}
			}

			$level = $target->getEnchantment($enchantmentType)?->getLevel() ?? 0;
			if($level > 0){
				if($level < $enchantment->getLevel()){
					// if target has a lower enchantment level, upgrade it to sacrifice's enchantment level
					$enchantment = new EnchantmentInstance($enchantmentType, $enchantment->getLevel());
				}elseif($level === $enchantment->getLevel()){
					// if target has a matching enchantment level, add 1 to the resulting level capped at max
					$enchantment = new EnchantmentInstance($enchantmentType, min($level + 1, $enchantmentType->getMaxLevel()));
					if($level === $enchantment->getLevel()){
						continue; // nothing changed, don't bother
					}
				}
			}

			$applicableEnchants[] = $enchantment;
		}
		return $applicableEnchants;
	}
}
