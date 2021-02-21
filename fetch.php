<?php

// https://www.data.gouv.fr/fr/datasets/r/d0566522-604d-4af6-be44-a26eefa01756
// https://www.sante.fr/cf/centres-vaccination-covid.html
const CENTRES_URL = 'https://www.data.gouv.fr/fr/datasets/r/d0566522-604d-4af6-be44-a26eefa01756';

require __DIR__ . '/functions.php';

update_centres();
update_stats();

function update_centres(): void
{
	$centres = http_get_json(CENTRES_URL);

	if (!isset($centres->features[0]->properties)) {
		throw new \RuntimeException('Invalid centres JSON file');
	}

	$db = new DB(DB_FILE);
	$db->exec('BEGIN;');

	$schema = false;

	foreach ($centres->features as $feature) {
		$centre = build_centre($feature);
		$data = get_object_vars($centre);

		if (!$schema) {
			debug('Creating centres tables');
			$db->assertTable('centres', array_keys($data), 'gid');
			$schema = true;
		}

		debug('Updating centre: ' . $centre->nom);
		$db->replace('centres', $data);
	}

	$db->exec('END;');
	$db->close();
}

function update_stats(): void
{
	$centres = [];

	$db = new DB(DB_FILE);
	$db->exec('BEGIN;');

	$i = 0;

	foreach ($db->iterate('SELECT * FROM centres;') AS $centre) {
		$uri = get_provider_uri($centre);

		if (!$uri) {
			continue;
		}

		$centres[$uri] = true;
	}

	$schema = false;

	foreach ($centres as $uri => $_ignore) {
		$find_uri = preg_replace('/\?.*$/', '?', $uri);

		if ($db->firstColumn('SELECT 1 FROM stats WHERE uri LIKE ? AND date = date();', $find_uri . '%')) {
			debug('Already got: ' . $find_uri);
			continue;
		}

		foreach (get_availability($uri) as $stats) {
			debug('Inserting stats: ' . print_r($stats, true));
			$data = get_object_vars($stats);
			$data['date'] = date('Y-m-d');

			if (!$schema) {
				$db->assertTable('stats', array_keys($data));
				$schema = true;
			}

			$db->insert('stats', $data);
		}

		if ($i++ % 50 == 0) {
			$db->exec('END; BEGIN;');
		}
	}

	$db->exec('END;');
	$db->close();
}

function build_centre(\stdClass $feature): \stdClass
{
	$out = [];

	foreach (get_object_vars($feature->properties) as $key => $value) {
		if (substr($key, 0, 2) !== 'c_') {
			continue;
		}

		$key = strtolower(substr($key, 2));
		$out[$key] = $value;
	}

	$out['lon'] = $feature->geometry->coordinates[0][0];
	$out['lat'] = $feature->geometry->coordinates[0][1];

	return (object) $out;
}

function get_availability(string $uri): \Generator
{
	$parts = explode('://', $uri, 2);

	switch ($parts[0])
	{
		case 'doctolib':
			return get_availability_doctolib($parts[1]);
		default:
			echo 'Unknown provider! ' . $uri . PHP_EOL;
	}
}


function get_provider_uri(\stdClass $centre): ?string
{
	if (empty($centre->rdv_site_web)) {
		return null;
	}

	$url = parse_url($centre->rdv_site_web);

	if (!isset($url['host'], $url['path'])) {
		return null;
	}

	$url['host'] = str_replace('www.doctolib.fr', 'partners.doctolib.fr', $url['host']);

	switch ($url['host'])
	{
		case 'partners.doctolib.fr':
			return 'doctolib://' . trim(basename($url['path']));
		default:
			echo 'Unknown provider: ' . $centre->rdv_site_web . PHP_EOL;
			return null;
	}
}

function get_availability_doctolib(string $id): \Generator
{
	// https://partners.doctolib.fr/hopital-public/charleville-mezieres/centre-hospitalier-intercommunal-nord-ardennes-charleville-fumay-sedan?pid=practice-164143&enable_cookies_consent=1
	// https://partners.doctolib.fr/booking/cpts-de-dijon-vaccination-covid-19.json

	$url = sprintf('https://partners.doctolib.fr/booking/%s.json', trim($id));
	$result = http_get_json($url);

	// Wait a bit
	sleep(1);

	if (!$result) {
		return null;
	}

	$stats = new Stats;
	$stats->uri = 'doctolib://' . $id;
	$motives = [];

	foreach ($result->data->visit_motives as $motive) {
		// Ignore anything that is not a first shot motive
		if (empty($motive->first_shot_motive)) {
			continue;
		}

		$motives[] = $motive->id;
	}

	foreach ($result->data->places as $place) {
		$stats->zipcode = (int) substr($place->zipcode, 0, 5);
		$stats->area = (int) substr($place->zipcode, 0, 2);

		// No motives: the place is closed
		if (!count($motives)) {
			$stats->vaccinations_28d = (int) $result->data->number_future_vaccinations;
			yield $stats;
			return;
		}

		foreach ($place->practice_ids as $pid) {
			$agendas = doctolib_get_agendas_for_practice($result, $motives, (int) $pid);

			if (!count($agendas)) {
				continue;
			}

			$av = doctolib_fetch_availabilities($agendas, $motives, (int)$pid, $result);

			$stats2 = clone $stats;

			foreach ($av as $key => $value) {
				$stats2->$key = $value;
			}

			$stats2->uri .= '?pid=' . (int)$pid;

			yield $stats2;
		}
	}
}

function doctolib_fetch_availabilities(array $open_agendas, array $motives, int $pid, \stdClass $result): ?array
{
	$params = [
		'start_date' => date('Y-m-d'),
		'visit_motive_ids' => implode('-', $motives),
		'agenda_ids' => implode('-', $open_agendas),
		'insurance_sector' => 'public',
		'practice_ids' => $pid,
		'destroy_temporary' => 'true',
		'limit' => 7,
		'allowNewPatients' => 'true',
		'telehealth' => 'false',
		'profileId' => $result->data->profile->id,
		'isOrganization' => $result->data->profile->organization ? 'true' : 'false',
		'telehealthFeatureEnabled' => 'false',
		'vaccinationMotive' => 'true',
		'vaccinationDaysRange' => 26,
		'vaccinationCenter' => 'true',
		'nbConfirmedVaccinationAppointments' => $result->data->number_future_vaccinations,
	];

	$url = sprintf('https://partners.doctolib.fr/availabilities.json?%s', http_build_query($params));
	$r = http_get_json($url);

	if (!$r) {
		return null;
	}

	return [
		'has_availabilities' => $r->total && count($r->availabilities) > 0,
		'available_slots_7d' => (int) $r->total,
		'next_available_slot' => isset($r->next_slot) ? $r->next_slot : null,
		'vaccinations_28d' => $r->number_future_vaccinations ?? null,
	];
}

function doctolib_get_agendas_for_practice(\stdClass $result, array $motives, int $pid): array
{
	$open_agendas = [];

	foreach ($result->data->agendas as $agenda) {
		if ($agenda->booking_disabled) {
			continue;
		}

		// Skip if practice does not match
		if ($agenda->practice_id != $pid) {
			continue;
		}

		$motives_by_practice = (array) $agenda->visit_motive_ids_by_practice_id;

		// Skip if motives for this practice are empty
		if (!count($motives_by_practice[$pid])) {
			continue;
		}

		// Check that this agenda matches our motives
		if (count($motives) != count(array_intersect($motives_by_practice[$pid], $motives))) {
			continue;
		}

		$open_agendas[] = $agenda->id;
	}

	return $open_agendas;
}
