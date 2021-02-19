<?php

const DB_FILE = __DIR__ . '/stats.sqlite';

if (file_exists(__DIR__ . '/config.php')) {
	require __DIR__ . '/config.php';
}

class Stats
{
	public $open_for_bookings = false;
	public $has_availabilities = false;
	public $vaccinations_28d = null;
	public $available_slots_7d = 0;
	public $next_available_slot = null;
	public $uri = null;
	public $area;
	public $zipcode;
}

class DB extends \SQLite3
{
	public function do(string $sql, ...$params)
	{
		$st = $this->prepare($sql);

		foreach ($params as $key => $value) {
			if (is_numeric($key)) {
				$key += 1;
			}
			else {
				$key = ':' . $key;
			}

			$this->bind($st, $key, $value);
		}

		return $st->execute();
	}

	public function iterate(string $sql, ...$params): \Generator
	{
		$res = $this->do($sql, ...$params);

		while ($row = $res->fetchArray(\SQLITE3_ASSOC)) {
			yield (object) $row;
		}
	}

	public function get(string $sql, ...$params): array
	{
		$out = [];

		foreach ($this->iterate($sql, ...$params) as $row) {
			$out[] = $row;
		}

		return $out;
	}

	public function firstColumn(string $sql, ...$params)
	{
		$row = $this->do($sql, ...$params)->fetchArray(\SQLITE3_NUM);

		return $row[0] ?? false;
	}

	public function first(string $sql, ...$params): ?\stdClass
	{
		foreach ($this->iterate($sql, ...$params) as $row) {
			return $row;
		}

		return null;
	}

	public function replace(string $table, array $data)
	{
		return $this->insert($table, $data, 'INSERT OR REPLACE INTO');
	}

	public function insert(string $table, array $data, string $verb = 'INSERT INTO')
	{
		$sql = $verb . ' %s (%s) VALUES (%s);';
		$sql = sprintf($sql, $table, implode(', ', array_keys($data)), implode(', ', array_fill(0, count($data), '?')));
		$params = array_values($data);

		$this->do($sql, ...$params);
	}

	public function assertTable(string $name, array $fields, string $primary = null): void
	{
		$list = $this->get(sprintf('PRAGMA table_info(%s);', $name));

		if ($primary) {
			$primary = sprintf(', PRIMARY KEY (%s)', $primary);
		}

		if (!count($list)) {
			$sql = 'CREATE TABLE %s (%s %s);';
			$this->exec(sprintf($sql, $name, implode(', ', $fields), $primary));
			return;
		}

		$columns = [];

		foreach ($list as $row) {
			$columns[] = $row->name;
		}

		$diff = array_diff($fields, $columns);

		if (!count($diff)) {
			return;
		}

		$new_columns = $columns + $diff;

		$placeholders = [
			'%table' => $name,
			'%primary' => $primary,
			'%columns' => implode(', ', $columns),
			'%new_columns' => implode(', ', $new_columns),
		];

		$sql = 'ALTER TABLE %table RENAME TO %table_old;
			CREATE TABLE %table (%new_columns);
			INSERT INTO %table (%columns) SELECT %columns FROM %table_old;
			DROP TABLE %table_old;';

		$sql = strtr($sql, $placeholders);
		$this->exec($sql);
	}

	public function bind(\SQLite3Stmt $st, $name, $value)
	{
		list($type, $value) = $this->valueForBinding($value);
		$st->bindValue($name, $value, $type);
	}

	public function valueForBinding($value): array
	{
		switch (gettype($value)) {
			case 'boolean':
			case 'integer':
				return [\SQLITE3_INTEGER, (int) $value];
			case 'double':
				return [\SQLITE3_FLOAT, $value];
			case 'null':
				return [\SQLITE3_NULL, $value];
			default:
				return [\SQLITE3_TEXT, $value];
		}
	}
}

function http_get(string $url): ?string
{
	//debug("Fetch $url");
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

	if (defined('CURL_COOKIES')) {
		curl_setopt($ch, CURLOPT_COOKIE, CURL_COOKIES);
	}

	if (defined('CURL_USERAGENT')) {
		curl_setopt($ch, CURLOPT_USERAGENT, CURL_USERAGENT);
	}

	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($code !== 200) {
		debug('[ERR] Got HTTP code: ' . $code);
		return null;
	}

	return $body;
}

function http_get_json(string $url): ?\stdClass
{
	$body = http_get($url);

	if ($body === null) {
		debug('[ERR] Cannot fetch: ' . $url);
		exit;
		return null;
	}

	$body = json_decode($body);

	if ($body === null) {
		throw new \RuntimeException('Cannot decode JSON: ' . $url . "\n" . $body);
	}

	return $body;
}

function debug(string $str)
{
	echo $str . PHP_EOL;
}

