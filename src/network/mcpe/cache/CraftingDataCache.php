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

namespace pocketmine\network\mcpe\cache;

use pmmp\encoding\BE;
use pocketmine\crafting\CraftingManager;
use pocketmine\crafting\FurnaceType;
use pocketmine\crafting\MetaWildcardRecipeIngredient;
use pocketmine\crafting\ShapedRecipe;
use pocketmine\crafting\ShapelessRecipe;
use pocketmine\crafting\ShapelessRecipeType;
use pocketmine\crafting\TagWildcardRecipeIngredient;
use pocketmine\data\bedrock\item\ItemTypeSerializeException;
use pocketmine\data\bedrock\ItemTagDowngrader;
use pocketmine\data\bedrock\ItemTagToIdMap;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\recipe\CraftingRecipeBlockName;
use pocketmine\network\mcpe\protocol\types\recipe\FurnaceRecipe as ProtocolFurnaceRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\FurnaceRecipeBlockName;
use pocketmine\network\mcpe\protocol\types\recipe\IntIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\PotionContainerChangeRecipe as ProtocolPotionContainerChangeRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\PotionTypeRecipe as ProtocolPotionTypeRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeUnlockingRequirement;
use pocketmine\network\mcpe\protocol\types\recipe\ShapedRecipe as ProtocolShapedRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\ShapelessRecipe as ProtocolShapelessRecipe;
use pocketmine\timings\Timings;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\ProtocolSingletonTrait;
use Ramsey\Uuid\Uuid;
use function array_map;
use function spl_object_id;

final class CraftingDataCache{
	use ProtocolSingletonTrait;

	/**
	 * @var CraftingDataPacket[]
	 * @phpstan-var array<int, CraftingDataPacket>
	 */
	private array $caches = [];

	/**
	 * The client doesn't like recipes with ID 0 (as of 1.21.100) and complains about them in the content log
	 * This doesn't actually affect the function of the recipe, but it is annoying, so this offset fixes it
	 */
	public const RECIPE_ID_OFFSET = 1;

	public function getCache(CraftingManager $manager) : CraftingDataPacket{
		$id = spl_object_id($manager);
		if(!isset($this->caches[$id])){
			$manager->getDestructorCallbacks()->add(function() use ($id) : void{
				unset($this->caches[$id]);
			});
			$manager->getRecipeRegisteredCallbacks()->add(function() use ($id) : void{
				unset($this->caches[$id]);
			});
			$this->caches[$id] = $this->buildCraftingDataCache($manager);
		}
		return $this->caches[$id];
	}

	/**
	 * Rebuilds the cached CraftingDataPacket.
	 */
	private function buildCraftingDataCache(CraftingManager $manager) : CraftingDataPacket{
		Timings::$craftingDataCacheRebuild->startTiming();

		$nullUUID = Uuid::fromString(Uuid::NIL);
		$converter = TypeConverter::getInstance($this->protocolId);
		$itemTagDowngrader = ItemTagDowngrader::getInstance($this->protocolId);
		$recipesWithTypeIds = [];

		$noUnlockingRequirement = new RecipeUnlockingRequirement(null);
		$recipeNetId = self::RECIPE_ID_OFFSET;
		foreach($manager->getCraftingRecipeIndex() as $index => $recipe){
			//the client doesn't like recipes with an ID of 0, so we need to offset them
			$recipeNetId = $index + self::RECIPE_ID_OFFSET;
			if($recipe instanceof ShapelessRecipe){
				$typeTag = match($recipe->getType()){
					ShapelessRecipeType::CRAFTING => CraftingRecipeBlockName::CRAFTING_TABLE,
					ShapelessRecipeType::STONECUTTER => CraftingRecipeBlockName::STONECUTTER,
					ShapelessRecipeType::CARTOGRAPHY => CraftingRecipeBlockName::CARTOGRAPHY_TABLE,
					ShapelessRecipeType::SMITHING => CraftingRecipeBlockName::SMITHING_TABLE,
				};
				foreach($itemTagDowngrader->downgradeShapelessRecipe($recipe) as $r){
					try{
						$recipesWithTypeIds[] = new ProtocolShapelessRecipe(
							CraftingDataPacket::ENTRY_SHAPELESS,
							BE::packUnsignedInt($recipeNetId), //TODO: this should probably be changed to something human-readable
							array_map($converter->coreRecipeIngredientToNet(...), $r->getIngredientList()),
							array_map($converter->coreItemStackToNet(...), $r->getResults()),
							$nullUUID,
							$typeTag,
							50,
							$noUnlockingRequirement,
							$recipeNetId
						);
					}catch(\InvalidArgumentException|ItemTypeSerializeException) {
						continue;
					}
				}
			}elseif($recipe instanceof ShapedRecipe){
				foreach($itemTagDowngrader->downgradeShapedRecipe($recipe) as $r){
					try{
						$inputs = [];
						for($row = 0, $height = $r->getHeight(); $row < $height; ++$row){
							for($column = 0, $width = $r->getWidth(); $column < $width; ++$column){
								$inputs[$row][$column] = $converter->coreRecipeIngredientToNet($r->getIngredient($column, $row));
							}
						}
						$recipesWithTypeIds[] = new ProtocolShapedRecipe(
							CraftingDataPacket::ENTRY_SHAPED,
							BE::packUnsignedInt($recipeNetId), //TODO: this should probably be changed to something human-readable
							$inputs,
							array_map($converter->coreItemStackToNet(...), $r->getResults()),
							$nullUUID,
							CraftingRecipeBlockName::CRAFTING_TABLE,
							50,
							true,
							$noUnlockingRequirement,
							$recipeNetId,
						);
					}catch(\InvalidArgumentException|ItemTypeSerializeException) {
						continue;
					}
				}
			}else{
				//TODO: probably special recipe types
			}
		}

		foreach(FurnaceType::cases() as $furnaceType){
			$typeTag = match($furnaceType){
				FurnaceType::FURNACE => FurnaceRecipeBlockName::FURNACE,
				FurnaceType::BLAST_FURNACE => FurnaceRecipeBlockName::BLAST_FURNACE,
				FurnaceType::SMOKER => FurnaceRecipeBlockName::SMOKER,
				FurnaceType::CAMPFIRE => FurnaceRecipeBlockName::CAMPFIRE,
				FurnaceType::SOUL_CAMPFIRE => FurnaceRecipeBlockName::SOUL_CAMPFIRE
			};
			if($this->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_20){
				$recipeNetId++;
			}
			foreach($manager->getFurnaceRecipeManager($furnaceType)->getAll() as $recipe){
				try{
					if($this->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_20){
						$recipesWithTypeIds[] = new ProtocolShapelessRecipe(
							CraftingDataPacket::ENTRY_SHAPELESS,
							BE::packUnsignedInt($recipeNetId), //TODO: this should probably be changed to something human-readable
							[$converter->coreRecipeIngredientToNet($recipe->getInput())],
							[$converter->coreItemStackToNet($recipe->getResult())],
							$nullUUID,
							$typeTag,
							50,
							$noUnlockingRequirement,
							$recipeNetId //not used, but we need to fill them with something unique regardless
						);
					}else{
						$input = $recipe->getInput();

						if($input instanceof TagWildcardRecipeIngredient){
							foreach(ItemTagToIdMap::getInstance($this->getProtocolId())->getIdsForTag($input->getTagName()) as $itemId){
								$input = $converter->coreRecipeIngredientToNet(new MetaWildcardRecipeIngredient($itemId))->getDescriptor();
								if(!$input instanceof IntIdMetaItemDescriptor){
									throw new AssumptionFailedError();
								}

								$recipesWithTypeIds[] = new ProtocolFurnaceRecipe(
									CraftingDataPacket::ENTRY_FURNACE_DATA,
									$input->getId(),
									$input->getMeta(),
									$converter->coreItemStackToNet($recipe->getResult()),
									$typeTag
								);
							}
						}else{
							$input = $converter->coreRecipeIngredientToNet($input)->getDescriptor();
							if(!$input instanceof IntIdMetaItemDescriptor){
								throw new AssumptionFailedError();
							}

							$recipesWithTypeIds[] = new ProtocolFurnaceRecipe(
								CraftingDataPacket::ENTRY_FURNACE_DATA,
								$input->getId(),
								$input->getMeta(),
								$converter->coreItemStackToNet($recipe->getResult()),
								$typeTag
							);
						}
					}
				}catch(\InvalidArgumentException|ItemTypeSerializeException){
					continue;
				}
			}
		}

		$potionTypeRecipes = [];
		foreach($manager->getPotionTypeRecipes() as $recipe){
			try{
				$input = $converter->coreRecipeIngredientToNet($recipe->getInput())->getDescriptor();
				$ingredient = $converter->coreRecipeIngredientToNet($recipe->getIngredient())->getDescriptor();
				if(!$input instanceof IntIdMetaItemDescriptor || !$ingredient instanceof IntIdMetaItemDescriptor){
					throw new AssumptionFailedError();
				}
				$output = $converter->coreItemStackToNet($recipe->getOutput());
				$potionTypeRecipes[] = new ProtocolPotionTypeRecipe(
					$input->getId(),
					$input->getMeta(),
					$ingredient->getId(),
					$ingredient->getMeta(),
					$output->getId(),
					$output->getMeta()
				);
			}catch(\InvalidArgumentException|ItemTypeSerializeException){
				continue;
			}
		}

		$potionContainerChangeRecipes = [];
		$itemTypeDictionary = $converter->getItemTypeDictionary();
		foreach($manager->getPotionContainerChangeRecipes() as $recipe){
			try{
				$input = $itemTypeDictionary->fromStringId($recipe->getInputItemId());
				$ingredient = $converter->coreRecipeIngredientToNet($recipe->getIngredient())->getDescriptor();
				if(!$ingredient instanceof IntIdMetaItemDescriptor){
					throw new AssumptionFailedError();
				}
				$output = $itemTypeDictionary->fromStringId($recipe->getOutputItemId());
				$potionContainerChangeRecipes[] = new ProtocolPotionContainerChangeRecipe(
					$input,
					$ingredient->getId(),
					$output
				);
			}catch(\InvalidArgumentException|ItemTypeSerializeException){
				continue;
			}
		}

		Timings::$craftingDataCacheRebuild->stopTiming();
		return CraftingDataPacket::create($recipesWithTypeIds, $potionTypeRecipes, $potionContainerChangeRecipes, [], true);
	}
}
