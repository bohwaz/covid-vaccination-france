<?php

require __DIR__ . '/functions.php';

$db = new DB(DB_FILE, \SQLITE3_OPEN_READONLY);

function list_current_stats(): \Generator
{
	global $db;

	$sql = 'SELECT
		area AS departement,
		SUM(available_slots_7d) AS available_slots_7d,
		SUM(vaccinations_28d) AS vaccinations_28d,
		next_available_slot AS next_slot
		FROM stats
		WHERE date = (SELECT MAX(date) FROM stats)
		GROUP BY area
		ORDER BY area;
	';

	foreach ($db->iterate($sql) as $row) {
		yield $row;
	}
}

$total = $db->first('SELECT SUM(available_slots_7d) AS available_slots_7d, SUM(vaccinations_28d) AS vaccinations_28d FROM stats WHERE date = (SELECT MAX(date) FROM stats);');

$date = $db->firstColumn('SELECT MAX(date) FROM stats;');

?>
<!DOCTYPE html>
<html>
<head>
	<title>Disponibilités centres vaccination Covid-19 France</title>
	<style type="text/css">
	thead.vertical th, thead.vertical td {
		writing-mode: vertical-rl;
		transform: rotate(180deg);
	}
	body {
		font-family: sans-serif;
	}

	table th, table td {
		text-align: center;
		padding: .2rem .5rem;
		border: 1px solid #999;
	}
	.ok {
		background: #cfc;
	}
	.nok {
		background: #fcc;
	}
	.not-bad {
		background: #ffc;
	}

	table {
		border-collapse: collapse;
	}
	</style>
</head>

<body>

<h2>Disponibilités au <?=htmlspecialchars($date)?></h2>

<table>
	<thead>
		<tr>
			<th>Département</th>
			<td>Disponibilités à J+7</td>
			<td>Vaccinations dans les 28 prochains jours</td>
			<td>Date de prochaine dispo.</td>
		</tr>
	</thead>
	<tbody>
		<?php foreach (list_current_stats() as $row): ?>
		<tr class="<?=($row->available_slots_7d ? 'ok' : ($row->next_slot ? 'not-bad' : 'nok'))?>">
			<th><?=sprintf('%02d', $row->departement)?></th>
			<td><?=intval($row->available_slots_7d)?></td>
			<td><?=intval($row->vaccinations_28d)?></td>
			<td><?=$row->next_slot?></td>
		</tr>
		<?php endforeach; ?>
	</tbody>
	<tfoot>
		<tr>
			<th>Total</th>
			<td><?=intval($total->available_slots_7d)?></td>
			<td><?=intval($total->vaccinations_28d)?></td>
			<td></td>
		</tr>
	</tfoot>
</table>

</body>
</html>