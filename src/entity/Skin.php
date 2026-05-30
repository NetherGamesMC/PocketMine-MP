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

namespace pocketmine\entity;

use Ahc\Json\Comment as CommentedJsonDecoder;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\utils\Limits;
use function implode;
use function in_array;
use function json_encode;
use function strlen;
use const JSON_THROW_ON_ERROR;

final class Skin{
	public const ACCEPTED_SKIN_SIZES = [
		64 * 32 * 4,
		64 * 64 * 4,
		128 * 64 * 4,
		128 * 128 * 4,
		256 * 128 * 4,
		256 * 256 * 4,
		512 * 256 * 4,
		512 * 512 * 4,
		1024 * 512 * 4,
		1024 * 1024 * 4,
	];

	private string $skinId;
	private string $skinData;
	private string $capeData;
	private string $geometryName;
	private string $geometryData;

	private ?SkinData $fullSkinData = null;

	private static function checkLength(string $string, string $name, int $maxLength) : void{
		return;
		
		if(strlen($string) > $maxLength){
			throw new InvalidSkinException("$name must be at most $maxLength bytes, but have " . strlen($string) . " bytes");
		}
	}

	private static function findClosestSkinSize(int $actualSize) : ?int{
		$closestSize = null;
		$minDiff = PHP_INT_MAX;
		
		foreach(self::ACCEPTED_SKIN_SIZES as $size){
			$diff = abs($actualSize - $size);
			if($diff < $minDiff){
				$minDiff = $diff;
				$closestSize = $size;
			}
		}
		
		if($closestSize !== null && $minDiff <= $closestSize * 0.5){
			return $closestSize;
		}
		
		return null;
	}

	public function __construct(string $skinId, string $skinData, string $capeData = "", string $geometryName = "", string $geometryData = ""){
		self::checkLength($skinId, "Skin ID", Limits::INT16_MAX);
		self::checkLength($geometryName, "Geometry name", Limits::INT16_MAX);
		self::checkLength($geometryData, "Geometry data", Limits::INT32_MAX);

		if($skinId === ""){
			$skinId = "Standard_Custom_" . bin2hex(random_bytes(4));
		}
		
		if($geometryData !== ""){
			try{
				$decodedGeometry = (new CommentedJsonDecoder())->decode($geometryData);
				$geometryData = json_encode($decodedGeometry, JSON_THROW_ON_ERROR);
			}catch(\RuntimeException | \JsonException $e){
			}
		}
		
		if($geometryName === ""){
			$geometryName = "geometry.humanoid.custom";
		}

		$this->skinId = $skinId;
		$this->skinData = $skinData;
		$this->capeData = $capeData;
		$this->geometryName = $geometryName;
		$this->geometryData = $geometryData;
	}

	public function getSkinId() : string{
		return $this->skinId;
	}

	public function getSkinData() : string{
		return $this->skinData;
	}

	public function getCapeData() : string{
		return $this->capeData;
	}

	public function getGeometryName() : string{
		return $this->geometryName;
	}

	public function getGeometryData() : string{
		return $this->geometryData;
	}

	public function getFullSkinData() : ?SkinData{
		return $this->fullSkinData;
	}

	public function setFullSkinData(?SkinData $data) : void{
		$this->fullSkinData = $data;
	}
}