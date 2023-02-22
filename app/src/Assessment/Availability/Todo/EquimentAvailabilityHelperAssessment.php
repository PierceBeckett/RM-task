<?php
namespace Assessment\Availability\Todo;

use Assessment\Availability\EquimentAvailabilityHelper;
use DateTime;
use PDO;

class EquimentAvailabilityHelperAssessment extends EquimentAvailabilityHelper {

	/**
	 * This function checks if a given quantity is available in the passed time frame
	 * @param int      $equipment_id Id of the equipment item
	 * @param int      $quantity How much should be available
	 * @param DateTime $start Start of time window
	 * @param DateTime $end End of time window
	 * @return bool True if available, false otherwise
	 */
	public function isAvailable(int $equipment_id, int $quantity, DateTime $start, DateTime $end) : bool {
		$pdo = $this->getDatabaseConnection();

		// verify parameters
		$items = $this->getEquipmentItems();
		if (!array_key_exists($equipment_id, $items)) {
			// equipment item does not exist
			return false;
		}

		// check for availability even if no planned checking quantity only
		if ($items[$equipment_id]['stock'] < $quantity) {
			return false;
		}

		// get all the 'moment groups' - various possible overlaps of planning times
		$moments = $this->getMomentsInRange($start, $end);

		// for each moment group get the count
		// array count less one as we dont use the last moment as a start
		for ($i=0; $i < count($moments)-1; $i++) {
			$moment_start = $moments[$i];
			$moment_end = $moments[$i+1];
			$used_sql = "SELECT sum(quantity)
						 FROM planning
						 WHERE equipment = $equipment_id AND start <= '$moment_end' AND end >='$moment_start'";
			$used = $pdo->query($used_sql)->fetchColumn();

			$availability = $items[$equipment_id]['stock'] - $used;
			if ($availability < $quantity) {
				// we have found a lack of availability
				// no need to continue checking
				return false;
			}
		}

		// all checks passed
		return true;
	}

	/**
	 * Calculate all items that are short in the given period
	 * @param DateTime $start Start of time window
	 * @param DateTime $end End of time window
	 * @return array Key/value array with as indices the equipment id's and as values the shortages
	 */
	public function getShortages(DateTime $start, DateTime $end) : array {

		$shortages = [];
		$pdo = $this->getDatabaseConnection();

		// get relevant moments
		$moments = $this->getMomentsInRange($start, $end);

		// check each moment for shortage
		for ($i=0; $i < count($moments)-1; $i++) {
			$moment_start = $moments[$i];
			$moment_end = $moments[$i+1];
			$shortage_sql = "SELECT equipment as p_eid, sum(quantity) as q_sum
						 	 FROM planning
							 WHERE start <= '$moment_end' AND end >='$moment_start'
							 GROUP BY equipment
							 HAVING q_sum > (SELECT stock FROM equipment WHERE equipment.id = p_eid)";
			$moment_shortages = array_column($pdo->query($shortage_sql)->fetchAll(PDO::FETCH_ASSOC),null,'p_eid');

			foreach ($moment_shortages as $m_shortage) {
				if (array_key_exists($m_shortage['p_eid'],$shortages)) {
					// add to existing count
					$shortages[$m_shortage['p_eid']] += (int) $m_shortage['q_sum'];
				} else {
					// initialise count for equipment
					$shortages[$m_shortage['p_eid']] = (int) $m_shortage['q_sum'];
				}
				// (assumed a count desired here? easy enough to make it an array with the date occurance that may be more informative)
				// along lines of
				// $shortages[$m_shortage['p_eid']][] = json_enconde([$m_shortage['date_info'], $m_shortage['q_sum']);
			}
		}

		return $shortages;
	}

	/**
	 * Provides a list of periods within planning schedule to group by overlaps
	 * 
	 * If the database grows very large, or queries cover extensive dates
	 * then would consider doing similar to this within SQL DB as some sort of recursive query
	 * (which would then be sued directly in the sql statements as a join)
	 * but need to weigh up the complexity and pay off before doing so
	 * 
	 * @param DateTime $start Date time of when to start the search
	 * @param DateTime $end Date time of when to end the search
	 * @return array The date range of the overlap
	 */
	private function getMomentsInRange(DateTime $start, DateTime $end) : array 
	{
		$pdo = $this->getDatabaseConnection();

		$start_formatted = $start->format(DATE_ISO8601);
		$end_formatted = $end->format(DATE_ISO8601);

		$moments_sql = "SELECT DISTINCT moment 
						FROM (
							SELECT start as moment
							FROM planning
							WHERE start BETWEEN '$start_formatted' AND '$end_formatted'
							UNION ALL
							SELECT end as moment
							FROM planning
							WHERE end BETWEEN '$start_formatted' AND '$end_formatted'
						) moments
						ORDER BY moment;
		";
		return $pdo->query($moments_sql)->fetchAll(PDO::FETCH_COLUMN);
	}


}
