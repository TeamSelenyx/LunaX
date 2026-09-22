<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use pocketmine\lang\KnownTranslationFactory;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\Network;
use pocketmine\network\NetworkInterface;
use pocketmine\network\NetworkInterfaceStartException;
use pocketmine\network\PacketHandlingException;
use pocketmine\Server;

/**
 * Receives Bedrock packet batches from the local NetherNet WebRTC sidecar.
 * The IPC is a TCP stream of network-order uint32 lengths and raw batch bodies.
 */
final class NetherNetInterface implements NetworkInterface{
	private const MAX_FRAME_SIZE = 8 * 1024 * 1024;

	/** @var resource|null */
	private $listener = null;
	/** @var array<int, resource> */
	private array $sockets = [];
	/** @var array<int, string> */
	private array $input = [];
	/** @var array<int, string> */
	private array $output = [];
	/** @var array<int, NetworkSession> */
	private array $sessions = [];
	private int $nextSessionId = 1;

	public function __construct(
		private Server $server,
		private Network $network,
		private int $port,
		private PacketBroadcaster $packetBroadcaster,
		private EntityEventBroadcaster $entityEventBroadcaster,
		private TypeConverter $typeConverter
	){}

	public function start() : void{
		$listener = @stream_socket_server("tcp://127.0.0.1:$this->port", $errno, $error);
		if($listener === false){
			throw new NetworkInterfaceStartException("NetherNet IPC listener failed: $error ($errno)");
		}
		stream_set_blocking($listener, false);
		$this->listener = $listener;
		$this->server->getLogger()->info("NetherNet IPC listener running on 127.0.0.1:$this->port");
	}

	public function setName(string $name) : void{}

	public function tick() : void{
		if($this->listener !== null){
			while(($socket = @stream_socket_accept($this->listener, 0)) !== false){
				stream_set_blocking($socket, false);
				$id = $this->nextSessionId++;
				$this->sockets[$id] = $socket;
				$this->input[$id] = "";
				$this->output[$id] = "";
				$this->sessions[$id] = new NetworkSession(
					$this->server,
					$this->network->getSessionManager(),
					PacketPool::getInstance(),
					new NetherNetPacketSender($this, $id),
					$this->packetBroadcaster,
					$this->entityEventBroadcaster,
					ZlibCompressor::getInstance(),
					$this->typeConverter,
					"127.0.0.1",
					$this->port
				);
			}
		}

		foreach($this->sockets as $id => $socket){
			$read = @fread($socket, 65536);
			if($read !== false && $read !== ""){
				$this->input[$id] .= $read;
				$this->processInput($id);
			}
			if(!isset($this->sockets[$id])){
				continue;
			}
			if($this->output[$id] !== ""){
				$sent = @fwrite($socket, $this->output[$id]);
				if($sent === false){
					$this->disconnect($id);
					continue;
				}
				$this->output[$id] = substr($this->output[$id], $sent);
			}
			if(feof($socket)){
				$this->disconnect($id);
			}
		}
	}

	private function processInput(int $id) : void{
		while(isset($this->sessions[$id]) && strlen($this->input[$id]) >= 4){
			$lengthBytes = unpack("N", $this->input[$id]);
			if($lengthBytes === false){
				throw new \LogicException("Failed to unpack NetherNet IPC frame length");
			}
			$length = $lengthBytes[1];
			if($length < 1 || $length > self::MAX_FRAME_SIZE){
				$this->sessions[$id]->getLogger()->warning("Invalid NetherNet IPC frame length $length");
				$this->disconnect($id);
				return;
			}
			if(strlen($this->input[$id]) < 4 + $length){
				return;
			}
			$payload = substr($this->input[$id], 4, $length);
			$this->input[$id] = substr($this->input[$id], 4 + $length);
			try{
				$this->sessions[$id]->handleEncoded($payload);
			}catch(PacketHandlingException $e){
				$this->sessions[$id]->disconnectWithError("Bad NetherNet packet: " . $e->getMessage());
			}
		}
	}

	public function send(int $id, string $payload, ?int $receiptId) : void{
		if(isset($this->sockets[$id])){
			$this->output[$id] .= pack("N", strlen($payload)) . $payload;
			if($receiptId !== null){
				$this->sessions[$id]->handleAckReceipt($receiptId);
			}
		}
	}

	public function close(int $id) : void{
		if(isset($this->sockets[$id])){
			fclose($this->sockets[$id]);
			unset($this->sockets[$id], $this->input[$id], $this->output[$id], $this->sessions[$id]);
		}
	}

	private function disconnect(int $id) : void{
		$session = $this->sessions[$id] ?? null;
		$this->close($id);
		$session?->onClientDisconnect(KnownTranslationFactory::pocketmine_disconnect_clientDisconnect());
	}

	public function shutdown() : void{
		foreach(array_keys($this->sockets) as $id){
			$this->disconnect($id);
		}
		if($this->listener !== null){
			fclose($this->listener);
			$this->listener = null;
		}
	}
}
