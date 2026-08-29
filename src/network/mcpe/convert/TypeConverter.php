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

namespace pocketmine\network\mcpe\convert;

use DaveRandom\CallbackValidator\BuiltInTypes;
use DaveRandom\CallbackValidator\CallbackType;
use DaveRandom\CallbackValidator\ParameterType;
use DaveRandom\CallbackValidator\ReturnType;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\block\tile\Container;
use pocketmine\block\VanillaBlocks;
use pocketmine\crafting\ExactRecipeIngredient;
use pocketmine\crafting\MetaWildcardRecipeIngredient;
use pocketmine\crafting\RecipeIngredient;
use pocketmine\crafting\TagWildcardRecipeIngredient;
use pocketmine\data\bedrock\item\BlockItemIdMap;
use pocketmine\data\bedrock\item\downgrade\ItemIdMetaDowngrader;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\NbtException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\nbt\TreeRoot;
use pocketmine\nbt\UnexpectedTagTypeException;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\types\GameMode as ProtocolGameMode;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackExtraData;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackExtraDataShield;
use pocketmine\network\mcpe\protocol\types\recipe\IntIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeIngredient as ProtocolRecipeIngredient;
use pocketmine\network\mcpe\protocol\types\recipe\StringIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\TagItemDescriptor;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\ProtocolSingletonTrait;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function count;
use function get_class;
use function hash;
use function spl_object_id;

class TypeConverter{
	use ProtocolSingletonTrait {
		ProtocolSingletonTrait::__construct as private __protocolConstruct;
	}

	private const PM_ID_TAG = "___Id___";
	private const PM_FULL_NBT_HASH_TAG = "___FullNbtHash___";

	private const RECIPE_INPUT_WILDCARD_META = 0x7fff;

	private BlockItemIdMap $blockItemIdMap;
	private BlockTranslator $blockTranslator;
	private ItemTranslator $itemTranslator;
	private ItemTypeDictionary $itemTypeDictionary;
	private ItemIdMetaDowngrader $itemDataDowngrader;
	private int $shieldRuntimeId;

	private SkinAdapter $skinAdapter;

	public function __construct(int $protocolId){
		$this->__protocolConstruct($protocolId);

		//TODO: inject stuff via constructor
		$this->blockItemIdMap = BlockItemIdMap::getInstance();

		$this->blockTranslator = BlockTranslator::loadFromProtocolId($protocolId);

		$this->itemTypeDictionary = ItemTypeDictionaryFromDataHelper::loadFromProtocolId($protocolId);
		$this->itemDataDowngrader = new ItemIdMetaDowngrader($this->itemTypeDictionary, ItemTranslator::getItemSchemaId($protocolId));
		$this->shieldRuntimeId = $this->itemTypeDictionary->fromStringId(ItemTypeNames::SHIELD);

		$this->itemTranslator = new ItemTranslator(
			$this->itemTypeDictionary,
			$this->blockTranslator->getBlockStateDictionary(),
			GlobalItemDataHandlers::getSerializer(),
			GlobalItemDataHandlers::getDeserializer(),
			$this->blockItemIdMap,
			$this->itemDataDowngrader
		);

		$this->skinAdapter = new LegacySkinAdapter();
	}

	public function getBlockTranslator() : BlockTranslator{ return $this->blockTranslator; }

	public function getItemTypeDictionary() : ItemTypeDictionary{ return $this->itemTypeDictionary; }

	public function getItemTranslator() : ItemTranslator{ return $this->itemTranslator; }

	public function getSkinAdapter() : SkinAdapter{ return $this->skinAdapter; }

	public function setSkinAdapter(SkinAdapter $skinAdapter) : void{
		$this->skinAdapter = $skinAdapter;
	}

	/**
	 * Returns a client-friendly gamemode of the specified real gamemode
	 * This function takes care of handling gamemodes known to MCPE (as of 1.1.0.3, that includes Survival, Creative and Adventure)
	 *
	 * @internal
	 */
	public function coreGameModeToProtocol(GameMode $gamemode) : int{
		return match($gamemode){
			GameMode::SURVIVAL => ProtocolGameMode::SURVIVAL,
			//TODO: native spectator support
			GameMode::CREATIVE, GameMode::SPECTATOR => ProtocolGameMode::CREATIVE,
			GameMode::ADVENTURE => ProtocolGameMode::ADVENTURE,
		};
	}

	public function protocolGameModeToCore(int $gameMode) : ?GameMode{
		return match($gameMode){
			ProtocolGameMode::SURVIVAL => GameMode::SURVIVAL,
			ProtocolGameMode::CREATIVE => GameMode::CREATIVE,
			ProtocolGameMode::ADVENTURE => GameMode::ADVENTURE,
			ProtocolGameMode::SURVIVAL_VIEWER, ProtocolGameMode::CREATIVE_VIEWER => GameMode::SPECTATOR,
			//TODO: native spectator support
			default => null,
		};
	}

	public function coreRecipeIngredientToNet(?RecipeIngredient $ingredient) : ProtocolRecipeIngredient{
		if($ingredient === null){
			return new ProtocolRecipeIngredient(null, 0);
		}
		if($ingredient instanceof MetaWildcardRecipeIngredient){
			$oldStringId = $ingredient->getItemId();
			[$stringId, $meta] = $this->itemDataDowngrader->downgrade($oldStringId, 0);

			$meta = $meta === 0 && $stringId === $oldStringId ? self::RECIPE_INPUT_WILDCARD_META : $meta; // downgrader returns the same meta
			if($this->protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				$descriptor = new StringIdMetaItemDescriptor($stringId, $meta);
			}else{
				$id = $this->itemTypeDictionary->fromStringId($stringId);
				$descriptor = new IntIdMetaItemDescriptor($id, $meta);
			}
		}elseif($ingredient instanceof ExactRecipeIngredient){
			$item = $ingredient->getItem();
			[$id, $meta, $blockRuntimeId] = $this->itemTranslator->toNetworkId($item);
			if($blockRuntimeId !== null){
				$meta = $this->blockTranslator->getBlockStateDictionary()->getMetaFromStateId($blockRuntimeId);
				if($meta === null){
					throw new AssumptionFailedError("Every block state should have an associated meta value");
				}
			}
			if($this->protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				$id = $this->itemTypeDictionary->fromIntId($id);
				$descriptor = new StringIdMetaItemDescriptor($id, $meta);
			}else{
				$descriptor = new IntIdMetaItemDescriptor($id, $meta);
			}
		}elseif($ingredient instanceof TagWildcardRecipeIngredient){
			$descriptor = new TagItemDescriptor($ingredient->getTagName());
		}else{
			throw new \LogicException("Unsupported recipe ingredient type " . get_class($ingredient) . ", only " . ExactRecipeIngredient::class . " and " . MetaWildcardRecipeIngredient::class . " are supported");
		}

		return new ProtocolRecipeIngredient($descriptor, 1);
	}

	public function netRecipeIngredientToCore(ProtocolRecipeIngredient $ingredient) : ?RecipeIngredient{
		$descriptor = $ingredient->getDescriptor();
		if($descriptor === null){
			return null;
		}

		if($descriptor instanceof TagItemDescriptor){
			return new TagWildcardRecipeIngredient($descriptor->getTag());
		}

		if($descriptor instanceof StringIdMetaItemDescriptor){
			$stringId = $descriptor->getId();
			$meta = $descriptor->getMeta();
		}else{
			throw new \LogicException("Unsupported conversion of recipe ingredient to core item stack");
		}

		if($meta === self::RECIPE_INPUT_WILDCARD_META){
			return new MetaWildcardRecipeIngredient($stringId);
		}

		$blockRuntimeId = null;
		if(($blockId = $this->blockItemIdMap->lookupBlockId($stringId)) !== null){
			$blockRuntimeId = $this->blockTranslator->getBlockStateDictionary()->lookupStateIdFromIdMeta($blockId, $meta);
			if($blockRuntimeId !== null){
				$meta = 0;
			}
		}
		$result = $this->itemTranslator->fromNetworkId(
			$this->itemTypeDictionary->fromStringId($stringId),
			$meta,
			$blockRuntimeId ?? ItemTranslator::NO_BLOCK_RUNTIME_ID
		);
		return new ExactRecipeIngredient($result);
	}

	/**
	 * Strips unnecessary block actor NBT from items that have it.
	 * This tag can potentially be extremely large, and is not read by the client anyway.
	 */
	protected function stripBlockEntityNBT(CompoundTag $tag) : bool{
		if(($tag->getTag(Item::TAG_BLOCK_ENTITY_TAG)) !== null){
			//client doesn't use this tag, so it's fine to delete completely
			$tag->removeTag(Item::TAG_BLOCK_ENTITY_TAG);
			return true;
		}
		return false;
	}

	/**
	 * Strips non-viewable data from shulker boxes and similar blocks
	 * The lore for shulker boxes only requires knowing the type & count of items and possibly custom name
	 * We don't need to, and should not allow, sending nested inventories across the network.
	 */
	protected function stripContainedItemNonVisualNBT(CompoundTag $tag) : bool{
		try{
			$blockEntityInventoryTag = $tag->getListTag(Container::TAG_ITEMS, CompoundTag::class);
		}catch(UnexpectedTagTypeException){
			return false;
		}
		if($blockEntityInventoryTag !== null && $blockEntityInventoryTag->count() > 0){
			$stripped = new ListTag();

			foreach($blockEntityInventoryTag as $itemTag){
				try{
					$containedItem = Item::nbtDeserialize($itemTag);
					$customName = $containedItem->getCustomName();
					$containedItem->clearNamedTag();
					$containedItem->setCustomName($customName);
					$stripped->push($containedItem->nbtSerialize());
				}catch(SavedDataLoadingException){
					continue;
				}
			}
			$tag->setTag(Container::TAG_ITEMS, $stripped);
			return true;
		}
		return false;
	}

	/**
	 * Computes a hash of an item's server-side NBT.
	 * This is baked into an item's network NBT to make sure the client doesn't try to stack items with the same network
	 * NBT but different server-side NBT.
	 */
	protected function hashNBT(Tag $tag) : string{
		$encoded = (new LittleEndianNbtSerializer())->write(new TreeRoot($tag));
		return hash('sha256', $encoded, binary: true);
	}

	/**
	 * TODO: HACK!
	 * Creates a copy of an item's NBT with non-viewable data stripped.
	 * This is a pretty yucky hack that's mainly needed because of inventories inside blockitems containing blockentity
	 * data. There isn't really a good way to deal with this due to the way tiles currently require a position,
	 * otherwise we could just keep a copy of the tile context and ask it for persistent vs network NBT as needed.
	 * Unfortunately, making this nice will require significant BC breaks, so this will have to do for now.
	 */
	protected function cleanupUnnecessaryItemNBT(CompoundTag $original) : CompoundTag{
		$tag = clone $original;
		$anythingStripped = false;
		foreach([
			$this->stripContainedItemNonVisualNBT($tag),
			$this->stripBlockEntityNBT($tag)
		] as $stripped){
			$anythingStripped = $anythingStripped || $stripped;
		}

		if($anythingStripped){
			$tag->setByteArray(self::PM_FULL_NBT_HASH_TAG, $this->hashNBT($original));
		}
		return $tag;
	}

	public function coreItemStackToNet(Item $itemStack) : ItemStack{
		if($itemStack->isNull()){
			return ItemStack::null();
		}
		$nbt = $itemStack->getNamedTag();
		if($nbt->count() === 0){
			$nbt = null;
		}else{
			$nbt = $this->cleanupUnnecessaryItemNBT($nbt);
		}

		$idMeta = $this->itemTranslator->toNetworkIdQuiet($itemStack);
		if($idMeta === null){
			//Display unmapped items as INFO_UPDATE, but stick something in their NBT to make sure they don't stack with
			//other unmapped items.
			[$id, $meta, $blockRuntimeId] = $this->itemTranslator->toNetworkId(VanillaBlocks::INFO_UPDATE()->asItem());
			if($nbt === null){
				$nbt = new CompoundTag();
			}
			$nbt->setLong(self::PM_ID_TAG, $itemStack->getStateId());
		}else{
			[$id, $meta, $blockRuntimeId] = $idMeta;
		}

		$extraData = $id === $this->shieldRuntimeId ?
			new ItemStackExtraDataShield($nbt, canPlaceOn: [], canDestroy: [], blockingTick: 0) :
			new ItemStackExtraData($nbt, canPlaceOn: [], canDestroy: []);

		$extraDataSerializer = new ByteBufferWriter();
		$extraData->write($extraDataSerializer);

		return new ItemStack(
			$id,
			$meta,
			$itemStack->getCount(),
			$blockRuntimeId ?? ItemTranslator::NO_BLOCK_RUNTIME_ID,
			$extraDataSerializer->getData(),
		);
	}

	/**
	 * WARNING: Avoid this in server-side code. If you need to compare ItemStacks provided by the client to the
	 * server, prefer encoding the server's itemstack and comparing the serialized ItemStack, instead of converting
	 * the client's ItemStack to a core Item.
	 * This method will fully decode the item's extra data, which can be very costly if the item has a lot of NBT data.
	 *
	 * @throws TypeConversionException
	 */
	public function netItemStackToCore(ItemStack $itemStack) : Item{
		if($itemStack->getId() === 0){
			return VanillaItems::AIR();
		}
		$extraData = $this->deserializeItemStackExtraData($itemStack->getRawExtraData(), $itemStack->getId());

		$compound = $extraData->getNbt();

		$itemResult = $this->itemTranslator->fromNetworkId($itemStack->getId(), $itemStack->getMeta(), $itemStack->getBlockRuntimeId());

		if($compound !== null){
			$compound = clone $compound;
		}

		$itemResult->setCount($itemStack->getCount());
		if($compound !== null){
			try{
				$itemResult->setNamedTag($compound);
			}catch(NbtException $e){
				throw TypeConversionException::wrap($e, "Bad itemstack NBT data");
			}
		}

		return $itemResult;
	}

	/**
	 * @param Player[] $players
	 *
	 * @phpstan-return array{array<int, TypeConverter>, array<int, array<int, Player>>}
	 */
	public static function sortByConverter(array $players) : array{
		/** @var TypeConverter[] $typeConverters */
		$typeConverters = [];
		/** @var Player[][] $converterRecipients */
		$converterRecipients = [];
		foreach($players as $recipient){
			$typeConverter = $recipient->getNetworkSession()->getTypeConverter();
			$typeConverters[spl_object_id($typeConverter)] = $typeConverter;
			$converterRecipients[spl_object_id($typeConverter)][spl_object_id($recipient)] = $recipient;
		}

		return [
			$typeConverters,
			$converterRecipients
		];
	}

	/**
	 * @param Player[] $players
	 * @phpstan-param \Closure(TypeConverter) : ClientboundPacket[] $closure
	 */
	public static function broadcastByTypeConverter(array $players, \Closure $closure) : void{
		Utils::validateCallableSignature(new CallbackType(
			new ReturnType(BuiltInTypes::ARRAY, ReturnType::COVARIANT),
			new ParameterType('typeConverter', TypeConverter::class),
		), $closure);

		[$typeConverters, $converterRecipients] = self::sortByConverter($players);

		foreach($typeConverters as $key => $typeConverter){
			$packets = $closure($typeConverter);
			if(count($packets) > 0){
				NetworkBroadcastUtils::broadcastPackets($converterRecipients[$key], $packets);
			}
		}
	}

	public function deserializeItemStackExtraData(string $extraData, int $id) : ItemStackExtraData{
		$extraDataDeserializer = new ByteBufferReader($extraData);
		return $id === $this->shieldRuntimeId ?
			ItemStackExtraDataShield::read($extraDataDeserializer) :
			ItemStackExtraData::read($extraDataDeserializer);
	}
}
