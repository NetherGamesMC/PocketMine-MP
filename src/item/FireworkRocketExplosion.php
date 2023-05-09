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

use pocketmine\block\utils\DyeColor;
use pocketmine\color\Color;
use pocketmine\data\bedrock\DyeColorIdMap;
use pocketmine\data\bedrock\FireworkRocketTypeIdMap;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\utils\Binary;
use pocketmine\utils\Utils;
use function array_key_first;
use function array_values;
use function count;
use function strlen;

class FireworkRocketExplosion{

	protected const TAG_TYPE = "FireworkType"; //TAG_Byte
	protected const TAG_COLORS = "FireworkColor"; //TAG_ByteArray
	protected const TAG_FADE_COLORS = "FireworkFade"; //TAG_ByteArray
	protected const TAG_TWINKLE = "FireworkFlicker"; //TAG_Byte
	protected const TAG_TRAIL = "FireworkTrail"; //TAG_Byte

	public static function fromCompoundTag(CompoundTag $tag) : self{
		$colors = self::decodeColors($tag->getByteArray(self::TAG_COLORS));
		if(count($colors) === 0){
			throw new SavedDataLoadingException("Colors list cannot be empty");
		}

		return new self(
			FireworkRocketTypeIdMap::getInstance()->fromId($tag->getByte(self::TAG_TYPE)) ?? throw new SavedDataLoadingException("Invalid firework type"),
			$colors,
			self::decodeColors($tag->getByteArray(self::TAG_FADE_COLORS)),
			$tag->getByte(self::TAG_TWINKLE, 0) !== 0,
			$tag->getByte(self::TAG_TRAIL, 0) !== 0
		);
	}

	/**
	 * @return DyeColor[]
	 */
	protected static function decodeColors(string $colorsBytes) : array{
		$colors = [];

		$dyeColorIdMap = DyeColorIdMap::getInstance();
		for($i = 0; $i < strlen($colorsBytes); $i++){
			$colorByte = Binary::readByte($colorsBytes[$i]);
			$color = $dyeColorIdMap->fromInvertedId($colorByte);
			if($color !== null){
				$colors[] = $color;
			}else{
				throw new SavedDataLoadingException("Unknown color $colorByte");
			}
		}

		return $colors;
	}

	/**
	 * @param DyeColor[] $colors
	 */
	protected static function encodeColors(array $colors) : string{
		$colorsBytes = "";

		$dyeColorIdMap = DyeColorIdMap::getInstance();
		foreach($colors as $color){
			$colorsBytes .= Binary::writeByte($dyeColorIdMap->toInvertedId($color));
		}

		return $colorsBytes;
	}

	/** @var \Closure(DyeColor) : void */
	protected \Closure $colorsValidator;

	/**
	 * @param DyeColor[] $colors
	 * @param DyeColor[] $fadeColors
	 */
	public function __construct(
		protected FireworkRocketType $type,
		protected array $colors,
		protected array $fadeColors,
		protected bool $twinkle,
		protected bool $trail
	){
		$this->colorsValidator = function(DyeColor $_) : void{};

		if(count($colors) === 0){
			throw new \InvalidArgumentException("Colors list cannot be empty");
		}
		$this->setColors($colors);
		$this->setFadeColors($fadeColors);
	}

	public function getType() : FireworkRocketType{
		return $this->type;
	}

	/** @return $this */
	public function setType(FireworkRocketType $type) : self{
		$this->type = $type;
		return $this;
	}

	/**
	 * @return DyeColor[]
	 */
	public function getColors() : array{
		return $this->colors;
	}

	/**
	 * @param DyeColor[] $colors
	 *
	 * @return $this
	 */
	public function setColors(array $colors) : self{
		if(count($colors) === 0){
			throw new \InvalidArgumentException("Colors list cannot be empty");
		}
		Utils::validateArrayValueType($colors, $this->colorsValidator);
		$this->colors = array_values($colors);

		return $this;
	}

	/**
	 * Returns the main color, this defines stuff like meta.
	 */
	public function getMainColor() : DyeColor{
		return $this->colors[array_key_first($this->colors)];
	}

	/**
	 * Returns the mix of all colors.
	 */
	public function getColorMix() : Color{
		/** @var Color[] $colors */
		$colors = [];
		foreach ($this->colors as $dyeColor) {
			$colors[] = $dyeColor->getRgbValue();
		}
		return Color::mix(...$colors);
	}

	/**
	 * @return DyeColor[]
	 */
	public function getFadeColors() : array{
		return $this->fadeColors;
	}

	/**
	 * @param DyeColor[] $colors
	 *
	 * @return $this
	 */
	public function setFadeColors(array $colors) : self{
		Utils::validateArrayValueType($colors, $this->colorsValidator);
		$this->fadeColors = array_values($colors);
		return $this;
	}

	public function willTwinkle() : bool{
		return $this->twinkle;
	}

	/** @return $this */
	public function setTwinkle(bool $twinkle) : self{
		$this->twinkle = $twinkle;
		return $this;
	}

	public function getTrail() : bool{
		return $this->trail;
	}

	/** @return $this */
	public function setTrail(bool $trail) : self{
		$this->trail = $trail;
		return $this;
	}

	public function toCompoundTag() : CompoundTag{
		return CompoundTag::create()
			->setByte(self::TAG_TYPE, FireworkRocketTypeIdMap::getInstance()->toId($this->type))
			->setByteArray(self::TAG_COLORS, self::encodeColors($this->colors))
			->setByteArray(self::TAG_FADE_COLORS, self::encodeColors($this->fadeColors))
			->setByte(self::TAG_TWINKLE, $this->twinkle ? 1 : 0)
			->setByte(self::TAG_TRAIL, $this->trail ? 1 : 0)
		;
	}
}
