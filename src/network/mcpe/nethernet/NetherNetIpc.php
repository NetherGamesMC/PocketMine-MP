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

namespace pocketmine\network\mcpe\nethernet;

use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\VarInt;
use pocketmine\nethernet\protocol\Reliability;
use function chr;
use function strlen;

/**
 * @internal
 */
final class NetherNetIpc{

	//thread to main
	public const T2M_SESSION_OPEN = 0;
	public const T2M_PACKET = 1;
	public const T2M_SESSION_CLOSE = 2;
	public const T2M_RECEIPT = 3;
	public const T2M_BANDWIDTH_STATS = 4;
	public const T2M_PING = 5;
	public const T2M_OFFER_ANSWER = 6;
	public const T2M_OFFER_FAILURE = 7;
	public const T2M_UNRELIABLE_PACKET = 8;

	//main to thread
	public const M2T_SEND = 0;
	public const M2T_CLOSE_SESSION = 1;
	public const M2T_SET_SERVER_DATA = 2;
	public const M2T_BLOCK_ADDRESS = 3;
	public const M2T_UNBLOCK_ADDRESS = 4;
	public const M2T_OFFER = 5;
	public const M2T_CANCEL_OFFER = 6;
	public const M2T_SEND_UNRELIABLE = 7;

	private function __construct(){
		//NOOP
	}

	public static function setServerData(string $encodedServerInfo) : string{
		$out = new ByteBufferWriter();
		$out->writeByteArray(chr(self::M2T_SET_SERVER_DATA));
		$out->writeByteArray($encodedServerInfo);

		return $out->getData();
	}

	public static function sessionOpen(int $sessionId, string $address, int $port, string $publicKeyDigest) : string{
		$out = new ByteBufferWriter();
		$out->writeByteArray(chr(self::T2M_SESSION_OPEN));
		VarInt::writeUnsignedInt($out, $sessionId);
		VarInt::writeUnsignedInt($out, strlen($address));
		$out->writeByteArray($address);
		VarInt::writeUnsignedInt($out, $port);
		$out->writeByteArray($publicKeyDigest);

		return $out->getData();
	}

	public static function packet(int $sessionId, string $payload, Reliability $reliability) : string{
		$out = new ByteBufferWriter();
		$out->writeByteArray(chr($reliability === Reliability::RELIABLE ? self::T2M_PACKET : self::T2M_UNRELIABLE_PACKET));
		VarInt::writeUnsignedInt($out, $sessionId);
		$out->writeByteArray($payload);

		return $out->getData();
	}

	public static function sessionClose(int $sessionId, int $reason) : string{
		$out = new ByteBufferWriter();
		$out->writeByteArray(chr(self::T2M_SESSION_CLOSE));
		VarInt::writeUnsignedInt($out, $sessionId);
		VarInt::writeUnsignedInt($out, $reason);

		return $out->getData();
	}

	public static function receipt(int $sessionId, int $receiptId) : string{
		$out = new ByteBufferWriter();
		$out->writeByteArray(chr(self::T2M_RECEIPT));
		VarInt::writeUnsignedInt($out, $sessionId);
		VarInt::writeUnsignedInt($out, $receiptId);

		return $out->getData();
	}

	public static function bandwidthStats(int $bytesSentDiff, int $bytesReceivedDiff) : string{
		$out = new ByteBufferWriter();
		$out->writeByteArray(chr(self::T2M_BANDWIDTH_STATS));
		VarInt::writeUnsignedLong($out, $bytesSentDiff);
		VarInt::writeUnsignedLong($out, $bytesReceivedDiff);

		return $out->getData();
	}

	public static function ping(int $sessionId, int $pingMS) : string{
		$out = new ByteBufferWriter();
		$out->writeByteArray(chr(self::T2M_PING));
		VarInt::writeUnsignedInt($out, $sessionId);
		VarInt::writeUnsignedInt($out, $pingMS);

		return $out->getData();
	}

	public static function offerAnswer(int $requestId, string $answerSdp) : string{
		$out = new ByteBufferWriter();
		$out->writeByteArray(chr(self::T2M_OFFER_ANSWER));
		VarInt::writeUnsignedInt($out, $requestId);
		$out->writeByteArray($answerSdp);

		return $out->getData();
	}

	public static function offerFailure(int $requestId, int $errorCode, string $reason) : string{
		$out = new ByteBufferWriter();
		$out->writeByteArray(chr(self::T2M_OFFER_FAILURE));
		VarInt::writeUnsignedInt($out, $requestId);
		VarInt::writeUnsignedInt($out, $errorCode);
		$out->writeByteArray($reason);

		return $out->getData();
	}

	public static function send(int $sessionId, string $payload, ?int $receiptId, Reliability $reliability) : string{
		$out = new ByteBufferWriter();
		$out->writeByteArray(chr($reliability === Reliability::RELIABLE ? self::M2T_SEND : self::M2T_SEND_UNRELIABLE));
		VarInt::writeUnsignedInt($out, $sessionId);
		// 0 represents no receipt requested; valid receipt IDs are incremented by 1
		VarInt::writeUnsignedInt($out, $receiptId === null ? 0 : $receiptId + 1);
		$out->writeByteArray($payload);

		return $out->getData();
	}

	public static function closeSession(int $sessionId) : string{
		$out = new ByteBufferWriter();
		$out->writeByteArray(chr(self::M2T_CLOSE_SESSION));
		VarInt::writeUnsignedInt($out, $sessionId);

		return $out->getData();
	}

	public static function blockAddress(string $address, int $timeout) : string{
		$out = new ByteBufferWriter();
		$out->writeByteArray(chr(self::M2T_BLOCK_ADDRESS));
		VarInt::writeUnsignedInt($out, strlen($address));
		$out->writeByteArray($address);
		VarInt::writeSignedInt($out, $timeout);

		return $out->getData();
	}

	public static function unblockAddress(string $address) : string{
		$out = new ByteBufferWriter();
		$out->writeByteArray(chr(self::M2T_UNBLOCK_ADDRESS));
		VarInt::writeUnsignedInt($out, strlen($address));
		$out->writeByteArray($address);

		return $out->getData();
	}

	public static function offer(int $requestId, string $networkId, ?string $clientAddress, string $offerSdp) : string{
		$clientAddress ??= "";

		$out = new ByteBufferWriter();
		$out->writeByteArray(chr(self::M2T_OFFER));
		VarInt::writeUnsignedInt($out, $requestId);
		VarInt::writeUnsignedInt($out, strlen($networkId));
		$out->writeByteArray($networkId);
		VarInt::writeUnsignedInt($out, strlen($clientAddress));
		$out->writeByteArray($clientAddress);
		$out->writeByteArray($offerSdp);

		return $out->getData();
	}

	public static function cancelOffer(int $requestId) : string{
		$out = new ByteBufferWriter();
		$out->writeByteArray(chr(self::M2T_CANCEL_OFFER));
		VarInt::writeUnsignedInt($out, $requestId);

		return $out->getData();
	}
}
