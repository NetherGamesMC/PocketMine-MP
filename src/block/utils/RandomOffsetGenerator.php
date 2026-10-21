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

namespace pocketmine\block\utils;

use pocketmine\xoroshiro128pp\Xoroshiro128PP;
use function gmp_add;
use function gmp_and;
use function gmp_cmp;
use function gmp_div_q;
use function gmp_init;
use function gmp_intval;
use function gmp_mul;
use function gmp_or;
use function gmp_pow;
use function gmp_sub;
use function gmp_testbit;
use function gmp_xor;

/**
 * Computes the position-seeded random offset Bedrock applies to a block's visual and collision shape (its
 * minecraft:random_offset component). Used by bamboo, pointed dripstone, sulfur spike and similar blocks, where a
 * position hash seeds a Xoroshiro128++ generator and each axis draws one value quantised into the configured range
 * and step count.
 */
final class RandomOffsetGenerator{

	private const MASK64 = "0xffffffffffffffff";

	private static function mask(\GMP $g) : \GMP{
		return gmp_and($g, gmp_init(self::MASK64));
	}

	private static function shr(\GMP $g, int $n) : \GMP{
		return gmp_div_q(self::mask($g), gmp_pow(2, $n));
	}

	private static function shl(\GMP $g, int $n) : \GMP{
		return self::mask(gmp_mul($g, gmp_pow(2, $n)));
	}

	/** Arithmetic (sign-propagating) shift right. */
	private static function ashr(\GMP $g, int $n) : \GMP{
		$r = self::shr($g, $n);
		if(gmp_testbit($g, 63)){
			$r = gmp_or($r, self::shl(gmp_sub(gmp_pow(2, $n), 1), 64 - $n));
		}
		return $r;
	}

	private static function sext32(\GMP $g) : \GMP{
		$low = gmp_and($g, gmp_init("0xffffffff"));
		if(gmp_testbit($low, 31)){
			$low = gmp_or($low, gmp_init("0xffffffff00000000"));
		}
		return $low;
	}

	private static function toSigned(\GMP $g) : int{
		$masked = self::mask($g);
		if(gmp_cmp($masked, gmp_init("0x7fffffffffffffff")) > 0){
			$masked = gmp_sub($masked, gmp_pow(2, 64));
		}
		return gmp_intval($masked);
	}

	/**
	 * Hashes a block position down to the 64-bit seed the generator is built from.
	 *
	 * X is read as unsigned and Z as signed, which is not symmetrical but is what the game does, so it has to be
	 * reproduced rather than tidied up. The result is fed to Xoroshiro128PP::fromSeed(), which applies the
	 * silver ratio mix that completes the hash.
	 */
	private static function positionHash(int $x, int $z) : int{
		$xu = gmp_and(gmp_init($x), gmp_init("0xffffffff"));
		$a = self::ashr(self::mask(gmp_mul(gmp_init("0x2fc20f00000001"), $xu)), 32);
		$b = self::mask(gmp_mul(gmp_init($z), gmp_init("0x6ebfff5")));
		$v1 = self::mask(gmp_xor($a, $b));

		$c = self::mask(gmp_add(gmp_mul(gmp_init("0x285b825"), $v1), gmp_init(11)));
		return self::toSigned(self::sext32(self::shr(self::mask(gmp_mul($v1, $c)), 16)));
	}

	/**
	 * Snaps a random value into one of the configured steps across [$min, $max].
	 *
	 * Note the asymmetry: the index is scaled by $steps but spread across $steps - 1 intervals, so the endpoints are
	 * both reachable. A step count of 0 means the axis is continuous, and 1 means it is pinned to the midpoint.
	 */
	private static function quantize(float $min, float $max, int $steps, float $random) : float{
		if($min >= $max){
			return $min;
		}
		if($steps === 1){
			return ($min + $max) * 0.5;
		}
		if($steps > 1){
			$index = (float) (int) ($steps * $random);
			return $min + $index * (($max - $min) / ($steps - 1));
		}
		return $min + ($max - $min) * $random;
	}

	/**
	 * Computes the horizontal (XZ) random offset for a block at the given position. The Y draw is consumed but
	 * unused, exactly as Bedrock does in XZ mode.
	 *
	 * @return float[] [$offsetX, $offsetZ]
	 * @phpstan-return array{float, float}
	 */
	public static function horizontal(int $x, int $z, float $min, float $max, int $steps) : array{
		$random = Xoroshiro128PP::fromSeed(self::positionHash($x, $z));

		$offsetX = self::quantize($min, $max, $steps, $random->nextFloat());
		$random->nextFloat(); //the Y axis draw is consumed even though its range is empty in XZ mode
		$offsetZ = self::quantize($min, $max, $steps, $random->nextFloat());

		return [$offsetX, $offsetZ];
	}
}
