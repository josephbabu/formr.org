<?php

class RunSession
{
	public $session = null;
	public $id, $run_id, $ended, $position;
	private $dbh;
	
	public function __construct($fdb, $run_id, $user_id, $session)
	{
		$this->dbh = $fdb;
		$this->session = $session;
		$this->run_id = $run_id;
		$this->user_id = $user_id;
		
		if($this->session != null AND $this->run_id != null): // called with null in constructor if they have no session yet
			$this->load();
		endif;
	}
	public function create($session = NULL)
	{
		if($session !== NULL)
		{
			if(strlen($session)!=64)
			{
				alert("<strong>Error.</strong> Session tokens need to be exactly 64 characters long.",'alert-error');
				return false;
			}
		}
		else
			$session = bin2hex(openssl_random_pseudo_bytes(32));
		
		$session_q = "INSERT IGNORE INTO  `survey_run_sessions`
		SET run_id = :run_id,
		session = :session,
		user_id = :user_id,
		created = NOW()
		";
		$add_session = $this->dbh->prepare($session_q) or die(print_r($this->dbh->errorInfo(), true));
	
		$add_session->bindParam(":session",$session);
		$add_session->bindParam(":run_id", $this->run_id);
		$add_session->bindParam(":user_id", $this->user_id);
	
		$add_session->execute() or die(print_r($add_session->errorInfo(), true));
		
#		$add_session->closeCursor();
		if($add_session->rowCount()===1)
		{
			$this->session = $session;
			$this->load();
			return true;
		}
		return false;
	}
	private function load()
	{
		$session_q = "SELECT 
			`survey_run_sessions`.id, 
			`survey_run_sessions`.session, 
			`survey_run_sessions`.user_id, 
			`survey_run_sessions`.run_id, 
			`survey_run_sessions`.created, 
			`survey_run_sessions`.ended, 
			`survey_run_sessions`.position, 
			`survey_runs`.name AS run_name
			  FROM  `survey_run_sessions`
			LEFT JOIN `survey_runs`
		ON `survey_runs`.id = `survey_run_sessions`.run_id 
		WHERE 
		run_id = :run_id AND
		session = :session
		LIMIT 1;";
	
		$valid_session = $this->dbh->prepare($session_q) or die(print_r($dbh->errorInfo(), true));
	
		$valid_session->bindParam(":session",$this->session);
		$valid_session->bindParam(":run_id", $this->run_id);
	
		$valid_session->execute() or die(print_r($valid_session->errorInfo(), true));
		$valid = $valid_session->rowCount();
		$sess_array = $valid_session->fetch(PDO::FETCH_ASSOC);
		if($valid):
			$this->id = $sess_array['id'];
			$this->session = $sess_array['session'];
			$this->run_id = $sess_array['run_id'];
			$this->user_id = $sess_array['user_id'];
			$this->created = $sess_array['created'];
			$this->ended = $sess_array['ended'];
			$this->position = $sess_array['position'];
			$this->run_name = $sess_array['run_name'];
		endif;
	}
	
	public function getUnit($cron = false)
	{
#		pr($this->id);
		$i = 0;
		$done = array();
			
		$output = false;
		while(! $output): // only when there is something to display, stop.
			$i++;
			if($i > 80) {
				global $user;
				if($user->isAdmin())
					 pr($unit);
				if($i > 90)
					die('Nesting too deep. Could there be an infinite loop or maybe no landing page?');
			}
			$unit = $this->getCurrentUnit(); // get first unit in line
			if($unit):								 // if there is one, spin that shit
				if($cron):
					$unit['cron'] = true;
				endif;
				@$done[ $unit['type'] ]++;
				
				
				$unit = makeUnit($this->dbh, $this->session, $unit);
				$output = $unit->exec();
				
				
			else:
				$this->runToNextUnit(); 		// if there is nothing in line yet, add the next one in run order
			endif;
		endwhile;
		
		if($cron)
			return $done;

		return $output;
	}

	public function getUnitIdAtPosition($position)
	{
		$data = $this->dbh->prepare("SELECT unit_id FROM `survey_run_units` WHERE 
			run_id = :run_id AND
			position = :position 
		LIMIT 1");
		$data->bindParam(":run_id",$this->run_id);
		$data->bindParam(":position",$position);
		$data->execute() or die(print_r($data->errorInfo(), true));
		$vars = $data->fetch(PDO::FETCH_ASSOC);
		if($vars)
			return $vars['unit_id'];
		return false;
	}
	public function forceTo($position)
	{
		$unit = $this->getCurrentUnit(); // get first unit in line
		if($unit):
			$unit = makeUnit($fdb,null,$unit);
			$unit->end(); 				// cancel it

			$this->runTo($position);
			alert(__('<strong>Success.</strong> User moved to position', $position));
		endif;
	}
	public function runTo($position,$unit_id = null)
	{
		if($unit_id === null) $unit_id = $this->getUnitIdAtPosition($position);
			
		if($unit_id):
			require_once INCLUDE_ROOT . 'Model/UnitSession.php';
			
			$unit_session = new UnitSession($this->dbh, $this->id, $unit_id);
			if(!$unit_session->id) $unit_session->create();
			$_SESSION['session'] = $this->session;
		
			if($unit_session->id):
				$run_to_q = "UPDATE `survey_run_sessions`
					SET position = :position
				WHERE 
				id = :id
				LIMIT 1;";
		
				$run_to_update = $this->dbh->prepare($run_to_q) or die(print_r($dbh->errorInfo(), true));
	
				$run_to_update->bindParam(":id",$this->id);
				$run_to_update->bindParam(":position",$position);
	
				$success = $run_to_update->execute() or die(print_r($run_to_update->errorInfo(), true));
				if($success):
					$this->position = (int)$position;
					return true;
				endif;
			endif;
		endif;
		return false;
	}


	public function getCurrentUnit()
	{
		$g_unit = $this->dbh->prepare(
		"SELECT 
			`survey_unit_sessions`.unit_id,
			`survey_unit_sessions`.id AS session_id,
			`survey_unit_sessions`.created,
			`survey_units`.type

		FROM `survey_unit_sessions`

 		LEFT JOIN `survey_units`
	 		ON `survey_unit_sessions`.unit_id = `survey_units`.id
	
		WHERE 
			`survey_unit_sessions`.run_session_id = :run_session_id AND
			`survey_unit_sessions`.unit_id = :unit_id AND
			`survey_unit_sessions`.ended IS NULL -- so we know when to runToNextUnit
		
		ORDER BY `survey_unit_sessions`.id DESC 
		LIMIT 1
		;"); // in the order they were added
		$g_unit->bindParam(':run_session_id',$this->id);
		$g_unit->bindValue(':unit_id',$this->getUnitIdAtPosition($this->position));
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$unit = $g_unit->fetch(PDO::FETCH_ASSOC);
		if($unit):
			// unit needs:
			# run_id
			# run_name
			# unit_id
			# session_id
			# run_session_id
			# type
			# session? 
			$unit['run_id'] = $this->run_id;
			$unit['run_name'] = $this->run_name;
			$unit['run_session_id'] = $this->id;
			return $unit;
		endif;
		return false;
	}
	public function runToNextUnit()
	{
		$g_unit = $this->dbh->prepare(
		"SELECT 
			unit_id,
			position
			
			 FROM `survey_run_units` 
			 
		WHERE 
			run_id = :run_id AND
			position > :position
			
		ORDER BY position ASC
		LIMIT 1
		;");
		$g_unit->bindParam(':run_id',$this->run_id);
		if($this->position !== NULL)
			$g_unit->bindParam(':position',$this->position);
		else
			$g_unit->bindValue(':position',-1000000);
		
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$next = $g_unit->fetch(PDO::FETCH_ASSOC);
		
		if(!$next)
		{
			die('Forgot a landing page');
		}
		if(!$this->runTo($next['position'],$next['unit_id']))
		{
			pr($next);
			die('Missing unit.');
		}
	}
	public function endLastExternal()
	{
		$end_q = "UPDATE `survey_unit_sessions`
			left join `survey_units`
		on `survey_unit_sessions`.unit_id = `survey_units`.id
			SET `survey_unit_sessions`.`ended` = NOW()
		WHERE 
		`survey_unit_sessions`.run_session_id = :id AND
		`survey_units`.type = 'External' AND 
		`survey_unit_sessions`.ended IS NULL;";
	
		$end_external = $this->dbh->prepare($end_q) or die(print_r($dbh->errorInfo(), true));
	
		$end_external->bindParam(":id",$this->id);
	
		$success = $end_external->execute() or die(print_r($end_external->errorInfo(), true));
		return $success;
	}
	

	public function end() // todo: not being used atm
	{
		$finish_run = $this->dbh->prepare("UPDATE `survey_run_sessions` 
			SET `ended` = NOW()
			WHERE 
			`id` = :id AND
			`ended` IS NULL
		LIMIT 1;");
		$finish_run->bindParam(":id", $this->id);
		$finish_run->execute() or die(print_r($finish_run->errorInfo(), true));

		if($finish_run->rowCount() === 1):
			$this->ended = true;
			return true;
		else:
			return false;
		endif;
	}
	
	public function __sleep()
	{
		return array('id', 'session', 'run_id');
	}
}