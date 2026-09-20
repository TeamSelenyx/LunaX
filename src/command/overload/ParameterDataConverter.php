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

namespace pocketmine\command\overload;

use pocketmine\command\CommandSender;
use pocketmine\entity\Entity;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\World;
use function abs;
use function array_filter;
use function array_rand;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function fmod;
use function in_array;
use function is_array;
use function is_string;
use function method_exists;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function usort;

class ParameterDataConverter{

	private const REPEATABLE_FILTER_KEYS = ['name', 'type', 'family', 'tag', 'm'];

	private const BRACE_KEYS = ['scores', 'haspermission', 'hasitem'];

	private const DISTANCE_SORTED_TYPES = ['p', 'n'];

	private const FAMILY_CLASS_MAP = [
		\pocketmine\entity\Living::class => 'mob',
	];

	public CommandSender $sender;
	public string $targetArg;

	public function __construct(CommandSender $sender, string $targetArg){
		$this->sender = $sender;
		$this->targetArg = $targetArg;
	}

	/**
	 * @return array<int, Entity>|array{0: string}|null
	 */
	public function getTargetConverter() : ?array{
		if(!$this->isSelector()){
			return [$this->targetArg];
		}

		$selector = $this->parseSelector();
		if($selector === null){
			return null;
		}

		return $this->resolveSelector($selector);
	}

	/**
	 * @return Player[]|null
	 */
	public function getPlayerTargetConverter() : ?array{
		$result = $this->getTargetConverter();
		if($result === null){
			return null;
		}

		if(isset($result[0]) && is_string($result[0])){
			$target = Server::getInstance()->getPlayerByPrefix($result[0]);
			return $target !== null ? [$target] : null;
		}

		/** @var Entity[] $result */
		return array_values(array_filter($result, static fn(Entity $e) : bool => $e instanceof Player));
	}

	private function isSelector() : bool{
		return strlen($this->targetArg) >= 2 && $this->targetArg[0] === '@';
	}

	/**
	 * @return array{type: string, arguments: array<int, array{0: string, 1: string}>}|null
	 */
	private function parseSelector() : ?array{
		$selector = trim($this->targetArg);
		if(!preg_match('/^@([a-zA-Z])(?:\[(.*)])?$/s', $selector, $matches)){
			return null;
		}

		$type = strtolower($matches[1]);
		if(!in_array($type, ['a', 'e', 'p', 'r', 's', 'n'], true)){
			return null;
		}

		try{
			$arguments = isset($matches[2]) && $matches[2] !== '' ? $this->parseArguments($matches[2]) : [];
		}catch(\InvalidArgumentException){
			return null;
		}

		return ['type' => $type, 'arguments' => $arguments];
	}

	/**
	 * @return array<int, array{0: string, 1: string}>
	 * @throws \InvalidArgumentException
	 */
	private function parseArguments(string $content) : array{
		$pairs = [];
		$length = strlen($content);
		$i = 0;

		while($i < $length){
			while($i < $length && ($content[$i] === ' ' || $content[$i] === ',')){
				$i++;
			}
			if($i >= $length){
				break;
			}

			$keyStart = $i;
			while($i < $length && $content[$i] !== '='){
				$i++;
			}
			if($i >= $length){
				throw new \InvalidArgumentException("Expected '=' after argument name near '" . substr($content, $keyStart) . "'");
			}
			$key = trim(substr($content, $keyStart, $i - $keyStart));
			if($key === ""){
				throw new \InvalidArgumentException("Empty argument name in selector");
			}
			$i++;

			[$value, $i] = $this->readArgumentValue($content, $i, $length);
			$pairs[] = [$key, $value];
		}

		return $pairs;
	}

	/**
	 * @return array{0: string, 1: int}
	 * @throws \InvalidArgumentException
	 */
	private function readArgumentValue(string $content, int $i, int $length) : array{
		while($i < $length && $content[$i] === ' '){
			$i++;
		}
		$start = $i;

		if($i < $length && $content[$i] === '"'){
			$i++;
			while($i < $length && $content[$i] !== '"'){
				if($content[$i] === '\\' && $i + 1 < $length){
					$i++;
				}
				$i++;
			}
			if($i >= $length){
				throw new \InvalidArgumentException("Unterminated quoted string in selector argument");
			}
			$i++;
			return [$this->unquote(substr($content, $start, $i - $start)), $i];
		}

		if($i < $length && $content[$i] === '{'){
			$depth = 0;
			do{
				if($content[$i] === '{'){
					$depth++;
				}elseif($content[$i] === '}'){
					$depth--;
				}
				$i++;
			}while($i < $length && $depth > 0);
			if($depth !== 0){
				throw new \InvalidArgumentException("Unbalanced '{' in selector argument");
			}
			return [substr($content, $start, $i - $start), $i];
		}

		while($i < $length && $content[$i] !== ','){
			$i++;
		}
		return [rtrim(substr($content, $start, $i - $start)), $i];
	}

	private function unquote(string $raw) : string{
		$inner = substr($raw, 1, -1);
		return str_replace(['\\"', '\\\\'], ['"', '\\'], $inner);
	}

	/**
	 * @return array{0: ?float, 1: ?float}
	 */
	private function parseRange(string $raw) : array{
		$raw = trim($raw);
		if(str_contains($raw, "..")){
			$parts = explode("..", $raw, 2);
			$minStr = trim($parts[0]);
			$maxStr = trim($parts[1] ?? "");
			$min = $minStr === "" ? null : (float) $minStr;
			$max = $maxStr === "" ? null : (float) $maxStr;
			return [$min, $max];
		}
		if($raw === ""){
			return [null, null];
		}
		$value = (float) $raw;
		return [$value, $value];
	}

	/**
	 * @param array{0: ?float, 1: ?float} $range
	 */
	private function rangeContains(array $range, float $value) : bool{
		[$min, $max] = $range;
		if($min !== null && $value < $min){
			return false;
		}
		if($max !== null && $value > $max){
			return false;
		}
		return true;
	}

	/**
	 * @param array<string, string> $single
	 */
	private function resolveOrigin(array $single) : ?Vector3{
		$entityPos = $this->sender instanceof Entity ? $this->sender->getPosition() : null;

		$x = isset($single['x']) ? (float) $single['x'] : $entityPos?->getX();
		$y = isset($single['y']) ? (float) $single['y'] : $entityPos?->getY();
		$z = isset($single['z']) ? (float) $single['z'] : $entityPos?->getZ();

		if($x === null || $y === null || $z === null){
			return null;
		}

		return new Vector3($x, $y, $z);
	}

	/**
	 * @param array<string, string> $single
	 */
	private function requiresOrigin(string $type, array $single) : bool{
		if(in_array($type, self::DISTANCE_SORTED_TYPES, true)){
			return true;
		}
		if(isset($single['dx']) || isset($single['dy']) || isset($single['dz']) || isset($single['r']) || isset($single['rm'])){
			return true;
		}
		if(in_array($type, ['a', 'e'], true) && isset($single['c'])){
			return true;
		}
		return false;
	}

	/**
	 * @param array{type: string, arguments: array<int, array{0: string, 1: string}>} $selector
	 * @return Entity[]|null
	 */
	private function resolveSelector(array $selector) : ?array{
		if($selector['type'] === 's'){
			return $this->sender instanceof Entity ? [$this->sender] : null;
		}

		[$single, $filters, $braces] = $this->groupArguments($selector['arguments']);

		$requestedWorld = null;
		if(isset($single['world'])){
			$requestedWorld = Server::getInstance()->getWorldManager()->getWorldByName($single['world']);
			if($requestedWorld === null){
				return [];
			}
		}

		$origin = $this->resolveOrigin($single);
		if($origin === null){
			if($this->requiresOrigin($selector['type'], $single)){
				return null;
			}
			$origin = new Vector3(0.0, 0.0, 0.0);
		}

		$poolWorld = $requestedWorld ?? ($this->sender instanceof Entity ? $this->sender->getWorld() : null);
		$needsWorldPool = in_array($selector['type'], ['e', 'n'], true) || ($selector['type'] === 'r' && isset($filters['type']));
		if($needsWorldPool && $poolWorld === null){
			return null;
		}

		$server = Server::getInstance();

		$candidates = match($selector['type']){
			'a' => $server->getOnlinePlayers(),
			'p' => $server->getOnlinePlayers(),
			'e', 'n' => $poolWorld?->getEntities() ?? [],
			'r' => isset($filters['type']) ? ($poolWorld?->getEntities() ?? []) : $server->getOnlinePlayers(),
			default => [],
		};

		$predicates = array_filter([
			$this->makeWorldPredicate($requestedWorld),
			$this->makeVolumePredicate($single, $origin),
			$this->makeRadiusPredicate($single, $origin),
			$this->makeRotationPredicate($single),
			$this->makeLevelPredicate($single),
			$this->makeGameModePredicate($filters),
			$this->makeNamePredicate($filters),
			$this->makeTypePredicate($filters),
			$this->makeFamilyPredicate($filters),
			$this->makeTagPredicate($filters),
			$this->makeScoresPredicate($braces),
			$this->makeHasPermissionPredicate($braces),
			$this->makeHasItemPredicate($braces),
		]);

		if(count($predicates) > 0){
			$candidates = array_filter($candidates, static function(Entity $e) use ($predicates) : bool{
				foreach($predicates as $predicate){
					if(!$predicate($e)){
						return false;
					}
				}
				return true;
			});
		}

		return $this->applyCountAndSort(array_values($candidates), $selector['type'], $single, $origin);
	}

	/**
	 * @param array<int, array{0: string, 1: string}> $arguments
	 * @return array{0: array<string, string>, 1: array<string, list<array{negated: bool, value: string}>>, 2: array<string, string>}
	 */
	private function groupArguments(array $arguments) : array{
		$single = [];
		$filters = [];
		$braces = [];

		foreach($arguments as [$key, $rawValue]){
			$key = strtolower($key);

			if(in_array($key, self::BRACE_KEYS, true)){
				$inner = $rawValue;
				if(strlen($inner) >= 2 && $inner[0] === '{' && $inner[strlen($inner) - 1] === '}'){
					$inner = substr($inner, 1, strlen($inner) - 2);
				}
				$braces[$key] = $inner;
				continue;
			}

			if(in_array($key, self::REPEATABLE_FILTER_KEYS, true)){
				$negated = str_starts_with($rawValue, '!');
				$value = $negated ? substr($rawValue, 1) : $rawValue;
				$filters[$key][] = ['negated' => $negated, 'value' => trim($value)];
				continue;
			}

			$single[$key] = $rawValue;
		}

		return [$single, $filters, $braces];
	}

	/**
	 * @param list<array{negated: bool, value: string}> $entries
	 */
	private function matchesOrExcludeList(array $entries, string $actual, ?callable $normalizer = null) : bool{
		$positives = [];
		$negatives = [];
		foreach($entries as $entry){
			$value = $normalizer !== null ? $normalizer($entry['value']) : $entry['value'];
			if($entry['negated']){
				$negatives[] = $value;
			}else{
				$positives[] = $value;
			}
		}

		foreach($negatives as $neg){
			if($neg === $actual){
				return false;
			}
		}
		if(count($positives) > 0){
			return in_array($actual, $positives, true);
		}
		return true;
	}

	private function makeWorldPredicate(?World $world) : ?callable{
		if($world === null){
			return null;
		}
		return static function(Entity $e) use ($world) : bool{
			return $e->getWorld() === $world;
		};
	}

	/**
	 * @param array<string, string> $single
	 */
	private function makeVolumePredicate(array $single, Vector3 $origin) : ?callable{
		if(!isset($single['dx']) && !isset($single['dy']) && !isset($single['dz'])){
			return null;
		}
		$dx = isset($single['dx']) ? (float) $single['dx'] : 0.0;
		$dy = isset($single['dy']) ? (float) $single['dy'] : 0.0;
		$dz = isset($single['dz']) ? (float) $single['dz'] : 0.0;

		[$minX, $maxX] = $this->span($origin->getX(), $dx);
		[$minY, $maxY] = $this->span($origin->getY(), $dy);
		[$minZ, $maxZ] = $this->span($origin->getZ(), $dz);

		return static function(Entity $e) use ($minX, $maxX, $minY, $maxY, $minZ, $maxZ) : bool{
			$pos = $e->getPosition();
			return $pos->getX() >= $minX && $pos->getX() <= $maxX
				&& $pos->getY() >= $minY && $pos->getY() <= $maxY
				&& $pos->getZ() >= $minZ && $pos->getZ() <= $maxZ;
		};
	}

	/**
	 * @return array{0: float, 1: float}
	 */
	private function span(float $origin, float $delta) : array{
		$a = $origin;
		$b = $origin + $delta;
		return $a <= $b ? [$a, $b] : [$b, $a];
	}

	/**
	 * @param array<string, string> $single
	 */
	private function makeRadiusPredicate(array $single, Vector3 $origin) : ?callable{
		if(!isset($single['r']) && !isset($single['rm'])){
			return null;
		}
		$rMax = isset($single['r']) ? (float) $single['r'] : null;
		$rMin = isset($single['rm']) ? (float) $single['rm'] : null;

		return static function(Entity $e) use ($origin, $rMax, $rMin) : bool{
			$dist = $e->getPosition()->distance($origin);
			if($rMax !== null && $dist > $rMax){
				return false;
			}
			if($rMin !== null && $dist < $rMin){
				return false;
			}
			return true;
		};
	}

	/**
	 * @param array<string, string> $single
	 */
	private function makeRotationPredicate(array $single) : ?callable{
		if(!isset($single['rx']) && !isset($single['rxm']) && !isset($single['ry']) && !isset($single['rym'])){
			return null;
		}
		$rxMax = isset($single['rx']) ? (float) $single['rx'] : null;
		$rxMin = isset($single['rxm']) ? (float) $single['rxm'] : null;
		$ryMax = isset($single['ry']) ? (float) $single['ry'] : null;
		$ryMin = isset($single['rym']) ? (float) $single['rym'] : null;

		return function(Entity $e) use ($rxMax, $rxMin, $ryMax, $ryMin) : bool{
			$loc = $e->getLocation();
			$pitch = $loc->getPitch();
			$yaw = $this->normalizeYaw($loc->getYaw());
			if($rxMax !== null && $pitch > $rxMax){
				return false;
			}
			if($rxMin !== null && $pitch < $rxMin){
				return false;
			}
			if($ryMax !== null && $yaw > $ryMax){
				return false;
			}
			if($ryMin !== null && $yaw < $ryMin){
				return false;
			}
			return true;
		};
	}

	private function normalizeYaw(float $yaw) : float{
		$yaw = fmod($yaw + 180.0, 360.0);
		if($yaw < 0){
			$yaw += 360.0;
		}
		return $yaw - 180.0;
	}

	/**
	 * @param array<string, string> $single
	 */
	private function makeLevelPredicate(array $single) : ?callable{
		if(!isset($single['l']) && !isset($single['lm'])){
			return null;
		}
		$lMax = isset($single['l']) ? (int) $single['l'] : null;
		$lMin = isset($single['lm']) ? (int) $single['lm'] : null;

		return static function(Entity $e) use ($lMax, $lMin) : bool{
			if(!$e instanceof Player){
				return false;
			}
			$level = $e->getXpManager()->getXpLevel();
			if($lMax !== null && $level > $lMax){
				return false;
			}
			if($lMin !== null && $level < $lMin){
				return false;
			}
			return true;
		};
	}

	/**
	 * @param array<string, list<array{negated: bool, value: string}>> $filters
	 */
	private function makeGameModePredicate(array $filters) : ?callable{
		if(!isset($filters['m'])){
			return null;
		}
		$entries = $filters['m'];

		$positives = [];
		$negatives = [];
		foreach($entries as $entry){
			$wanted = GameMode::fromString($entry['value']);
			if($wanted === null){
				continue;
			}
			if($entry['negated']){
				$negatives[] = $wanted;
			}else{
				$positives[] = $wanted;
			}
		}

		return static function(Entity $e) use ($positives, $negatives) : bool{
			if(!$e instanceof Player){
				return false;
			}
			$actual = $e->getGamemode();
			if(in_array($actual, $negatives, true)){
				return false;
			}
			return count($positives) === 0 || in_array($actual, $positives, true);
		};
	}

	/**
	 * @param array<string, list<array{negated: bool, value: string}>> $filters
	 */
	private function makeNamePredicate(array $filters) : ?callable{
		if(!isset($filters['name'])){
			return null;
		}
		$entries = $filters['name'];

		return function(Entity $e) use ($entries) : bool{
			$name = $e instanceof Player ? $e->getName() : $e->getNameTag();
			return $this->matchesOrExcludeList($entries, strtolower($name), static fn(string $v) : string => strtolower($v));
		};
	}

	/**
	 * @param array<string, list<array{negated: bool, value: string}>> $filters
	 */
	private function makeTypePredicate(array $filters) : ?callable{
		if(!isset($filters['type'])){
			return null;
		}
		$entries = $filters['type'];

		return function(Entity $e) use ($entries) : bool{
			return $this->matchesOrExcludeList($entries, $this->typeIdOf($e), fn(string $v) => $this->normalizeTypeId($v));
		};
	}

	private function typeIdOf(Entity $e) : string{
		if($e instanceof Player){
			return 'minecraft:player';
		}
		if(method_exists($e, 'getNetworkTypeId')){
			return $this->normalizeTypeId($e->getNetworkTypeId());
		}
		$short = (new \ReflectionClass($e))->getShortName();
		return $this->normalizeTypeId($short);
	}

	private function normalizeTypeId(string $id) : string{
		$id = strtolower(trim($id));
		if(!str_contains($id, ':')){
			$id = 'minecraft:' . $id;
		}
		return $id;
	}

	/**
	 * @param array<string, list<array{negated: bool, value: string}>> $filters
	 */
	private function makeFamilyPredicate(array $filters) : ?callable{
		if(!isset($filters['family'])){
			return null;
		}
		$entries = $filters['family'];

		$positives = [];
		$negatives = [];
		foreach($entries as $entry){
			$value = strtolower($entry['value']);
			if($entry['negated']){
				$negatives[] = $value;
			}else{
				$positives[] = $value;
			}
		}

		return function(Entity $e) use ($positives, $negatives) : bool{
			$families = $this->familiesOf($e);
			foreach($negatives as $neg){
				if(in_array($neg, $families, true)){
					return false;
				}
			}
			if(count($positives) > 0){
				foreach($positives as $pos){
					if(in_array($pos, $families, true)){
						return true;
					}
				}
				return false;
			}
			return true;
		};
	}

	/**
	 * @return string[]
	 */
	private function familiesOf(Entity $e) : array{
		$families = [];
		if($e instanceof Player){
			$families[] = 'player';
		}
		foreach(self::FAMILY_CLASS_MAP as $class => $family){
			if($e instanceof $class){
				$families[] = $family;
			}
		}
		return $families;
	}

	/**
	 * @param array<string, list<array{negated: bool, value: string}>> $filters
	 */
	private function makeTagPredicate(array $filters) : ?callable{
		if(!isset($filters['tag'])){
			return null;
		}
		$entries = $filters['tag'];

		$requiresEmpty = false;
		$positives = [];
		$negatives = [];
		foreach($entries as $entry){
			if(!$entry['negated'] && $entry['value'] === ''){
				$requiresEmpty = true;
				continue;
			}
			if($entry['negated']){
				$negatives[] = $entry['value'];
			}else{
				$positives[] = $entry['value'];
			}
		}

		return function(Entity $e) use ($requiresEmpty, $positives, $negatives) : bool{
			$tags = $this->tagsOf($e);
			if($requiresEmpty){
				return count($tags) === 0;
			}
			foreach($negatives as $neg){
				if(in_array($neg, $tags, true)){
					return false;
				}
			}
			foreach($positives as $pos){
				if(!in_array($pos, $tags, true)){
					return false;
				}
			}
			return true;
		};
	}

	/**
	 * @return string[]
	 */
	private function tagsOf(Entity $e) : array{
		if(method_exists($e, 'getSelectorTags')){
			return $e->getSelectorTags();
		}
		$tag = $e->getScoreTag();
		return $tag !== "" ? [$tag] : [];
	}

	/**
	 * @param array<string, string> $braces
	 */
	private function makeScoresPredicate(array $braces) : ?callable{
		if(!isset($braces['scores']) || trim($braces['scores']) === ''){
			return null;
		}
		$ranges = [];
		foreach($this->parseArguments($braces['scores']) as [$objective, $rangeRaw]){
			$ranges[$objective] = $this->parseRange($rangeRaw);
		}

		return function(Entity $e) use ($ranges) : bool{
			if(!method_exists($e, 'getSelectorScore')){
				return false;
			}
			foreach($ranges as $objective => $range){
				$score = $e->getSelectorScore($objective);
				if($score === null || !$this->rangeContains($range, (float) $score)){
					return false;
				}
			}
			return true;
		};
	}

	/**
	 * @param array<string, string> $braces
	 */
	private function makeHasPermissionPredicate(array $braces) : ?callable{
		if(!isset($braces['haspermission']) || trim($braces['haspermission']) === ''){
			return null;
		}
		$pairs = $this->parseArguments($braces['haspermission']);

		return static function(Entity $e) use ($pairs) : bool{
			if(!$e instanceof Player){
				return false;
			}
			foreach($pairs as [$permission, $state]){
				$expected = strtolower(trim($state)) === 'enabled';
				if($e->hasPermission($permission) !== $expected){
					return false;
				}
			}
			return true;
		};
	}

	/**
	 * @param array<string, string> $braces
	 */
	private function makeHasItemPredicate(array $braces) : ?callable{
		if(!isset($braces['hasitem']) || trim($braces['hasitem']) === ''){
			return null;
		}
		$spec = [];
		foreach($this->parseArguments($braces['hasitem']) as [$k, $v]){
			$spec[strtolower($k)] = $v;
		}
		$wantedItem = isset($spec['item']) ? strtolower($this->normalizeTypeId($spec['item'])) : null;
		$quantityRange = isset($spec['quantity']) ? $this->parseRange($spec['quantity']) : null;

		return function(Entity $e) use ($wantedItem, $quantityRange) : bool{
			if(!method_exists($e, 'getInventory')){
				return false;
			}
			$inventory = $e->getInventory();
			if($inventory === null){
				return false;
			}
			$total = 0;
			foreach($inventory->getContents() as $item){
				if($wantedItem !== null && !$this->matchesItemIdentifier($item, $wantedItem)){
					continue;
				}
				$total += $item->getCount();
			}
			if($total === 0){
				return false;
			}
			return $quantityRange === null || $this->rangeContains($quantityRange, (float) $total);
		};
	}

	private function matchesItemIdentifier(mixed $item, string $wantedNormalized) : bool{
		if(method_exists($item, 'getVanillaName')){
			return $this->normalizeTypeId((string) $item->getVanillaName()) === $wantedNormalized;
		}
		if(method_exists($item, 'getName')){
			return $this->normalizeTypeId(str_replace(' ', '_', (string) $item->getName())) === $wantedNormalized;
		}
		return false;
	}

	/**
	 * @param Entity[] $candidates
	 * @param array<string, string> $single
	 * @return Entity[]
	 */
	private function applyCountAndSort(array $candidates, string $baseType, array $single, Vector3 $origin) : array{
		$count = isset($single['c']) ? (int) $single['c'] : null;

		switch($baseType){
			case 'p':
			case 'n':
				$count ??= 1;
				$candidates = $this->sortByDistance($candidates, $origin, $count >= 0);
				return array_slice($candidates, 0, abs($count));

			case 'r':
				$count = abs($count ?? 1);
				if(count($candidates) <= $count){
					return $candidates;
				}
				$keys = array_rand($candidates, $count);
				$keys = is_array($keys) ? $keys : [$keys];
				$picked = [];
				foreach($keys as $k){
					$picked[] = $candidates[$k];
				}
				return $picked;

			case 'a':
			case 'e':
				if($count === null){
					return $candidates;
				}
				$candidates = $this->sortByDistance($candidates, $origin, $count >= 0);
				return array_slice($candidates, 0, abs($count));

			default:
				return $candidates;
		}
	}

	/**
	 * @param Entity[] $candidates
	 * @return Entity[]
	 */
	private function sortByDistance(array $candidates, Vector3 $origin, bool $nearestFirst) : array{
		usort($candidates, static function(Entity $a, Entity $b) use ($origin, $nearestFirst) : int{
			$da = $a->getPosition()->distanceSquared($origin);
			$db = $b->getPosition()->distanceSquared($origin);
			return $nearestFirst ? ($da <=> $db) : ($db <=> $da);
		});
		return $candidates;
	}
}