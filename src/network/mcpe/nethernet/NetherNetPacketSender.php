<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use pocketmine\network\mcpe\PacketSender;

final class NetherNetPacketSender implements PacketSender{
	private bool $closed = false;

	public function __construct(private NetherNetInterface $interface, private int $sessionId){}

	public function send(string $payload, bool $immediate, ?int $receiptId) : void{
		if(!$this->closed){
			$this->interface->send($this->sessionId, $payload, $receiptId);
		}
	}

	public function close(string $reason = "unknown reason") : void{
		if(!$this->closed){
			$this->closed = true;
			$this->interface->close($this->sessionId);
		}
	}
}
