<?php
require_once UPATH."/inc/connection.php";

/**
 * Does a complete checkup of the network.
 */
class CheckUp
{
	const SCORE_PERFECT = 0;
	const SCORE_NOT_BAD = 1;
	const SCORE_COULD_BE_BETTER = 10;
	const SCORE_NEEDS_ATTENTION = 25;
	const SCORE_VERY_BAD = 50;

	public $num_of_problems = [
		"chanmodes" => 0,
		"usermodes" => 0,
		"modules" => 0,
		"servers" => 0,
		"other" => 0,
	];
	public $problems = [
		"chanmodes" => [],
		"usermodes" => [],
		"modules" => [],
		"servers" => [],
		"other" => [],
	];

	// Keep the existing problem arrays as unresolved issues for compatibility.
	public array $issue_details = [];
	public array $all_issues = [];
	public array $accepted_issues = [];
	public int $accepted_current_count = 0;
	public $serverlist = [];

	/**
	 * Construct0r
	 */
	function __construct()
	{
		global $rpc;

		$this->serverlist = $rpc->server()->getAll();
		$this->chanmode_check();
		$this->usermode_check();
		$this->module_check();
		$this->server_check();
		$this->applyAcceptances();
	}

	/** A stable ID for this exact warning, including its direction and server names. */
	public static function issueId(string $category, string $message): string
	{
		// HTML presentation changes should not invalidate previously accepted warnings.
		$plain = html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
		return hash('sha256', $category."\0".$plain);
	}

	/** Partition warnings without changing how the underlying checks are performed. */
	public function applyAcceptances(): void
	{
		$saved = get_config('health_check_accepted');
		$this->accepted_issues = is_array($saved) ? $saved : [];
		$this->all_issues = [];
		$this->issue_details = [];
		$this->accepted_current_count = 0;

		foreach ($this->problems as $category => $messages)
		{
			$unresolved = [];
			$this->issue_details[$category] = [];
			foreach ($messages as $message)
			{
				$id = self::issueId($category, $message);
				$issue = [
					'id' => $id,
					'category' => $category,
					'message' => $message,
					'summary' => trim(html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
				];
				$this->all_issues[$id] = $issue;
				if (isset($this->accepted_issues[$id]) && is_array($this->accepted_issues[$id]))
				{
					++$this->accepted_current_count;
					continue;
				}
				$unresolved[] = $message;
				$this->issue_details[$category][] = $issue;
			}
			$this->problems[$category] = $unresolved;
			$this->num_of_problems[$category] = count($unresolved);
		}
	}

	public function total()
	{
		$count = 0;
		foreach ($this->num_of_problems as $problem)
			$count += $problem;
		return $count;
	}

	public function equivalate()
	{
		$total = $this->total();
		if ($total == self::SCORE_PERFECT)
				return "Perfect";
		elseif ($total < self::SCORE_COULD_BE_BETTER)
				return "Not Bad";
		elseif ($total < self::SCORE_NEEDS_ATTENTION)
				return "Could Be Better";
		elseif ($total < self::SCORE_VERY_BAD)
				return "Needs Attention";
		else return "Very Poor";
	}
	public function badgestyle()
	{
		$total = $this->total();
		if ($total == self::SCORE_PERFECT)
				return "success";
		elseif ($total < self::SCORE_COULD_BE_BETTER)
				return "info";
		elseif ($total < self::SCORE_NEEDS_ATTENTION)
				return "warning";
		elseif ($total < self::SCORE_VERY_BAD)
				return "danger";
		else return "dark";

	}

	/**
	 * Checks channel modes of servers against other servers
	 * @return void
	 */
	function chanmode_check() : void
	{
		foreach($this->serverlist as $s) // cycle through each server
		{
			/* hmm if it's not unreal, skip it too */
			if (!isset($s->server->features->software) || !strstr($s->server->features->software,"UnrealIRCd"))
				continue;
			/* make a single string from the array of groups */
			$ourchmodes = "";
			foreach ($s->server->features->chanmodes as $set)
				for ($i=0; isset($set[$i]); $i++)
					strcat($ourchmodes,$set[$i]);

			/* take a look at every other server... yep, we do this for every server */
			foreach ($this->serverlist as $serv)
			{
				/* except for ourselves lol */
				if ($serv->id == $s->id)
					continue;
				
				/* hmm if it's not unreal, skip it too */
				if (!isset($serv->server->features->software) || !strstr($serv->server->features->software,"UnrealIRCd"))
					continue;
				
				/* make a single string from the array of groups but for them this time */
				$theirchmodes = "";
				foreach ($serv->server->features->chanmodes as $set)
					for ($i=0; isset($set[$i]); $i++)
						strcat($theirchmodes,$set[$i]);

				/* check ours against theirs */
				for ($i=0; isset($ourchmodes[$i]) && $m = $ourchmodes[$i]; $i++)
				{
					/* if we have a mode that they don't have */
					if (!strstr($theirchmodes, $m))
					{
						++$this->num_of_problems['chanmodes'];
						$this->problems['chanmodes'][] = "Channel mode <code>$m</code> is present on <code>$s->name</code> but missing on <code>$serv->name</code>";
					}
				}
			}
		}
	}

	/**
	 * Checks user modes of servers against other servers
	 * @return void
	 */
	function usermode_check() : void
	{
		foreach($this->serverlist as $s)
		{
			/* hmm if it's not unreal, skip it too */
			if (!isset($s->server->features->software) || !strstr($s->server->features->software,"UnrealIRCd"))
				continue;
			/* make a single string from the array of groups */
			$ourumodes = $s->server->features->usermodes;

			/* take a look at every other server... yep, we do this for every server */
			foreach ($this->serverlist as $serv)
			{
				/* except for ourselves lol */
				if ($serv->id == $s->id)
					continue;
				
				/* hmm if it's not unreal, skip it too */
				if (!isset($serv->server->features->software) || !strstr($serv->server->features->software,"UnrealIRCd"))
					continue;
				
				$theirumodes = $serv->server->features->usermodes;

				/* check ours against theirs */
				for ($i=0; isset($ourumodes[$i]) && $m = $ourumodes[$i]; $i++)
				{
					/* if we have a mode that they don't have */
					if (!strstr($theirumodes, $m))
					{
						++$this->num_of_problems['usermodes'];
						$this->problems['usermodes'][] = "User mode <code>$m</code> is present on <code>$s->name</code> but missing on <code>$serv->name</code>";
					}
				}
			}
		}
	}

	/**
	 * Checks modules of servers against other servers
	 * @return void
	 */
	function module_check() : void
	{
		global $rpc;
		foreach ($this->serverlist as $s)
		{
			/* hmm if it's not unreal, skip it too */
			if (!isset($s->server->features->software) || !strstr($s->server->features->software,"UnrealIRCd"))
				continue;
			$ourmods = sort_mods(json_decode(json_encode(@$rpc->server()->module_list($s->id)->list), true));
			
			// doesn't support that yet
			if (empty($ourmods))
				continue;

			foreach ($this->serverlist as $serv)
			{
				/* except for ourselves lol */
				if ($serv->id == $s->id)
					continue;
				
				/* hmm if it's not unreal, skip it too */
				if (!isset($serv->server->features->software) || !strstr($serv->server->features->software,"UnrealIRCd"))
					continue;

				$theirmods = sort_mods(json_decode(json_encode(@$rpc->server()->module_list($serv->id)->list), true));

				// doesn't support that yet
				if (empty($theirmods))
					continue;
				// we only check if theirs doesn't match ours
				foreach ($theirmods as $name => $version)
				{
					if (!isset($ourmods[$name])) // we don't have that module
					{
						++$this->num_of_problems['modules'];
						$this->problems['modules'][] = "Module <code>$name</code> exists on <code>$serv->name</code> but not <code>$s->name</code>";
					}
					else if ((int)$version > (int)$ourmods[$name])
					{
						++$this->num_of_problems['modules'];
						$this->problems['modules'][] = "Module <code>$name</code> on <code>$serv->name</code> is newer than on <code>$s->name</code>";
					}
				}
			}
		}
	}

	function server_check() : void
	{
		global $rpc;
		foreach ($this->serverlist as $s)
		{
			/* hmm if it's not unreal, skip it too */
			if (!isset($s->server->features->software) || !strstr($s->server->features->software,"UnrealIRCd"))
					continue;
			// protocol checking
			$ours = (int)$s->server->features->protocol;
			foreach ($this->serverlist as $serv)
			{
				if (!isset($serv->server->features->software) || !strstr($serv->server->features->software,"UnrealIRCd"))
					continue;

				$theirs = (int)$serv->server->features->protocol;

				if ($ours < $theirs)
				{
					++$this->num_of_problems['servers'];
					$this->problems['servers'][] = "Protocol mismatch: <code>$serv->name</code> using protocol <code>$theirs</code> but <code>$s->name</code> using protocol <code>$ours</code>. <a href=\"https://www.unrealircd.org/docs/Upgrading\">Click for upgrade documentation.</a>";
				}
			}

			// EOL checking
			$tok = explode('-', $s->server->features->software);
			if ((int)$tok[1] < 6)
			{
				++$this->num_of_problems['servers'];
				$this->problems['servers'][] = "EOL: <code>$s->name</code> (".$s->server->features->software.") is running old unsupported software which is no longer receiving security updates. <a href=\"https://www.unrealircd.org/docs/Upgrading\">Click here for upgrade documentation.</a>";
			}
		}
	}
	/* Print a widget easy! */
	function __toString()
	{
		return '
		<a style="width:fit-content" id="health_banner" class="card alert text-dark alert-'.(!$this->total() ? "success" : "danger").'" role="alert" href="'.get_config("base_url").'tools/checkup.php">
			<i class="fa-solid fa-heart-pulse fa-2x" style="position:absolute;top:10px;left:10px"></i>
			<h4 class="alert-heading mt-0 mb-0" style="padding-left:40px;">Network Health </h4><span style="position:relative;top:5px;" class="ml-3 badge badge-'.$this->badgestyle().'">'.$this->equivalate().'</span>
			<p class="ml-4 mt-2 mb-0">Found <b>'.$this->total().'</b> unresolved problems.'.
				($this->accepted_current_count ? ' <small>('.$this->accepted_current_count.' accepted as intended)</small>' : '').'</p>
	</a>';
	}

	public function toTable(Array $array)
	{
		echo "<table class=\"table table-striped\">\n";
		foreach ($array as $key => $value)
		{
			echo "\t<tr><td>$value</td></tr>\n";
		}
		echo "</table>\n";
	}

	/** Render checkup-only controls; other callers can keep using toTable(). */
	public function toManageTable(string $category, bool $canManage): void
	{
		$items = $this->issue_details[$category] ?? [];
		if ($items === [])
		{
			echo '<p class="text-muted">No unresolved conflicts.</p>';
			return;
		}
		echo '<table class="table table-striped"><tbody>';
		foreach ($items as $issue)
		{
			echo '<tr><td>'.$issue['message'].'</td>';
			if ($canManage)
			{
				$id = htmlspecialchars($issue['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
				$csrf = htmlspecialchars(panel_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
				echo '<td><form method="post" class="form-inline">'
					.'<input type="hidden" name="_csrf" value="'.$csrf.'">'
					.'<input type="hidden" name="checkup_action" value="accept">'
					.'<input type="hidden" name="issue_id" value="'.$id.'">'
					.'<input type="text" name="reason" maxlength="500" class="form-control form-control-sm mr-2" '
					.'aria-label="Reason for accepting this warning" placeholder="Reason (optional)">'
					.'<button type="submit" class="btn btn-sm btn-outline-secondary">Accept as intended</button>'
					.'</form></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}

function sort_mods($mods)
{
		$list = [];
		if (!$mods)
				return $list;
		foreach($mods as $mod)
				$list[$mod["name"]] = $mod["version"];
		
		return $list;
}

function checkup_widget()
{
		$ch = new CheckUp();
		echo $ch;
}