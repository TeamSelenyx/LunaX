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

namespace pocketmine\resourcepacks;

use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\thread\Thread;
use function explode;
use function feof;
use function fclose;
use function fgets;
use function filesize;
use function fopen;
use function fread;
use function fwrite;
use function is_array;
use function is_file;
use function json_decode;
use function json_last_error;
use function ltrim;
use function parse_url;
use function stream_select;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_server;
use function trim;
use const JSON_ERROR_NONE;
use const PHP_URL_PATH;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;

/**
 * Serves resource pack files over plain HTTP, so they can be given to the client as CDN download URLs instead of
 * being transferred over the game connection. This avoids consuming the RakNet connection's bandwidth for large
 * packs and lets the client download them in parallel over an ordinary HTTP connection.
 */
class ResourcePackCdnServer extends Thread{
	private bool $ready = false;

	/**
	 * @param string $packFilesJson JSON-encoded map of URL path (usually the pack UUID) => absolute file path on disk
	 */
	public function __construct(
		private ThreadSafeLogger $logger,
		private string $bindAddress,
		private int $port,
		private string $packFilesJson
	){}

	public function startAndWait() : void{
		$this->start();
		$this->synchronized(function() : void{
			while(!$this->ready && !$this->isTerminated()){
				$this->wait();
			}
		});
	}

	protected function onRun() : void{
		\GlobalLogger::set($this->logger);

		$packFiles = json_decode($this->packFilesJson, true);
		if(!is_array($packFiles) || json_last_error() !== JSON_ERROR_NONE){
			$packFiles = [];
		}

		$socket = stream_socket_server("tcp://$this->bindAddress:$this->port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
		if($socket === false){
			$this->logger->critical("Failed to start resource pack CDN server on $this->bindAddress:$this->port: $errstr ($errno)");
			$this->synchronized(function() : void{
				$this->ready = true;
				$this->notify();
			});
			return;
		}
		stream_set_blocking($socket, false);

		$this->synchronized(function() : void{
			$this->ready = true;
			$this->notify();
		});

		while(!$this->isKilled){
			$read = [$socket];
			$write = $except = [];
			if(stream_select($read, $write, $except, 1) > 0){
				$conn = @stream_socket_accept($socket, 0);
				if($conn !== false){
					$this->handleConnection($conn, $packFiles);
				}
			}
		}
		fclose($socket);
	}

	/**
	 * @param resource              $conn
	 * @param array<string, string> $packFiles
	 */
	private function handleConnection($conn, array $packFiles) : void{
		stream_set_blocking($conn, true);
		stream_set_timeout($conn, 5);

		$requestLine = fgets($conn, 8192);
		if($requestLine === false){
			fclose($conn);
			return;
		}
		//consume and discard the rest of the request headers, we don't need them
		while(($line = fgets($conn, 8192)) !== false){
			if(trim($line) === ""){
				break;
			}
		}

		$parts = explode(" ", trim($requestLine));
		if(count($parts) < 2 || $parts[0] !== "GET"){
			fwrite($conn, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
			fclose($conn);
			return;
		}

		$path = ltrim((string) (parse_url($parts[1], PHP_URL_PATH) ?? ""), "/");
		$filePath = $packFiles[$path] ?? null;
		if($filePath === null || !is_file($filePath)){
			fwrite($conn, "HTTP/1.1 404 Not Found\r\nConnection: close\r\n\r\n");
			fclose($conn);
			return;
		}

		$size = filesize($filePath);
		if($size === false){
			fwrite($conn, "HTTP/1.1 500 Internal Server Error\r\nConnection: close\r\n\r\n");
			fclose($conn);
			return;
		}

		fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: $size\r\nConnection: close\r\n\r\n");
		$fp = fopen($filePath, "rb");
		if($fp !== false){
			while(!feof($fp)){
				$chunk = fread($fp, 65536);
				if($chunk === false){
					break;
				}
				fwrite($conn, $chunk);
			}
			fclose($fp);
		}
		fclose($conn);
	}

	public function getThreadName() : string{
		return "Resource Pack CDN";
	}
}
