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

namespace pocketmine\data\bedrock\block\downgrade;

use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\nbt\tag\Tag;
use pocketmine\utils\Utils;
use function count;
use function ksort;
use const SORT_NUMERIC;

final class BlockStateDowngrader{
	/** @var BlockStateDowngradeSchema[] */
	private array $downgradeSchemas = [];

	/**
	 * @param BlockStateDowngradeSchema[] $downgradeSchemas
	 *
	 * @phpstan-param array<int, BlockStateDowngradeSchema> $downgradeSchemas
	 */
	public function __construct(array $downgradeSchemas){
		foreach($downgradeSchemas as $schema){
			$this->addSchema($schema);
		}
	}

	public function addSchema(BlockStateDowngradeSchema $schema) : void{
		$schemaId = $schema->getSchemaId();
		if(isset($this->downgradeSchemas[$schemaId])){
			throw new \InvalidArgumentException("Cannot add two schemas with the same schema ID");
		}
		$this->downgradeSchemas[$schemaId] = $schema;

		ksort($this->downgradeSchemas, SORT_NUMERIC);
	}

	public function downgrade(BlockStateData $blockStateData) : BlockStateData{
		$version = $blockStateData->getVersion();
		foreach($this->downgradeSchemas as $schema){
			$resultVersion = $schema->getVersionId();
			if($version < $resultVersion){
				//even if this is actually the same version, we have to apply it anyway because mojang are dumb and
				//didn't always bump the blockstate version when changing it :(
				continue;
			}
			$oldName = $blockStateData->getName();
			$oldState = $blockStateData->getStates();
			if(isset($schema->remappedStates[$oldName])){
				foreach($schema->remappedStates[$oldName] as $remap){
					$shouldCopyStates = [];
					foreach($remap->copiedState as $key){
						if(isset($oldState[$key])){
							$shouldCopyStates[$key] = $oldState[$key];
						}
					}
					if(count($oldState) - count($shouldCopyStates) !== count($remap->oldState)){
						continue; //try next state
					}
					foreach(Utils::stringifyKeys($oldState) as $k => $v){
						if(isset($shouldCopyStates[$k])){
							continue;
						}
						if(!isset($remap->oldState[$k]) || !$remap->oldState[$k]->equals($v)){
							continue 2; //try next state
						}
					}
					$newStates = $remap->newState;
					foreach(Utils::stringifyKeys($shouldCopyStates) as $key => $value){
						if(!isset($newStates[$key])){
							$newStates[$key] = $value;
						}
						//is there need something else for isset?
					}
					$blockStateData = new BlockStateData($remap->newName, $newStates, $resultVersion);
					continue 2; //try next schema
				}
			}
			$newName = $schema->renamedIds[$oldName] ?? null;

			$stateChanges = 0;
			$states = $blockStateData->getStates();

			$states = $this->applyPropertyAdded($schema, $oldName, $states, $stateChanges);
			$states = $this->applyPropertyRemoved($schema, $oldName, $states, $stateChanges);
			$states = $this->applyPropertyRenamedOrValueChanged($schema, $oldName, $states, $stateChanges);
			$states = $this->applyPropertyValueChanged($schema, $oldName, $states, $stateChanges);

			if($newName !== null || $stateChanges > 0){
				$blockStateData = new BlockStateData($newName ?? $oldName, $states, $resultVersion);
				//don't break out; we may need to further upgrade the state
			}
		}

		return $blockStateData;
	}

	/**
	 * @param Tag[] $states
	 * @phpstan-param array<string, Tag> $states
	 *
	 * @return Tag[]
	 * @phpstan-return array<string, Tag>
	 */
	private function applyPropertyAdded(BlockStateDowngradeSchema $schema, string $oldName, array $states, int &$stateChanges) : array{
		if(isset($schema->addedProperties[$oldName])){
			foreach(Utils::stringifyKeys($schema->addedProperties[$oldName]) as $propertyName => $value){
				if(!isset($states[$propertyName])){
					$stateChanges++;
					$states[$propertyName] = $value;
				}
			}
		}

		return $states;
	}

	/**
	 * @param Tag[] $states
	 * @phpstan-param array<string, Tag> $states
	 *
	 * @return Tag[]
	 * @phpstan-return array<string, Tag>
	 */
	private function applyPropertyRemoved(BlockStateDowngradeSchema $schema, string $oldName, array $states, int &$stateChanges) : array{
		if(isset($schema->removedProperties[$oldName])){
			foreach($schema->removedProperties[$oldName] as $propertyName){
				if(isset($states[$propertyName])){
					$stateChanges++;
					unset($states[$propertyName]);
				}
			}
		}

		return $states;
	}

	private function locateNewPropertyValue(BlockStateDowngradeSchema $schema, string $oldName, string $oldPropertyName, Tag $oldValue) : Tag{
		if(isset($schema->remappedPropertyValues[$oldName][$oldPropertyName])){
			foreach($schema->remappedPropertyValues[$oldName][$oldPropertyName] as $mappedPair){
				if($mappedPair->old->equals($oldValue)){
					return $mappedPair->new;
				}
			}
		}

		return $oldValue;
	}

	/**
	 * @param Tag[] $states
	 * @phpstan-param array<string, Tag> $states
	 *
	 * @return Tag[]
	 * @phpstan-return array<string, Tag>
	 */
	private function applyPropertyRenamedOrValueChanged(BlockStateDowngradeSchema $schema, string $oldName, array $states, int &$stateChanges) : array{
		if(isset($schema->renamedProperties[$oldName])){
			foreach(Utils::stringifyKeys($schema->renamedProperties[$oldName]) as $oldPropertyName => $newPropertyName){
				$oldValue = $states[$oldPropertyName] ?? null;
				if($oldValue !== null){
					$stateChanges++;
					unset($states[$oldPropertyName]);

					//If a value remap is needed, we need to do it here, since we won't be able to locate the property
					//after it's been renamed - value remaps are always indexed by old property name for the sake of
					//being able to do changes in any order.
					$states[$newPropertyName] = $this->locateNewPropertyValue($schema, $oldName, $oldPropertyName, $oldValue);
				}
			}
		}

		return $states;
	}

	/**
	 * @param Tag[] $states
	 * @phpstan-param array<string, Tag> $states
	 *
	 * @return Tag[]
	 * @phpstan-return array<string, Tag>
	 */
	private function applyPropertyValueChanged(BlockStateDowngradeSchema $schema, string $oldName, array $states, int &$stateChanges) : array{
		if(isset($schema->remappedPropertyValues[$oldName])){
			foreach(Utils::stringifyKeys($schema->remappedPropertyValues[$oldName]) as $oldPropertyName => $remappedValues){
				$oldValue = $states[$oldPropertyName] ?? null;
				if($oldValue !== null){
					$newValue = $this->locateNewPropertyValue($schema, $oldName, $oldPropertyName, $oldValue);
					if($newValue !== $oldValue){
						$stateChanges++;
						$states[$oldPropertyName] = $newValue;
					}
				}
			}
		}

		return $states;
	}
}
