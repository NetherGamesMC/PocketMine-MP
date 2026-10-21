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

use pocketmine\nethernet\negotiation\Negotiation;
use pocketmine\nethernet\negotiation\NegotiationException;
use pocketmine\nethernet\protocol\ErrorCode;
use pocketmine\nethernet\signaling\Signaling;
use pocketmine\nethernet\signaling\SignalingContext;
use function count;
use function microtime;

/**
 * Signaling transport that processes offers forwarded from the main thread via IPC ({@link NetherNetInterface::acceptOffer()}).
 *
 * @internal
 */
final class NetherNetIpcSignaling implements Signaling{

	public const NEGOTIATION_TIMEOUT = 20.0;
	public const MAX_PENDING_OFFERS = 256;

	/** @phpstan-var array<int, array{Negotiation, float}> */
	private array $pending = [];

	/** Set once the server starts this transport; offers cannot be accepted before that. */
	private ?SignalingContext $context = null;

	private bool $closed = false;

	public function __construct(
		private readonly NetherNetChannel $out,
		private readonly \Logger $logger,
		private readonly float $negotiationTimeout = self::NEGOTIATION_TIMEOUT
	){}

	public function describe() : string{
		return "NetherNet IPC signaling";
	}

	public function start(SignalingContext $context) : void{
		$this->context = $context;
	}

	public function acceptOffer(int $requestId, string $networkId, string $offerSdp, ?string $clientAddress) : void{
		$context = $this->context;
		if($this->closed || $context === null){
			$this->fail($requestId, ErrorCode::NO_SIGNALING_CHANNEL, "NetherNet is not running");
			return;
		}
		if($clientAddress !== null && $context->blockList->isBlocked($clientAddress)){
			$this->fail($requestId, ErrorCode::INCOMING_CONNECTION_IGNORED, "Address $clientAddress is blocked");
			return;
		}
		if(count($this->pending) >= self::MAX_PENDING_OFFERS){
			$this->fail($requestId, ErrorCode::INCOMING_CONNECTION_IGNORED, "Too many offers are waiting for an answer");
			return;
		}

		try{
			$negotiation = $context->negotiator->beginNegotiation($offerSdp, $networkId, peerAddress: $clientAddress);
		}catch(NegotiationException $e){
			$this->fail($requestId, $e->getErrorCode(), $e->getMessage());
			return;
		}

		$this->pending[$requestId] = [$negotiation, microtime(true) + $this->negotiationTimeout];
	}

	public function cancel(int $requestId) : void{
		$pending = $this->pending[$requestId] ?? null;
		if($pending === null){
			return;
		}
		unset($this->pending[$requestId]);

		$pending[0]->fail("Cancelled by the plugin that accepted the offer", ErrorCode::INCOMING_CONNECTION_IGNORED);
	}

	public function tick(float $now) : void{
		if($this->closed){
			return;
		}

		foreach($this->pending as $requestId => [$negotiation, $deadline]){
			$answer = $negotiation->getAnswer();
			if($answer !== null){
				unset($this->pending[$requestId]);
				$this->out->write(NetherNetIpc::offerAnswer($requestId, $answer));
				continue;
			}
			if($negotiation->isFailed()){
				unset($this->pending[$requestId]);
				$this->fail($requestId, $negotiation->getFailureCode(), $negotiation->getFailureReason() ?? "no reason given");
				continue;
			}
			if($now > $deadline){
				unset($this->pending[$requestId]);
				$negotiation->fail("Timed out producing an answer", ErrorCode::NEGOTIATION_TIMEOUT);
				$this->fail($requestId, ErrorCode::NEGOTIATION_TIMEOUT, "Timed out producing an answer");
			}
		}
	}

	public function shutdown() : void{
		if($this->closed){
			return;
		}
		$this->closed = true;

		foreach($this->pending as $requestId => [$negotiation]){
			$negotiation->fail("NetherNet is shutting down", ErrorCode::NO_SIGNALING_CHANNEL);
			$this->fail($requestId, ErrorCode::NO_SIGNALING_CHANNEL, "NetherNet is shutting down");
		}
		$this->pending = [];
	}

	private function fail(int $requestId, ErrorCode $code, string $reason) : void{
		$this->logger->debug("Offer $requestId from the main thread failed: $reason");
		$this->out->write(NetherNetIpc::offerFailure($requestId, $code->value, $reason));
	}
}
