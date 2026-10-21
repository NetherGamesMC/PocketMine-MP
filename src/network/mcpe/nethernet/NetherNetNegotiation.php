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

use pocketmine\nethernet\protocol\ErrorCode;

/**
 * Handle for an SDP offer submitted through {@link NetherNetInterface::acceptOffer()}.
 */
final class NetherNetNegotiation{

	/** @phpstan-var list<\Closure(string) : void> */
	private array $onAnswer = [];

	/** @phpstan-var list<\Closure(ErrorCode, string) : void> */
	private array $onFailure = [];

	private ?string $answer = null;

	private ErrorCode $failureCode = ErrorCode::NONE;

	private ?string $failureReason = null;

	private bool $finished = false;

	private bool $cancelled = false;

	/**
	 * @internal Use {@link NetherNetInterface::acceptOffer()} to obtain an instance.
	 *
	 * @phpstan-param \Closure() : void $onCancel
	 */
	public function __construct(
		private string $networkId,
		private ?string $clientAddress,
		private \Closure $onCancel
	){}

	public function getNetworkId() : string{ return $this->networkId; }

	public function getClientAddress() : ?string{ return $this->clientAddress; }

	/**
	 * Registers completion callbacks for negotiation outcome. If the negotiation has already completed, the
	 * corresponding callback is invoked immediately.
	 *
	 * @phpstan-param \Closure(string $answerSdp) : void               $onAnswer
	 * @phpstan-param \Closure(ErrorCode $code, string $reason) : void $onFailure
	 */
	public function onCompletion(\Closure $onAnswer, \Closure $onFailure) : void{
		if($this->cancelled){
			return;
		}
		if($this->answer !== null){
			$onAnswer($this->answer);
			return;
		}
		if($this->failureReason !== null){
			$onFailure($this->failureCode, $this->failureReason);
			return;
		}

		$this->onAnswer[] = $onAnswer;
		$this->onFailure[] = $onFailure;
	}

	public function isFinished() : bool{ return $this->finished; }

	public function getAnswer() : ?string{ return $this->answer; }

	public function getFailureCode() : ErrorCode{ return $this->failureCode; }

	public function getFailureReason() : ?string{ return $this->failureReason; }

	public function isCancelled() : bool{ return $this->cancelled; }

	/**
	 * Cancels the negotiation. Has no effect if the negotiation has already finished.
	 */
	public function cancel() : void{
		if($this->finished){
			return;
		}
		$this->finished = true;
		$this->cancelled = true;
		$this->onAnswer = [];
		$this->onFailure = [];

		($this->onCancel)();
	}

	/**
	 * @internal
	 */
	public function resolve(string $answer) : void{
		if($this->finished){
			return;
		}
		$this->finished = true;
		$this->answer = $answer;

		$callbacks = $this->onAnswer;
		$this->onAnswer = [];
		$this->onFailure = [];
		foreach($callbacks as $callback){
			$callback($answer);
		}
	}

	/**
	 * @internal
	 */
	public function fail(ErrorCode $code, string $reason) : void{
		if($this->finished){
			return;
		}
		$this->finished = true;
		$this->failureCode = $code;
		$this->failureReason = $reason;

		$callbacks = $this->onFailure;
		$this->onAnswer = [];
		$this->onFailure = [];
		foreach($callbacks as $callback){
			$callback($code, $reason);
		}
	}
}
