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

use pocketmine\color\Color;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\cache\model\VoxelShapesData;
use pocketmine\network\mcpe\protocol\AvailableActorIdentifiersPacket;
use pocketmine\network\mcpe\protocol\BiomeDefinitionListPacket;
use pocketmine\network\mcpe\protocol\JigsawStructureDataPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\types\biome\BiomeDefinitionEntry;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\SerializableVoxelCells;
use pocketmine\network\mcpe\protocol\types\SerializableVoxelShape;
use pocketmine\network\mcpe\protocol\VoxelShapesPacket;
use pocketmine\utils\Filesystem;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use pocketmine\world\biome\model\BiomeDefinitionEntryData;
use function count;
use function get_debug_type;
use function is_array;
use function json_decode;

class StaticPacketCache{
	use SingletonTrait;

	/**
	 * @phpstan-return CacheableNbt<CompoundTag>
	 */
	protected static function loadCompoundFromFile(string $filePath) : CacheableNbt{
		return new CacheableNbt((new NetworkNbtSerializer())->read(Filesystem::fileGetContents($filePath))->mustGetCompoundTag());
	}

	/**
	 * @return list<BiomeDefinitionEntry>
	 */
	private static function loadBiomeDefinitionModel(string $filePath) : array{
		$biomeEntries = json_decode(Filesystem::fileGetContents($filePath), associative: true);
		if(!is_array($biomeEntries)){
			throw new SavedDataLoadingException("$filePath root should be an array, got " . get_debug_type($biomeEntries));
		}

		$jsonMapper = new \JsonMapper();
		$jsonMapper->bExceptionOnMissingData = true;
		$jsonMapper->bStrictObjectTypeChecking = true;
		$jsonMapper->bEnforceMapType = false;

		$entries = [];
		foreach(Utils::promoteKeys($biomeEntries) as $biomeName => $entry){
			if(!is_array($entry)){
				throw new SavedDataLoadingException("$filePath should be an array of objects, got " . get_debug_type($entry));
			}

			try{
				$biomeDefinition = $jsonMapper->map($entry, new BiomeDefinitionEntryData());

				$mapWaterColour = $biomeDefinition->mapWaterColour;
				$entries[] = new BiomeDefinitionEntry(
					(string) $biomeName,
					$biomeDefinition->id,
					$biomeDefinition->temperature,
					$biomeDefinition->downfall,
					$biomeDefinition->redSporeDensity,
					$biomeDefinition->blueSporeDensity,
					$biomeDefinition->ashDensity,
					$biomeDefinition->whiteAshDensity,
					$biomeDefinition->foliageSnow,
					$biomeDefinition->depth,
					$biomeDefinition->scale,
					new Color(
						$mapWaterColour->r,
						$mapWaterColour->g,
						$mapWaterColour->b,
						$mapWaterColour->a
					),
					$biomeDefinition->rain,
					count($biomeDefinition->tags) > 0 ? $biomeDefinition->tags : null,
				);
			}catch(\JsonMapper_Exception $e){
				throw new \RuntimeException($e->getMessage(), 0, $e);
			}
		}

		return $entries;
	}

	private static function loadVoxelShapesModel(string $filePath) : VoxelShapesData{
		$voxelShapes = json_decode(Filesystem::fileGetContents($filePath), associative: true);
		if(!is_array($voxelShapes)){
			throw new SavedDataLoadingException("$filePath root should be an array, got " . get_debug_type($voxelShapes));
		}

		$jsonMapper = new \JsonMapper();
		$jsonMapper->bExceptionOnMissingData = true;
		$jsonMapper->bStrictObjectTypeChecking = true;
		$jsonMapper->bEnforceMapType = false;

		try{
			return $jsonMapper->map($voxelShapes, new VoxelShapesData());
		}catch(\JsonMapper_Exception $e){
			throw new \RuntimeException($e->getMessage(), 0, $e);
		}
	}

	private static function buildVoxelShapesPacket(VoxelShapesData $data) : VoxelShapesPacket{
		$shapes = [];
		foreach($data->shapes as $shape){
			$cells = $shape->cells;
			$shapes[] = new SerializableVoxelShape(
				new SerializableVoxelCells($cells->xSize, $cells->ySize, $cells->zSize, $cells->storage),
				$shape->x,
				$shape->y,
				$shape->z
			);
		}

		return VoxelShapesPacket::create($shapes, $data->nameMap, 0);
	}

	/** @phpstan-return list<BlockPaletteEntry> */
	private static function loadDataDrivenBlockPalette(string $filePath) : array{
		$nbt = self::loadCompoundFromFile($filePath)->getRoot();
		if(!$nbt instanceof CompoundTag){
			throw new SavedDataLoadingException("$filePath should contain a CompoundTag, got " . get_debug_type($nbt));
		}
		$paletteNBT = $nbt->getListTag("blockPalette");
		if($paletteNBT === null){
			throw new SavedDataLoadingException("$filePath should contain a blockPalette ListTag");
		}
		$palette = [];
		foreach($paletteNBT->getValue() as $entryNBT){
			if(!$entryNBT instanceof CompoundTag){
				throw new SavedDataLoadingException("$filePath blockPalette should contain CompoundTag entries, got " . get_debug_type($entryNBT));
			}
			$name = $entryNBT->getString("name");
			$states = $entryNBT->getCompoundTag("states");
			if($states === null){
				throw new SavedDataLoadingException("$filePath blockPalette entry $name should contain a states CompoundTag");
			}
			$palette[] = new BlockPaletteEntry($name, new CacheableNbt($states));
		}
		return $palette;
	}

	private static function make() : self{
		return new self(
			BiomeDefinitionListPacket::fromDefinitions(self::loadBiomeDefinitionModel(BedrockDataFiles::BIOME_DEFINITIONS_JSON)),
			BiomeDefinitionListPacket::createLegacy(self::loadCompoundFromFile(BedrockDataFiles::BIOME_DEFINITIONS_NBT)),
			AvailableActorIdentifiersPacket::create(self::loadCompoundFromFile(BedrockDataFiles::ENTITY_IDENTIFIERS_NBT)),
			JigsawStructureDataPacket::create(self::loadCompoundFromFile(BedrockDataFiles::JIGSAW_STRUCTURES_DATA_NBT)),
			self::buildVoxelShapesPacket(self::loadVoxelShapesModel(BedrockDataFiles::VOXEL_SHAPES_JSON)),
			self::loadDataDrivenBlockPalette(BedrockDataFiles::DATA_DRIVEN_BLOCKS_NBT)
		);
	}

	/** @phpstan-param list<BlockPaletteEntry> $blockPaletteEntries */
	public function __construct(
		private BiomeDefinitionListPacket $biomeDefs,
		private BiomeDefinitionListPacket $legacyBiomeDefs,
		private AvailableActorIdentifiersPacket $availableActorIdentifiers,
		private JigsawStructureDataPacket $jigsawStructureData,
		private VoxelShapesPacket $voxelShapes,
		private array $blockPaletteEntries
	){
	}

	public function getBiomeDefs(int $protocolId) : BiomeDefinitionListPacket{
		return $protocolId >= ProtocolInfo::PROTOCOL_1_21_80 ? $this->biomeDefs : $this->legacyBiomeDefs;
	}

	public function getAvailableActorIdentifiers() : AvailableActorIdentifiersPacket{
		return $this->availableActorIdentifiers;
	}

	public function getJigsawStructureData() : JigsawStructureDataPacket{
		return $this->jigsawStructureData;
	}

	public function getVoxelShapes() : VoxelShapesPacket{
		return $this->voxelShapes;
	}

	/**
	 * @return BlockPaletteEntry[]
	 * @phpstan-return list<BlockPaletteEntry>
	 */
	public function getBlockPaletteEntries() : array{
		return $this->blockPaletteEntries;
	}
}
