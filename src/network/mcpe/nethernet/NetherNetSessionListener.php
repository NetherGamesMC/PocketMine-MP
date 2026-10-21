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

use pocketmine\nethernet\identity\PeerIdentity;
use pocketmine\nethernet\protocol\DisconnectReason;
use pocketmine\nethernet\protocol\Reliability;
use pocketmine\nethernet\server\ServerEventListener;
use pocketmine\nethernet\utils\InternetAddress;

/**
 * Forwards everything the NetherNet server reports to the main thread.
 *
 * @internal
 */
final class NetherNetSessionListener implements ServerEventListener{

	/**
	 * Maximum bytes waiting for the main thread before packets stop being read from sessions.
	 */
	private const MAX_BACKLOG_SIZE = 4 * 1024 * 1024;

	/**
	 * Maximum messages waiting for the main thread before packets stop being read from sessions.
	 */
	private const MAX_BACKLOG_MESSAGES = 32768;

	private int $consumedBytes = 0;

	public function __construct(
		private readonly NetherNetChannel $out
	){}

	public function onSessionOpen(int $sessionId, string $networkId, InternetAddress $address, ?string $clientAddress, ?PeerIdentity $identity) : void{
		$this->out->write(NetherNetIpc::sessionOpen(
			$sessionId,
			//a client behind a relay or a reverse proxy is known by the address it signaled from, not the relay's
			$clientAddress ?? $address->ip,
			$address->port,
			$identity?->publicKey->getDigest() ?? ""
		));
	}

	public function onSessionClose(int $sessionId, DisconnectReason $reason) : void{
		$this->out->write(NetherNetIpc::sessionClose($sessionId, $reason->value));
	}

	public function canAcceptPackets() : bool{
		return $this->out->count() < self::MAX_BACKLOG_MESSAGES
			&& $this->out->getTotalBytes() - $this->consumedBytes < self::MAX_BACKLOG_SIZE;
	}

	public function setConsumedBytes(int $bytes) : void{
		$this->consumedBytes = $bytes;
	}

	public function onPacketReceive(int $sessionId, string $payload, Reliability $reliability) : void{
		$this->out->write(NetherNetIpc::packet($sessionId, $payload, $reliability));
	}

	public function onPacketAck(int $sessionId, int $receiptId) : void{
		$this->out->write(NetherNetIpc::receipt($sessionId, $receiptId));
	}

	public function onBandwidthStatsUpdate(int $bytesSentDiff, int $bytesReceivedDiff) : void{
		$this->out->write(NetherNetIpc::bandwidthStats($bytesSentDiff, $bytesReceivedDiff));
	}

	public function onPingMeasure(int $sessionId, int $pingMs) : void{
		$this->out->write(NetherNetIpc::ping($sessionId, $pingMs));
	}
}
