<?php
require_once "../inc/common.php";
require_once "../Classes/class-checkup.php";

$checkup = new CheckUp();
$can_manage = current_user_can(PERMISSION_MANAGE_HEALTH);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')
{
	if (!$can_manage)
	{
		http_response_code(403);
		exit('Access denied');
	}
	$action = $_POST['checkup_action'] ?? '';
	$id = $_POST['issue_id'] ?? '';
	if (!is_string($action) || !in_array($action, ['accept', 'revoke'], true) ||
		!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id))
	{
		http_response_code(400);
		exit('Invalid health checkup action');
	}

	$accepted = $checkup->accepted_issues;
	if ($action === 'accept')
	{
		// Only accept findings actually detected in the current network check.
		if (!isset($checkup->all_issues[$id]))
		{
			http_response_code(400);
			exit('Health warning is no longer present');
		}
		$reason = $_POST['reason'] ?? '';
		if (!is_string($reason) || mb_strlen($reason, 'UTF-8') > 500)
		{
			http_response_code(400);
			exit('Invalid acceptance reason');
		}
		$user = unreal_get_current_user();
		$issue = $checkup->all_issues[$id];
		$accepted[$id] = [
			'category' => $issue['category'],
			'summary' => $issue['summary'],
			'reason' => trim($reason),
			'by' => $user ? $user->username : '',
			'at' => gmdate('Y-m-d H:i:s').' UTC',
		];
	}
	else
	{
		if (!isset($accepted[$id]))
		{
			http_response_code(400);
			exit('Health warning is not accepted');
		}
		unset($accepted[$id]);
	}

	$config['health_check_accepted'] = $accepted;
	if (!write_config('health_check_accepted'))
	{
		http_response_code(500);
		exit('Unable to save health checkup settings');
	}
	header('Location: '.get_config('base_url').'tools/checkup.php', true, 303);
	exit;
}

require_once "../inc/header.php";
?>

<h4>Network Health Checkup</h4>
<style>
	.card {
		min-height: 80%;
		border-radius: 16px;
	}
	#accordion > .card {
		margin-bottom: 5px;
		width:100%;
		border-radius: 16x;
	}

</style>
<?php echo $checkup ?>
<div id="accordion" class="container-xxl">
	<div class="card">
		<div class="card-header" id="headingOne" aria-describedby="test1">
			<h5 class="mb-0">
				<button class="btn" data-toggle="collapse" data-target="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
					User Mode Conflicts <span class="badge badge-danger"><?php echo $checkup->num_of_problems['usermodes'] ?></span>
				</button>
			</h5>
		</div>
		<div id="collapseOne" class="collapse" aria-labelledby="headingOne" data-parent="#accordion">
			<div class="card-body">
				<?php $checkup->toManageTable('usermodes', $can_manage); ?>
			</div>
		</div>
	</div>
	<div class="card">
		<div class="card-header" id="headingTwo">
			<h5 class="mb-0">
				<button class="btn collapsed" data-toggle="collapse" data-target="#collapseTwo" aria-expanded="false" aria-controls="collapseTwo">
					Channel Mode Conflicts <span class="badge badge-danger"><?php echo $checkup->num_of_problems['chanmodes'] ?></span>
				</button>
			</h5>
		</div>
		<div id="collapseTwo" class="collapse" aria-labelledby="headingTwo" data-parent="#accordion">
			<div class="card-body">
				<?php $checkup->toManageTable('chanmodes', $can_manage); ?>
			</div>
		</div>
	</div>
	<div class="card">
		<div class="card-header" id="headingThree">
			<h5 class="mb-0">
				<button class="btn collapsed" data-toggle="collapse" data-target="#collapseThree" aria-expanded="false" aria-controls="collapseThree">
					Module Conflicts <span class="badge badge-danger"><?php echo $checkup->num_of_problems['modules'] ?></span>
				</button>
			</h5>
		</div>
		<div id="collapseThree" class="collapse" aria-labelledby="headingThree" data-parent="#accordion">
			<div class="card-body">
				<?php $checkup->toManageTable('modules', $can_manage); ?>
			</div>
		</div>
	</div>
	<div class="card">
		<div class="card-header" id="headingFour">
			<h5 class="mb-0">
				<button class="btn collapsed" data-toggle="collapse" data-target="#collapseFour" aria-expanded="false" aria-controls="collapseFour">
					Server Conflicts <span class="badge badge-danger"><?php echo $checkup->num_of_problems['servers'] ?></span>
				</button>
			</h5>
		</div>
		<div id="collapseFour" class="collapse" aria-labelledby="headingFour" data-parent="#accordion">
			<div class="card-body">
				<?php $checkup->toManageTable('servers', $can_manage); ?>
			</div>
		</div>
	</div>
</div>
<?php if ($checkup->accepted_issues !== []) { ?>
<h5 class="mt-4">Accepted as intended (<?php echo count($checkup->accepted_issues); ?>)</h5>
<p class="text-muted">These are excluded from the health score. You can reopen them if the network configuration changes.</p>
<table class="table table-striped">
	<thead><tr><th>Warning</th><th>Accepted by</th><th>Reason</th><th>Accepted at</th><?php if ($can_manage) { ?><th>Action</th><?php } ?></tr></thead>
	<tbody>
	<?php foreach ($checkup->accepted_issues as $id => $entry) {
		if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id) || !is_array($entry)) continue;
		$safe = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	?>
	<tr>
		<td><?php echo $safe($entry['summary'] ?? ''); ?><?php if (!isset($checkup->all_issues[$id])) { ?><span class="text-muted"> (not currently detected)</span><?php } ?></td>
		<td><?php echo $safe($entry['by'] ?? ''); ?></td>
		<td><?php echo $safe($entry['reason'] ?? ''); ?></td>
		<td><?php echo $safe($entry['at'] ?? ''); ?></td>
		<?php if ($can_manage) { ?>
		<td><form method="post">
			<input type="hidden" name="_csrf" value="<?php echo $safe(panel_csrf_token()); ?>">
			<input type="hidden" name="checkup_action" value="revoke">
			<input type="hidden" name="issue_id" value="<?php echo $safe($id); ?>">
			<button type="submit" class="btn btn-sm btn-outline-secondary">Reopen</button>
		</form></td>
		<?php } ?>
	</tr>
	<?php } ?>
	</tbody>
</table>
<?php } ?>
<?php

require_once "../inc/footer.php";