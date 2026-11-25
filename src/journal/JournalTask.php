<?php

declare(strict_types=1);

namespace Trix\Tebex\journal;

use quark\scheduler\AsyncTask;
use Vow\Deferred;
use function count;
use function igbinary_serialize;
use function igbinary_unserialize;
use function is_array;
use function is_string;
use function time;

/**
 * Applies a batch of journal operations in one transaction on the journal worker.
 * @internal
 */
final class JournalTask extends AsyncTask{
	private const string DEFERRED = "deferred";

	/** @var array<string, \PDO> */
	private static array $connections = [];
	/** @var array<string, int> */
	private static array $prunedAt = [];

	private string $ops;

	/**
	 * @param list<list<mixed>> $ops
	 * @phpstan-param Deferred<list<mixed>> $deferred
	 */
	public function __construct(
		private string $path,
		private string $scope,
		private string $owner,
		array $ops,
		Deferred $deferred
	){
		$this->ops = (string) igbinary_serialize($ops);
		$this->storeLocal(self::DEFERRED, $deferred);
	}

	public function onRun() : void{
		try{
			/** @var list<list<mixed>> $ops */
			$ops = igbinary_unserialize($this->ops);
			$this->setResult(igbinary_serialize(self::apply(self::connect($this->path), $this->scope, $this->owner, $ops)));
		}catch(\Throwable $e){
			unset(self::$connections[$this->path]);
			$this->setResult($e->getMessage());
		}
	}

	public function onCompletion() : void{
		/** @phpstan-var Deferred<list<mixed>> $deferred */
		$deferred = $this->fetchLocal(self::DEFERRED);
		$result = $this->getResult();
		$decoded = is_string($result) ? @igbinary_unserialize($result) : null;
		if(is_array($decoded)){
			/** @var list<mixed> $decoded */
			$deferred->resolve($decoded);
		}else{
			$deferred->reject(new \RuntimeException("Journal write failed: " . (is_string($result) ? $result : "no result")));
		}
	}

	public static function connect(string $path) : \PDO{
		if(isset(self::$connections[$path])){
			return self::$connections[$path];
		}
		$db = new \PDO("sqlite:" . $path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 10]);
		$db->exec("PRAGMA journal_mode = WAL");
		$db->exec("PRAGMA synchronous = FULL");
		$db->exec("PRAGMA busy_timeout = 10000");
		$db->exec("CREATE TABLE IF NOT EXISTS commands(
			scope TEXT NOT NULL,
			id INTEGER NOT NULL,
			state INTEGER NOT NULL,
			due INTEGER NOT NULL DEFAULT 0,
			owner TEXT NOT NULL,
			updated INTEGER NOT NULL,
			detail TEXT NOT NULL DEFAULT '',
			PRIMARY KEY(scope, id)
		) WITHOUT ROWID");
		return self::$connections[$path] = $db;
	}

	/**
	 * @param list<list<mixed>> $ops
	 * @return list<mixed>
	 */
	public static function apply(\PDO $db, string $scope, string $owner, array $ops) : array{
		$now = time();
		$results = [];
		$db->exec("BEGIN IMMEDIATE");
		try{
			// Acknowledged commands are kept a while so late redeliveries are recognised, then dropped once a day.
			if((self::$prunedAt[$scope] ?? 0) <= $now - 86400){
				$db->prepare("DELETE FROM commands WHERE scope = ? AND state = ? AND updated < ?")->execute([$scope, Journal::ACKED, $now - 30 * 86400]);
				self::$prunedAt[$scope] = $now;
			}
			foreach($ops as $op){
				// Operations are built by Journal, their shapes match the handlers.
				$results[] = match($op[0]){
					"load" => self::load($db, $scope, $owner, $now),
					// @phpstan-ignore argument.type
					"claim" => self::claim($db, $scope, $owner, $now, $op[1]),
					// @phpstan-ignore argument.type
					"defer" => self::defer($db, $scope, $owner, $now, $op[1]),
					// @phpstan-ignore argument.type
					"put" => self::put($db, $scope, $owner, $now, $op[1]),
					// @phpstan-ignore argument.type
					"release" => self::release($db, $scope, $owner, $now, $op[1]),
					// @phpstan-ignore argument.type, argument.type
					"resolve" => self::resolve($db, $scope, $owner, $now, $op[1], $op[2]),
					// @phpstan-ignore argument.type
					"list" => self::list($db, $scope, $op[1]),
					default => throw new \InvalidArgumentException("Unknown journal operation")
				};
			}
			$db->exec("COMMIT");
		}catch(\Throwable $e){
			$db->exec("ROLLBACK");
			throw $e;
		}
		return $results;
	}

	/**
	 * Commands this server was executing when it stopped may or may not have run, so they are set aside for an admin.
	 * So are those another owner left running for ten minutes, which covers a server whose address and so owner changed.
	 *
	 * @return list<array{int, int, int}> id, state and due time of every unacknowledged command
	 */
	private static function load(\PDO $db, string $scope, string $owner, int $now) : array{
		$db->prepare("UPDATE commands SET state = ?, updated = ?, detail = 'interrupted while running: ' || detail WHERE scope = ? AND state = ? AND (owner = ? OR updated < ?)")
			->execute([Journal::QUARANTINED, $now, $scope, Journal::EXECUTING, $owner, $now - 600]);
		$select = $db->prepare("SELECT id, state, due FROM commands WHERE scope = ? AND state <> ? ORDER BY id");
		$select->execute([$scope, Journal::ACKED]);
		$rows = [];
		foreach($select->fetchAll(\PDO::FETCH_NUM) as [$id, $state, $due]){
			$rows[] = [(int) $id, (int) $state, (int) $due];
		}
		return $rows;
	}

	/**
	 * Marks commands as running. Only commands nobody else has started are claimed, the others report their state.
	 *
	 * @param list<array{int, string}> $commands id and description
	 * @return array{list<int>, array<int, int>} claimed ids, and the state of every other command
	 */
	private static function claim(\PDO $db, string $scope, string $owner, int $now, array $commands) : array{
		$statement = $db->prepare("INSERT INTO commands(scope, id, state, owner, updated, detail) VALUES(?, ?, ?, ?, ?, ?)
			ON CONFLICT(scope, id) DO UPDATE SET state = excluded.state, owner = excluded.owner, updated = excluded.updated, detail = excluded.detail
			WHERE commands.state = ?");
		$select = $db->prepare("SELECT state FROM commands WHERE scope = ? AND id = ?");
		$claimed = [];
		$others = [];
		foreach($commands as [$id, $detail]){
			$statement->execute([$scope, $id, Journal::EXECUTING, $owner, $now, $detail, Journal::PENDING]);
			if($statement->rowCount() === 1){
				$claimed[] = $id;
			}else{
				$select->execute([$scope, $id]);
				$others[$id] = (int) $select->fetchColumn();
			}
		}
		return [$claimed, $others];
	}

	/**
	 * Records when delayed commands become due, keeping the time of the first sighting across restarts.
	 *
	 * @param list<array{int, int}> $commands id and due time
	 * @return array<int, int> id => due time
	 */
	private static function defer(\PDO $db, string $scope, string $owner, int $now, array $commands) : array{
		$insert = $db->prepare("INSERT OR IGNORE INTO commands(scope, id, state, due, owner, updated) VALUES(?, ?, ?, ?, ?, ?)");
		$select = $db->prepare("SELECT due FROM commands WHERE scope = ? AND id = ?");
		$due = [];
		foreach($commands as [$id, $at]){
			$insert->execute([$scope, $id, Journal::PENDING, $at, $owner, $now]);
			$select->execute([$scope, $id]);
			$due[$id] = (int) $select->fetchColumn();
		}
		return $due;
	}

	/**
	 * @param list<array{int, int, ?string}> $changes id, state and an optional description
	 */
	private static function put(\PDO $db, string $scope, string $owner, int $now, array $changes) : int{
		$statement = $db->prepare("INSERT INTO commands(scope, id, state, owner, updated, detail) VALUES(?, ?, ?, ?, ?, ?)
			ON CONFLICT(scope, id) DO UPDATE SET state = excluded.state, updated = excluded.updated,
			detail = CASE WHEN ? IS NULL THEN commands.detail ELSE excluded.detail END");
		foreach($changes as [$id, $state, $detail]){
			$statement->execute([$scope, $id, $state, $owner, $now, $detail ?? "", $detail]);
		}
		return count($changes);
	}

	/**
	 * Returns claimed commands that did not run to the queue.
	 *
	 * @param list<int> $ids
	 */
	private static function release(\PDO $db, string $scope, string $owner, int $now, array $ids) : int{
		$statement = $db->prepare("UPDATE commands SET state = ?, updated = ? WHERE scope = ? AND id = ? AND owner = ? AND state = ?");
		$released = 0;
		foreach($ids as $id){
			$statement->execute([Journal::PENDING, $now, $scope, $id, $owner, Journal::EXECUTING]);
			$released += $statement->rowCount();
		}
		return $released;
	}

	/**
	 * Settles a quarantined command: delivered ones are acknowledged, retried ones run again.
	 */
	private static function resolve(\PDO $db, string $scope, string $owner, int $now, int $id, bool $delivered) : bool{
		$statement = $delivered ?
			$db->prepare("UPDATE commands SET state = " . Journal::EXECUTED . ", owner = ?, updated = ? WHERE scope = ? AND id = ? AND state = " . Journal::QUARANTINED) :
			$db->prepare("UPDATE commands SET state = " . Journal::PENDING . ", owner = ?, updated = ? WHERE scope = ? AND id = ? AND state = " . Journal::QUARANTINED);
		$statement->execute([$owner, $now, $scope, $id]);
		return $statement->rowCount() === 1;
	}

	/**
	 * @return list<array{int, int, string}> id, last change and description
	 */
	private static function list(\PDO $db, string $scope, int $state) : array{
		$select = $db->prepare("SELECT id, updated, detail FROM commands WHERE scope = ? AND state = ? ORDER BY id LIMIT 50");
		$select->execute([$scope, $state]);
		$rows = [];
		foreach($select->fetchAll(\PDO::FETCH_NUM) as [$id, $updated, $detail]){
			$rows[] = [(int) $id, (int) $updated, (string) $detail];
		}
		return $rows;
	}
}
